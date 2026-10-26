<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/PublishReadiness.php';

use GTMAuditor\PublishReadiness;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
$releaseId = (int)($input['release_id'] ?? 0);
assert_client_access($clientId);

$stmt = db()->prepare(
    "SELECT id, status, mapping_ids, release_snapshot
     FROM tag_releases
     WHERE id = ? AND client_id = ?
     LIMIT 1"
);
$stmt->execute([$releaseId, $clientId]);
$release = $stmt->fetch();
if (!$release) api_respond(['error' => 'release_not_found'], 404);
if ($release['status'] !== 'approved') {
    api_respond(['error' => 'release_not_approved', 'message' => 'Approve this release before publishing it.'], 409);
}

$snapshot = json_decode($release['release_snapshot'], true) ?: [];
$mappings = array_merge(
    is_array($snapshot['base_events'] ?? null) ? $snapshot['base_events'] : [],
    is_array($snapshot['mappings'] ?? null) ? $snapshot['mappings'] : []
);
if (!$mappings) api_respond(['error' => 'release_empty', 'message' => 'This release has no mappings.'], 409);

$mappingIds = json_decode($release['mapping_ids'], true) ?: [];
$mappingIds = array_values(array_unique(array_filter(array_map('intval', $mappingIds))));
if ($mappingIds) {
    $placeholders = implode(',', array_fill(0, count($mappingIds), '?'));
    $activeStmt = db()->prepare(
        "SELECT COUNT(*)
         FROM measurement_elements
         WHERE client_id = ? AND status = 'active' AND id IN ($placeholders)"
    );
    $activeStmt->execute(array_merge([$clientId], $mappingIds));
    if ((int)$activeStmt->fetchColumn() !== count($mappingIds)) {
        api_respond([
            'error' => 'release_contains_deleted_tags',
            'message' => 'This release contains a tag that was deleted from the active workspace. Create and approve a new release before publishing.',
        ], 409);
    }
}

$readinessService = new PublishReadiness();
$readiness = $readinessService->evaluate($mappings, $readinessService->loadConnectionState(db(), $clientId));
if (!$readiness['ready_to_publish']) {
    api_respond([
        'error' => 'publication_blocked',
        'message' => count($readiness['blockers']) . ' readiness item(s) must be resolved before these tags can go live.',
        'data' => ['readiness' => $readiness],
    ], 409);
}

// This path remains unreachable until a concrete adapter sets deployment_adapter_ready.
api_respond(['error' => 'deployment_adapter_missing', 'message' => 'No external deployment adapter is available. Nothing was changed in your tag manager.'], 501);
