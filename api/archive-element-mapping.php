<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
$mappingId = (int)($input['mapping_id'] ?? 0);
assert_client_access($clientId);
if ($mappingId <= 0) {
    api_respond(['error' => 'invalid_mapping'], 422);
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "SELECT id, element_name
         FROM measurement_elements
         WHERE id = ? AND client_id = ? AND status = 'active'
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([$mappingId, $clientId]);
    $mapping = $stmt->fetch();
    if (!$mapping) {
        $pdo->rollBack();
        api_respond(['error' => 'mapping_not_found', 'message' => 'This mapped tag is no longer active.'], 404);
    }

    $archive = $pdo->prepare(
        "UPDATE measurement_elements
         SET status = 'archived', updated_at = NOW()
         WHERE id = ? AND client_id = ? AND status = 'active'"
    );
    $archive->execute([$mappingId, $clientId]);

    $disableSettings = $pdo->prepare(
        'UPDATE tracking_event_settings
         SET enabled = 0, updated_by_user_id = ?, updated_at = NOW()
         WHERE client_id = ? AND event_key LIKE ?'
    );
    $disableSettings->execute([(int)$user['id'], $clientId, 'mapping:' . $mappingId . ':%']);
    $pdo->commit();

    api_respond(['ok' => true, 'data' => [
        'mapping_id' => $mappingId,
        'element_name' => $mapping['element_name'],
        'archived' => true,
    ]]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Element mapping archive failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'mapping_archive_failed', 'message' => 'The mapped tag could not be removed.'], 500);
}
