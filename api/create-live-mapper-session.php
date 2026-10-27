<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/MapperSessionManager.php';

use GTMAuditor\MapperSessionManager;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
assert_client_access($clientId);

$stmt = db()->prepare(
    "SELECT ss.id, ss.root_url, ss.mapper_snippet_detected_at
     FROM onboarding_profiles op
     JOIN site_scans ss ON ss.id = op.latest_scan_id AND ss.client_id = op.client_id
     WHERE op.client_id = ? AND ss.status = 'complete'
     LIMIT 1"
);
$stmt->execute([$clientId]);
$scan = $stmt->fetch();
if (!$scan) {
    api_respond(['error' => 'measurement_plan_required', 'message' => 'Complete a site scan before opening the live mapper.'], 409);
}
if ($scan['mapper_snippet_detected_at'] === null) {
    api_respond(['error' => 'snippet_not_detected', 'message' => 'TagVerity has not detected the live mapper snippet on this site yet.'], 409);
}

$session = (new MapperSessionManager())->createToken(db(), $clientId, (int)$scan['id'], (int)$user['id']);

api_respond(['ok' => true, 'data' => [
    'token' => $session['token'],
    'root_url' => $scan['root_url'],
    'expires_at' => $session['expires_at'],
]]);
