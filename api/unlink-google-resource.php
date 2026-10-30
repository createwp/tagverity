<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/GoogleResourceLinker.php';

use GTMAuditor\GoogleResourceLinker;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = is_int($input['client_id'] ?? null) ? $input['client_id'] : 0;
$type = $input['type'] ?? null;
$rowId = is_int($input['id'] ?? null) ? $input['id'] : 0;
if ($clientId < 1 || $rowId < 1 || ($type !== 'ga4' && $type !== 'gtm')) {
    api_respond(['error' => 'invalid_request'], 400);
}

assert_client_access($clientId);

try {
    (new GoogleResourceLinker())->unlink(db(), $clientId, $type, $rowId);
} catch (InvalidArgumentException $e) {
    api_respond(['error' => 'foreign_target'], 422);
} catch (Throwable $e) {
    error_log('[GTMAuditor][Linking] unlink failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'unlink_failed'], 500);
}

api_respond(['success' => true]);
