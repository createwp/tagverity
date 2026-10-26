<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/ReleaseEventBuilder.php';
require_once __DIR__ . '/../src/TrackingWorkspace.php';

use GTMAuditor\TrackingWorkspace;

$stmt = db()->query('SELECT id, account_id FROM clients ORDER BY id ASC LIMIT 1');
$client = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$clientId = (int)($client['id'] ?? 0);
if ($clientId === 0) {
    echo "TrackingWorkspace smoke test skipped: no clients.\n";
    exit(0);
}

$workspace = (new TrackingWorkspace())->load(db(), $clientId);
foreach (['base_events', 'mappings', 'events', 'privacy'] as $key) {
    if (!array_key_exists($key, $workspace)) {
        fwrite(STDERR, 'TrackingWorkspace missing ' . $key . ".\n");
        exit(1);
    }
}
$planStmt = db()->prepare('SELECT measurement_plan FROM onboarding_profiles WHERE client_id = ? LIMIT 1');
$planStmt->execute([$clientId]);
$measurementPlan = json_decode((string)$planStmt->fetchColumn(), true) ?: [];
$recommendedNames = [];
foreach ($measurementPlan['events'] ?? [] as $plannedEvent) {
    $name = (string)($plannedEvent['name'] ?? '');
    if ($name !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) $recommendedNames[$name] = true;
}
if (count($workspace['base_events']) !== count($recommendedNames)) {
    fwrite(STDERR, "Workspace did not expose every measurement-plan recommendation.\n");
    exit(1);
}
if (($workspace['privacy']['raw_pii_capture'] ?? null) !== false) {
    fwrite(STDERR, "Raw PII must default to disabled.\n");
    exit(1);
}
foreach ($workspace['events'] as $event) {
    if (empty($event['event_key']) || !isset($event['available_parameters'], $event['selected_parameters'])) {
        fwrite(STDERR, "Workspace event configuration is incomplete.\n");
        exit(1);
    }
    if (!$event['configured'] && !empty($event['available_trigger_intents'])) {
        $expectedName = strtolower($event['element_name']);
        $expectedName = preg_replace('/[^a-z0-9]+/', '_', $expectedName) ?: '';
        $expectedName = trim($expectedName, '_');
        if ($expectedName !== '' && !preg_match('/^[a-z]/', $expectedName)) $expectedName = 'event_' . $expectedName;
        $expectedName = $expectedName === '' ? $event['system_event_name'] : substr($expectedName, 0, 40);
        if ($event['configured_name'] !== $expectedName) {
            fwrite(STDERR, "Mapper-selected name was not preserved as the custom event name.\n");
            exit(1);
        }
    }
    foreach ($event['tag_templates'] ?? [] as $tagTemplate) {
        if (($tagTemplate['platform'] ?? '') === 'generic' && ($tagTemplate['template'] ?? '') === 'Data layer event') {
            fwrite(STDERR, "A missing destination was presented as a selected data-layer tag.\n");
            exit(1);
        }
    }
}

if ($workspace['events']) {
    $userStmt = db()->prepare('SELECT id FROM users WHERE account_id = ? ORDER BY id ASC LIMIT 1');
    $userStmt->execute([(int)$client['account_id']]);
    $userId = (int)$userStmt->fetchColumn();
    if ($userId > 0) {
        $sample = $workspace['events'][0];
        foreach ($workspace['events'] as $candidate) {
            if (!empty($candidate['available_trigger_intents'])) {
                $sample = $candidate;
                break;
            }
        }
        $triggerOverride = null;
        if (!empty($sample['available_trigger_intents'])) {
            $triggerOverride = $sample['trigger_intent'] === 'click' ? 'visibility' : 'click';
        }
        db()->beginTransaction();
        try {
            $save = db()->prepare(
                'INSERT INTO tracking_event_settings
                 (client_id, event_key, source_event_name, approved_event_name, enabled, selected_parameters, trigger_intent, updated_by_user_id)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE approved_event_name = VALUES(approved_event_name), enabled = 0,
                     selected_parameters = VALUES(selected_parameters), trigger_intent = VALUES(trigger_intent),
                     updated_by_user_id = VALUES(updated_by_user_id)'
            );
            $savedSourceName = $triggerOverride === 'click' ? 'element_click' : ($triggerOverride === 'visibility' ? 'element_view' : $sample['source_name']);
            $save->execute([$clientId, $sample['event_key'], $savedSourceName, 'qa_event', '[]', $triggerOverride, $userId]);
            $privacySave = db()->prepare(
                'INSERT INTO tracking_privacy_settings
                 (client_id, hashed_pii_enabled, allowed_hashed_fields, updated_by_user_id)
                 VALUES (?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE hashed_pii_enabled = 1,
                     allowed_hashed_fields = VALUES(allowed_hashed_fields), updated_by_user_id = VALUES(updated_by_user_id)'
            );
            $privacySave->execute([$clientId, '["email"]', $userId]);
            $configured = (new TrackingWorkspace())->load(db(), $clientId);
            $configuredSample = (new TrackingWorkspace())->findEvent($configured, $sample['event_key']);
            if (!$configuredSample || $configuredSample['configured_name'] !== 'qa_event' || $configuredSample['enabled'] !== false || $configuredSample['selected_parameters'] !== []) {
                throw new RuntimeException('Saved event configuration was not merged into the workspace.');
            }
            if ($triggerOverride && ($configuredSample['trigger_intent'] !== $triggerOverride || $configuredSample['system_event_name'] !== ($triggerOverride === 'click' ? 'element_click' : 'element_view'))) {
                throw new RuntimeException('Trigger override did not preserve the custom event while changing its system template.');
            }
            if ($configured['privacy']['raw_pii_capture'] !== false || $configured['privacy']['hashed_pii_enabled'] !== true || $configured['privacy']['allowed_hashed_fields'] !== ['email']) {
                throw new RuntimeException('Saved privacy configuration was not merged safely.');
            }
            db()->rollBack();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
    }
}

if ($workspace['mappings']) {
    $mappingId = (int)$workspace['mappings'][0]['id'];
    db()->beginTransaction();
    try {
        $archive = db()->prepare("UPDATE measurement_elements SET status = 'archived' WHERE id = ? AND client_id = ? AND status = 'active'");
        $archive->execute([$mappingId, $clientId]);
        $afterArchive = (new TrackingWorkspace())->load(db(), $clientId);
        foreach ($afterArchive['mappings'] as $mapping) {
            if ((int)$mapping['id'] === $mappingId) throw new RuntimeException('Archived mapping remained in the active workspace.');
        }
        db()->rollBack();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

echo 'TrackingWorkspace smoke test passed for client #' . $clientId . ' with ' . count($workspace['events']) . " events.\n";
