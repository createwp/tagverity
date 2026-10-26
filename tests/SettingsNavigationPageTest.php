<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$userId = (int)db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($userId === 0) {
    echo "Settings navigation page test skipped: no users.\n";
    exit(0);
}

$_SESSION['user_id'] = $userId;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['tab' => 'security'];

ob_start();
require __DIR__ . '/../account-settings.php';
$settingsHtml = ob_get_clean();

foreach ([
    'class="settings-tabs"',
    'data-settings-tab="profile"',
    'data-settings-tab="security"',
    'data-settings-panel="security"',
    'sidebar-nav-details',
    'Tracking setup',
    'Domain map',
    'Event destinations',
    'Google connections',
] as $expected) {
    if (strpos($settingsHtml, $expected) === false) {
        fwrite(STDERR, 'Settings navigation page is missing: ' . $expected . "\n");
        exit(1);
    }
}

if (strpos($settingsHtml, 'data-settings-panel="profile" hidden') === false) {
    fwrite(STDERR, "Profile panel was not hidden when Security was requested.\n");
    exit(1);
}

$_GET = [];
ob_start();
require __DIR__ . '/../dashboard.php';
$dashboardHtml = ob_get_clean();

if (strpos($dashboardHtml, 'page-header-actions') === false || strpos($dashboardHtml, 'tracking setup') === false && strpos($dashboardHtml, 'setup wizard') === false) {
    fwrite(STDERR, "Dashboard is missing the tracking setup wizard action.\n");
    exit(1);
}

$styles = file_get_contents(__DIR__ . '/../assets/css/app.css');
foreach ([
    '.settings-page { width: 100%; max-width: 900px; }',
    '.settings-panel .card { max-width: none; }',
] as $expectedStyle) {
    if (strpos($styles, $expectedStyle) === false) {
        fwrite(STDERR, 'Settings layout is missing its shared content alignment: ' . $expectedStyle . "\n");
        exit(1);
    }
}

echo "Settings navigation page test passed.\n";
