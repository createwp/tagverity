<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/DomainMap.php';

use GTMAuditor\DomainMap;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = is_int($input['client_id'] ?? null) ? $input['client_id'] : 0;
$url = $input['url'] ?? null;
if ($clientId < 1 || !is_string($url) || trim($url) === '' || strlen($url) > 2048) {
    api_respond(['error' => 'invalid_request'], 400);
}

assert_client_access($clientId);

try {
    $domainId = DomainMap::addManual(db(), $clientId, $url);
} catch (InvalidArgumentException $e) {
    api_respond([
        'error' => 'invalid_host',
        'message' => "That doesn't look like a website address. Try something like example.com.",
    ], 422);
} catch (Throwable $e) {
    error_log('[GTMAuditor][DomainMap] add failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'add_failed'], 500);
}

api_respond(['success' => true, 'domain_id' => $domainId]);
