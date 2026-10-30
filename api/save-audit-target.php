<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/AuditTargetResolver.php';

use GTMAuditor\AuditTargetResolver;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

// Target ids are row ids. Null clears that resource's target.
$clientId = is_int($input['client_id'] ?? null) ? $input['client_id'] : 0;
$gtmId = $input['gtm_container_id'] ?? null;
$ga4Id = $input['ga4_property_id'] ?? null;
$validTarget = static fn($v): bool => $v === null || (is_int($v) && $v > 0);
if ($clientId < 1 || !$validTarget($gtmId) || !$validTarget($ga4Id)) {
    api_respond(['error' => 'invalid_request'], 400);
}

assert_client_access($clientId);

try {
    (new AuditTargetResolver())->setTargets(db(), $clientId, $gtmId, $ga4Id);
} catch (InvalidArgumentException $e) {
    api_respond(['error' => 'foreign_target'], 422);
} catch (Throwable $e) {
    error_log('[GTMAuditor][Linking] save audit target failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'target_save_failed'], 500);
}

api_respond(['success' => true]);
