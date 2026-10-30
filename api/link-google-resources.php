<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/SourceStatus.php';
require_once __DIR__ . '/../src/GoogleApiClient.php';
require_once __DIR__ . '/../src/GoogleResourceLinker.php';

use GTMAuditor\GoogleApiClient;
use GTMAuditor\GoogleResourceLinker;
use GTMAuditor\SourceStatus;

// A list of Google ids: strings of digits, at most 200 items.
function link_google_id_list($value): ?array {
    if (!is_array($value) || count($value) > 200) {
        return null;
    }
    foreach ($value as $id) {
        if (!is_string($id) || !preg_match('/^[0-9]{1,20}$/', $id)) {
            return null;
        }
    }
    return array_values($value);
}

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = is_int($input['client_id'] ?? null) ? $input['client_id'] : 0;
$connectionId = is_int($input['connection_id'] ?? null) ? $input['connection_id'] : 0;
$ga4PropertyIds = link_google_id_list($input['ga4_property_ids'] ?? null);
$gtmContainerIds = link_google_id_list($input['gtm_container_ids'] ?? null);
if ($clientId < 1 || $connectionId < 1 || $ga4PropertyIds === null || $gtmContainerIds === null) {
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

try {
    $source = new GoogleApiClient($connection);
} catch (Throwable $e) {
    error_log('[GTMAuditor][Linking] Google client could not start for connection #' . $connectionId . ': ' . $e->getMessage());
    api_respond(['error' => SourceStatus::fromThrowable($e)], 409);
}

try {
    $linked = (new GoogleResourceLinker())->link(
        $pdo,
        $clientId,
        $connectionId,
        (int)$user['id'],
        $source,
        $ga4PropertyIds,
        $gtmContainerIds
    );
} catch (InvalidArgumentException $e) {
    $code = $e->getMessage() === 'not_accessible' ? 'not_accessible' : 'foreign_target';
    api_respond(['error' => $code], 422);
} catch (RuntimeException $e) {
    // Discovery failed: the message is a SourceStatus code. Anything else (PDOException is a
    // RuntimeException too) is logged and never echoed.
    $statuses = [
        SourceStatus::PERMISSION_DENIED, SourceStatus::AUTHENTICATION_FAILED, SourceStatus::QUOTA_LIMITED,
        SourceStatus::TIMED_OUT, SourceStatus::UNAVAILABLE, SourceStatus::INSUFFICIENT_COVERAGE,
    ];
    if (in_array($e->getMessage(), $statuses, true)) {
        api_respond(['error' => $e->getMessage()], 409);
    }
    error_log('[GTMAuditor][Linking] link failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'link_failed'], 500);
} catch (Throwable $e) {
    error_log('[GTMAuditor][Linking] link failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'link_failed'], 500);
}

api_respond([
    'success'    => true,
    'ga4_linked' => $linked['ga4_linked'],
    'gtm_linked' => $linked['gtm_linked'],
]);
