<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/PublishReadiness.php';
require_once __DIR__ . '/../src/ReleaseEventBuilder.php';
require_once __DIR__ . '/../src/IdentifierProcessor.php';
require_once __DIR__ . '/../src/TrackingWorkspace.php';

use GTMAuditor\IdentifierProcessor;
use GTMAuditor\PublishReadiness;
use GTMAuditor\ReleaseEventBuilder;
use GTMAuditor\TrackingWorkspace;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
assert_client_access($clientId);
$action = (string)($input['action'] ?? 'draft');
if (!in_array($action, ['draft', 'approve'], true)) {
    api_respond(['error' => 'invalid_action'], 422);
}

$mappingSelections = is_array($input['mapping_selections'] ?? null) ? $input['mapping_selections'] : [];
$baseEventSelections = is_array($input['base_event_selections'] ?? null) ? $input['base_event_selections'] : [];
$mappingIds = [];
foreach ($mappingSelections as $selection) {
    $mappingId = (int)($selection['mapping_id'] ?? 0);
    if ($mappingId > 0) $mappingIds[] = $mappingId;
}
$mappingIds = array_values(array_unique($mappingIds));
if ((!$mappingIds && !$baseEventSelections) || count($mappingIds) > 100 || count($baseEventSelections) > 20) {
    api_respond(['error' => 'invalid_release_items', 'message' => 'Select at least one event and no more than 100 mapped tags.'], 422);
}

$rows = [];
if ($mappingIds) {
    $placeholders = implode(',', array_fill(0, count($mappingIds), '?'));
    $stmt = db()->prepare(
        "SELECT id, site_scan_id, page_url, trigger_scope, element_name, element_role, tracking_intent,
                measurement_goal, css_selector, selector_strategy, data_key, preview_text,
                event_template, trigger_template, tag_templates, updated_at
         FROM measurement_elements
         WHERE client_id = ? AND status = 'active' AND id IN ($placeholders)
         ORDER BY id ASC"
    );
    $stmt->execute(array_merge([$clientId], $mappingIds));
    $rows = $stmt->fetchAll();
}
if (count($rows) !== count($mappingIds)) {
    api_respond(['error' => 'mapping_not_found', 'message' => 'One or more selected mappings are no longer available.'], 409);
}

$availableMappings = [];
foreach ($rows as $row) {
    $availableMappings[] = [
        'id' => (int)$row['id'],
        'site_scan_id' => (int)$row['site_scan_id'],
        'page_url' => $row['page_url'],
        'trigger_scope' => $row['trigger_scope'],
        'element_name' => $row['element_name'],
        'element_role' => $row['element_role'],
        'tracking_intent' => $row['tracking_intent'],
        'measurement_goal' => $row['measurement_goal'],
        'css_selector' => $row['css_selector'],
        'selector_strategy' => $row['selector_strategy'],
        'data_key' => $row['data_key'],
        'preview_text' => $row['preview_text'],
        'event_template' => json_decode($row['event_template'], true) ?: [],
        'trigger_template' => json_decode($row['trigger_template'], true) ?: [],
        'tag_templates' => json_decode($row['tag_templates'], true) ?: [],
        'updated_at' => $row['updated_at'],
    ];
}

$stmt = db()->prepare('SELECT latest_scan_id, measurement_plan FROM onboarding_profiles WHERE client_id = ? LIMIT 1');
$stmt->execute([$clientId]);
$profile = $stmt->fetch();
if (!$profile || !(int)$profile['latest_scan_id']) {
    api_respond(['error' => 'measurement_plan_required', 'message' => 'Create a measurement plan before creating a release.'], 409);
}

