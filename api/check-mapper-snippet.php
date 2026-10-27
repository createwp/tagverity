<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/MapperSnippetDetector.php';
require_once __DIR__ . '/../src/SiteScanner.php';

use GTMAuditor\MapperSnippetDetector;
use GTMAuditor\SiteScanner;

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
    api_respond(['error' => 'measurement_plan_required', 'message' => 'Complete a site scan before checking the mapper snippet.'], 409);
}

try {
    $scanner = new SiteScanner(1, 1000000, 6, SITE_SCAN_CA_BUNDLE);
    $response = $scanner->fetchPreviewPage($scan['root_url']);
} catch (Throwable $e) {
    error_log('Mapper snippet check failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'snippet_check_failed', 'message' => 'TagVerity could not fetch the site to check for the mapper snippet.'], 422);
}

$detected = (new MapperSnippetDetector())->detect($response['body']);
$detectedAt = $scan['mapper_snippet_detected_at'];

if ($detected) {
    $stmt = db()->prepare("UPDATE site_scans SET mapper_snippet_detected_at = NOW() WHERE id = ?");
    $stmt->execute([$scan['id']]);
    $stmt = db()->prepare("SELECT mapper_snippet_detected_at FROM site_scans WHERE id = ?");
    $stmt->execute([$scan['id']]);
    $detectedAt = $stmt->fetchColumn();
}

api_respond(['ok' => true, 'data' => [
    'detected' => $detected,
    'detected_at' => $detectedAt,
]]);
