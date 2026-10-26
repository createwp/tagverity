<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/DestinationConnectionManager.php';

use GTMAuditor\DestinationConnectionManager;

$user = require_auth();
$clientId = (int)($_GET['client_id'] ?? 0);
assert_client_access($clientId);

try {
    $manager = new DestinationConnectionManager();
    $stmt = db()->prepare('SELECT id, google_email, connected_at FROM google_connections WHERE account_id = ? ORDER BY connected_at DESC');
    $stmt->execute([(int)$user['account_id']]);
    $googleAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = db()->prepare(
        'SELECT gp.display_name, gs.stream_id, gs.measurement_id, gs.stream_url
         FROM ga4_streams gs
         JOIN ga4_properties gp ON gp.id = gs.ga4_property_id
         WHERE gp.client_id = ?
         ORDER BY gp.display_name, gs.stream_url'
    );
    $stmt->execute([$clientId]);
    $ga4Streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    api_respond(['ok' => true, 'data' => [
        'connections' => $manager->load(db(), $clientId),
        'google_connection' => ['connected' => count($googleAccounts) > 0, 'accounts' => $googleAccounts, 'ga4_streams' => $ga4Streams],
    ]]);
} catch (Throwable $e) {
    error_log('Destination connection load failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'connection_load_failed', 'message' => 'Destination settings could not be loaded.'], 500);
}
