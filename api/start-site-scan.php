<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../includes/api.php';

use GTMAuditor\OnboardingPlanner;
use GTMAuditor\SiteScanner;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
assert_client_access($clientId);
rate_limit('site_scan', (string)$user['id'], 5, 600);

$scanner = new SiteScanner(8, 750000, 4, SITE_SCAN_CA_BUNDLE);
try {
    $rootUrl = $scanner->normaliseRootUrl((string)($input['site_url'] ?? ''));
} catch (Throwable $e) {
    api_respond(['error' => 'invalid_site_url', 'message' => 'Enter a public website URL, such as https://example.com.'], 422);
}

$pdo = db();
$stmt = $pdo->prepare(
    "INSERT INTO site_scans (client_id, requested_by_user_id, root_url, status, started_at, created_at)
     VALUES (?, ?, ?, 'running', NOW(), NOW())"
);
$stmt->execute([$clientId, $user['id'], $rootUrl]);
$scanId = (int)$pdo->lastInsertId();

try {
    $scan = $scanner->scan($rootUrl);
    $planner = new OnboardingPlanner();
    $questionnaire = $planner->buildQuestionnaire($scan);
    $scanJson = json_encode($scan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $questionnaireJson = json_encode($questionnaire, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "UPDATE site_scans
         SET status = 'complete', pages_discovered = ?, pages_scanned = ?, scan_data = ?, questionnaire = ?, completed_at = NOW()
         WHERE id = ? AND client_id = ?"
    );
    $stmt->execute([
        (int)$scan['coverage']['pages_discovered'],
        (int)$scan['coverage']['pages_scanned'],
        $scanJson,
        $questionnaireJson,
        $scanId,
        $clientId,
    ]);

    $stmt = $pdo->prepare(
        "INSERT INTO onboarding_profiles (client_id, latest_scan_id, site_url, status, business_type, answers, measurement_plan, created_at, updated_at)
         VALUES (?, ?, ?, 'questions_ready', NULL, NULL, NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE latest_scan_id = VALUES(latest_scan_id), site_url = VALUES(site_url),
             status = 'questions_ready', business_type = NULL, answers = NULL, measurement_plan = NULL, updated_at = NOW()"
    );
    $stmt->execute([$clientId, $scanId, $rootUrl]);
    $pdo->commit();

    api_respond([
        'ok' => true,
        'data' => [
            'profile' => ['client_id' => $clientId, 'site_url' => $rootUrl, 'status' => 'questions_ready'],
            'scan' => ['id' => $scanId, 'status' => 'complete', 'data' => $scan, 'questionnaire' => $questionnaire],
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Site scan failed for scan #' . $scanId . ': ' . $e->getMessage());
    $code = str_contains($e->getMessage(), 'site_unreachable') ? 'site_unreachable' : 'scan_failed';
    $stmt = $pdo->prepare(
        "UPDATE site_scans SET status = 'failed', error_code = ?, completed_at = NOW() WHERE id = ? AND client_id = ?"
    );
    $stmt->execute([$code, $scanId, $clientId]);
    api_respond([
        'error' => $code,
        'message' => 'We could not read enough public HTML from this site. Check the URL and firewall settings, then try again.',
    ], 422);
}
