<?php
// api/trigger-audit.php — Queue a new audit job for a client
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/rate-limit.php';

header('Content-Type: application/json');

$user = require_auth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// CSRF check
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid_csrf']);
    exit;
}

$client_id = (int)($_POST['client_id'] ?? 0);
$client    = assert_client_access($client_id); // Exits 403 if not owned

// Rate limit: max 5 audit triggers per user per 60 seconds
rate_limit('audit_trigger', (string)$user['id'], 5, 60);

// Check audit credit balance
$used = (int)db()->prepare(
    "SELECT COUNT(*) FROM audit_jobs
     WHERE client_id = ? AND status IN ('queued','running','complete')
     AND MONTH(started_at) = MONTH(NOW()) AND YEAR(started_at) = YEAR(NOW())"
)->execute([$client_id]) ? db()->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;

$limit = plan_limit('audit_credits');
if ($used >= $limit) {
    http_response_code(403);
    echo json_encode(['error' => 'credit_limit_reached', 'message' => 'Upgrade your plan for more audit credits.']);
    exit;
}

// Queue the job
$stmt = db()->prepare(
    "INSERT INTO audit_jobs (client_id, triggered_by, status, data_sources, created_at)
     VALUES (?, 'manual', 'queued', ?, NOW())"
);
$data_sources = json_encode([
    'gtm_api' => true,
    'ga4_api' => true,
    'bigquery' => has_feature('bigquery'),
    'crawl'    => has_feature('headless_crawl'),
]);
$stmt->execute([$client_id, $data_sources]);
$job_id = db()->lastInsertId();

echo json_encode(['success' => true, 'job_id' => $job_id]);