$eventBuilder = new ReleaseEventBuilder();
try {
    $configuredWorkspace = (new TrackingWorkspace($eventBuilder))->load(db(), $clientId);
    $configuredMappings = [];
    foreach ($configuredWorkspace['mappings'] as $mapping) $configuredMappings[(int)$mapping['id']] = $mapping;
    $availableMappings = [];
    foreach ($mappingIds as $mappingId) {
        if (isset($configuredMappings[$mappingId])) $availableMappings[] = $configuredMappings[$mappingId];
    }
    $mappings = $eventBuilder->selectMappings($availableMappings, $mappingSelections);
    $availableBaseEvents = $configuredWorkspace['base_events'];
    $baseEvents = $eventBuilder->selectBaseEvents($availableBaseEvents, $baseEventSelections);
} catch (InvalidArgumentException $e) {
    $message = 'One of the selected events is no longer available.';
    if ($e->getMessage() === 'invalid_event_name') {
        $message = 'Event names must start with a letter and contain only lowercase letters, numbers, and underscores.';
    } elseif ($e->getMessage() === 'invalid_event_parameter') {
        $message = 'One of the selected parameters is no longer available for this event.';
    }
    api_respond(['error' => $e->getMessage(), 'message' => $message], 422);
}
if (!$mappings && !$baseEvents) {
    api_respond(['error' => 'no_events_selected', 'message' => 'Select at least one event for this release.'], 422);
}

$mappingIds = array_values(array_map(fn(array $mapping): int => (int)$mapping['id'], $mappings));
$scanIds = array_values(array_unique(array_column($mappings, 'site_scan_id')));
if ($baseEvents) $scanIds[] = (int)$profile['latest_scan_id'];
$scanIds = array_values(array_unique($scanIds));
if (count($scanIds) !== 1) {
    api_respond(['error' => 'mixed_scan_versions', 'message' => 'Create one release from mappings generated by the same site scan.'], 409);
}

$readinessService = new PublishReadiness();
$readinessItems = array_merge($baseEvents, $mappings);
$readiness = $readinessService->evaluate($readinessItems, $readinessService->loadConnectionState(db(), $clientId));
$requestedName = trim((string)($input['name'] ?? ''));
$requestedName = mb_substr($requestedName, 0, 150);

$privacyStmt = db()->prepare(
    'SELECT hashed_pii_enabled, allowed_hashed_fields
     FROM tracking_privacy_settings
     WHERE client_id = ?
     LIMIT 1'
);
$privacyStmt->execute([$clientId]);
$privacyRow = $privacyStmt->fetch();
$privacySnapshot = [
    'raw_pii_capture' => false,
    'hashed_pii_enabled' => $privacyRow ? (bool)$privacyRow['hashed_pii_enabled'] : false,
    'allowed_hashed_fields' => $privacyRow ? (json_decode($privacyRow['allowed_hashed_fields'], true) ?: []) : [],
    'consent_required' => true,
    'identifier_processing' => (new IdentifierProcessor())->rules(),
];

$pdo = db();
try {
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT id FROM clients WHERE id = ? FOR UPDATE');
    $lock->execute([$clientId]);
    $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM tag_releases WHERE client_id = ?');
    $versionStmt->execute([$clientId]);
    $version = (int)$versionStmt->fetchColumn();
    $name = $requestedName !== '' ? $requestedName : 'Release v' . $version;
    $status = $action === 'approve' ? 'approved' : 'draft';

    $stmt = $pdo->prepare(
        "INSERT INTO tag_releases
         (client_id, site_scan_id, version_number, name, status, mapping_ids,
          release_snapshot, readiness_snapshot, approved_by_user_id, approved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $clientId,
        $scanIds[0],
        $version,
        $name,
        $status,
        json_encode($mappingIds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        json_encode(['created_at' => date(DATE_ATOM), 'base_events' => $baseEvents, 'mappings' => $mappings, 'privacy' => $privacySnapshot], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        json_encode($readiness, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $action === 'approve' ? (int)$user['id'] : null,
        $action === 'approve' ? date('Y-m-d H:i:s') : null,
    ]);
    $releaseId = (int)$pdo->lastInsertId();
    $pdo->commit();
    api_respond(['ok' => true, 'data' => [
        'release' => ['id' => $releaseId, 'version_number' => $version, 'name' => $name, 'status' => $status],
        'readiness' => $readiness,
    ]], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Create tag release failed: ' . $e->getMessage());
    api_respond(['error' => 'release_save_failed', 'message' => 'The release could not be saved. Please try again.'], 500);
}
