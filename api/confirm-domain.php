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
$domainId = is_int($input['domain_id'] ?? null) ? $input['domain_id'] : 0;
$answer = $input['answer'] ?? null;
if ($clientId < 1 || $domainId < 1 || !in_array($answer, ['yours', 'not_yours', 'investigate'], true)) {
    api_respond(['error' => 'invalid_request'], 400);
}

assert_client_access($clientId);

try {
    DomainMap::confirm(db(), $clientId, $domainId, $answer, (int)$user['id']);
} catch (InvalidArgumentException $e) {
    if ($e->getMessage() === 'foreign_target') {
        api_respond([
            'error' => 'foreign_target',
            'message' => "That website isn't part of this client. Refresh the page and try again.",
        ], 422);
    }
    api_respond(['error' => 'invalid_request'], 400);
} catch (Throwable $e) {
    error_log('[GTMAuditor][DomainMap] confirm failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'confirm_failed'], 500);
}

api_respond([
    'success' => true,
    'domain' => ['id' => $domainId, 'confirmation' => $answer],
]);
