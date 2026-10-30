<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../src/SourceStatus.php';
require_once __DIR__ . '/../src/GoogleApiClient.php';
require_once __DIR__ . '/../src/GoogleResourceDiscovery.php';

use GTMAuditor\GoogleApiClient;
use GTMAuditor\GoogleResourceDiscovery;
use GTMAuditor\SourceStatus;

$user = require_auth();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    api_respond(['error' => 'method_not_allowed'], 405);
}

$clientId = filter_var($_GET['client_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$connectionId = filter_var($_GET['connection_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($clientId === false || $connectionId === false) {
    api_respond(['error' => 'invalid_request'], 400);
}

assert_client_access($clientId);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM google_connections WHERE id = ? AND account_id = ?');
$stmt->execute([$connectionId, $user['account_id']]);
$connection = $stmt->fetch();
if (!$connection) {
    api_respond(['error' => 'connection_not_found'], 404);
}

rate_limit('google_discovery', (string)$user['id'], 20, 300);

try {
    $source = new GoogleApiClient($connection);
} catch (Throwable $e) {
    error_log('[GTMAuditor][Linking] Google client could not start for connection #' . $connectionId . ': ' . $e->getMessage());
    $status = SourceStatus::fromThrowable($e);
    api_respond([
        'ga4_accounts' => [],
        'gtm_accounts' => [],
        'errors'       => ['ga4' => $status, 'gtm' => $status],
    ]);
}

try {
    $result = (new GoogleResourceDiscovery())->discover($pdo, $clientId, $source);
} catch (Throwable $e) {
    error_log('[GTMAuditor][Linking] discovery failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'discovery_failed'], 500);
}

api_respond($result);
