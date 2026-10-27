<?php
// Cross-origin endpoint: called directly from the client's own live website
// (via the JS snippet), never from the TagVerity dashboard. There is no PHP
// session cookie and no CSRF token here — do NOT require includes/auth.php
// or any session-based helper. Authorization comes entirely from the
// random, hashed, single-use mapper session token minted by
// api/create-live-mapper-session.php and validated below.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rate-limit.php';
// Explicit require (not just the `use` below) because this worktree's
// vendor/ directory is a junction to the main checkout, and Composer's
// autoloader can't resolve classes that only exist on this branch.
require_once __DIR__ . '/../src/MapperSessionManager.php';

use GTMAuditor\MapperSessionManager;

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

rate_limit('live_mapper_exchange', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 30, 60);

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$token = (string)($input['token'] ?? '');

if ($token === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'missing_token']);
    exit;
}

try {
    $data = (new MapperSessionManager())->validateToken(db(), $token, $origin);
} catch (DomainException $e) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'data' => $data]);
