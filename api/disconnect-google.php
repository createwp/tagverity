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

if (!in_array($user['role'] ?? '', ['owner', 'superadmin'], true)) {
    api_respond(['error' => 'insufficient_role'], 403);
}

$connectionId = is_int($input['connection_id'] ?? null) ? $input['connection_id'] : 0;
if ($connectionId < 1) {
    api_respond(['error' => 'invalid_request'], 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM google_connections WHERE id = ? AND account_id = ?');
$stmt->execute([$connectionId, $user['account_id']]);
if (!$stmt->fetch()) {
    api_respond(['error' => 'connection_not_found'], 404);
}

try {
    (new GoogleResourceLinker())->disconnect($pdo, (int)$user['account_id'], $connectionId);
} catch (InvalidArgumentException $e) {
    api_respond(['error' => 'connection_not_found'], 404);
} catch (Throwable $e) {
    error_log('[GTMAuditor][Linking] disconnect failed for connection #' . $connectionId . ': ' . $e->getMessage());
    api_respond(['error' => 'disconnect_failed'], 500);
}

api_respond(['success' => true]);
