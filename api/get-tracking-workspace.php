<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/ReleaseEventBuilder.php';
require_once __DIR__ . '/../src/TrackingWorkspace.php';

use GTMAuditor\TrackingWorkspace;

require_auth();
$clientId = (int)($_GET['client_id'] ?? 0);
$client = assert_client_access($clientId);

try {
    $data = (new TrackingWorkspace())->load(db(), $clientId);
    $data['client'] = ['id' => (int)$client['id'], 'name' => $client['name']];
    api_respond(['ok' => true, 'data' => $data]);
} catch (Throwable $e) {
    error_log('Tracking workspace load failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'workspace_load_failed', 'message' => 'The tracking workspace could not be loaded.'], 500);
}
