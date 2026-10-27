<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/MapperSessionManager.php';
require_once __DIR__ . '/../src/ScanHostAllowlist.php';

use GTMAuditor\MapperSessionManager;
use GTMAuditor\ScanHostAllowlist;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
assert_client_access($clientId);

$stmt = db()->prepare(
    "SELECT ss.id, ss.root_url, ss.scan_data, ss.mapper_snippet_detected_at
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

$allowlist = new ScanHostAllowlist();

$rootUrl = $scan['root_url'];
$startUrl = trim((string)($input['start_url'] ?? ''));
if ($startUrl !== '') {
    // Same validated-parse-and-rebuild pattern as api/save-element-mapping.php's
    // page_url check, but checking *origin* membership (scheme+host+port
    // together) rather than host alone — a start_url whose host is legitimate
    // but whose scheme/port don't match any origin the scan actually saw would
    // otherwise pass a host-only check here and then fail validateToken()'s
    // origin_mismatch check later with a confusing error.
    if (preg_match('/[\x00-\x1F\x7F\\\\]/', $startUrl)) {
        api_respond(['error' => 'start_url_outside_domain', 'message' => 'Choose a page on your verified site domain.'], 422);
    }
    $parts = parse_url($startUrl);
    if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
        api_respond(['error' => 'start_url_outside_domain', 'message' => 'Choose a page on your verified site domain.'], 422);
    }
    $startScheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($startScheme, ['http', 'https'], true)) {
        api_respond(['error' => 'start_url_outside_domain', 'message' => 'Choose a page on your verified site domain.'], 422);
    }
    // Compare canonical origins (default port omitted), not a raw
    // scheme+host+port concatenation — otherwise a start_url that spells
    // out a default port (https://example.com:443/) would mismatch
    // origins()'s default-port-omitted form and be wrongly rejected.
    $startOrigin = $allowlist->canonicalOrigin($startUrl);
    if ($startOrigin === null || !in_array($startOrigin, $allowlist->origins($scan['root_url'], $scan['scan_data']), true)) {
        api_respond(['error' => 'start_url_outside_domain', 'message' => 'Choose a page on your verified site domain.'], 422);
    }
    $rootUrl = $startOrigin . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

$session = (new MapperSessionManager())->createToken(db(), $clientId, (int)$scan['id'], (int)$user['id']);

api_respond(['ok' => true, 'data' => [
    'token' => $session['token'],
    'root_url' => $rootUrl,
    'allowed_origins' => $allowlist->origins($scan['root_url'], $scan['scan_data']),
    'expires_at' => $session['expires_at'],
]]);
