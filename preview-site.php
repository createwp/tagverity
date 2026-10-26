<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';

use GTMAuditor\SitePreviewRenderer;
use GTMAuditor\SiteScanner;

require_auth();
$clientId = (int)($_GET['client_id'] ?? 0);
assert_client_access($clientId);
$requestedUrl = trim((string)($_GET['url'] ?? ''));

$stmt = db()->prepare(
    "SELECT op.latest_scan_id, ss.scan_data
     FROM onboarding_profiles op
     JOIN site_scans ss ON ss.id = op.latest_scan_id AND ss.client_id = op.client_id
     WHERE op.client_id = ? AND ss.status = 'complete'
     LIMIT 1"
);
$stmt->execute([$clientId]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(409);
    exit('Complete a site scan before opening the visual preview.');
}

$scan = json_decode($row['scan_data'], true);
$allowedPages = [];
foreach ($scan['pages'] ?? [] as $page) {
    if (!empty($page['url'])) {
        $allowedPages[rtrim($page['url'], '/')] = $page['url'];
    }
}
$pageKey = rtrim($requestedUrl, '/');
if ($requestedUrl === '' || !isset($allowedPages[$pageKey])) {
    http_response_code(403);
    exit('This page is not part of the latest site scan.');
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline' https: http:; font-src https: http: data:; media-src https: http:; script-src 'unsafe-inline'; connect-src 'none'; frame-src 'none'; object-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri https: http:");

try {
    $scanner = new SiteScanner(1, 1000000, 6, SITE_SCAN_CA_BUNDLE);
    $response = $scanner->fetchPreviewPage($allowedPages[$pageKey]);
    $renderer = new SitePreviewRenderer();
    echo $renderer->render($response['body'], $response['url']);
} catch (Throwable $e) {
    error_log('Visual preview failed for client #' . $clientId . ': ' . $e->getMessage());
    http_response_code(422);
    echo '<!doctype html><html><body style="font-family:system-ui;padding:30px"><h1>Preview unavailable</h1><p>TagVerity could not create a safe preview for this page. Try rescanning the site or choose another page.</p></body></html>';
}

