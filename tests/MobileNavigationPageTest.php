<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$userId = (int)db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($userId === 0) {
    echo "Mobile navigation page test skipped: no users.\n";
    exit(0);
}

$_SESSION['user_id'] = $userId;
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require __DIR__ . '/../dashboard.php';
$html = ob_get_clean();

foreach ([
    'class="mobile-app-bar"',
    'id="mobile-sidebar-toggle"',
    'aria-controls="sidebar"',
    'id="mobile-sidebar-backdrop"',
    'id="mobile-sidebar-close"',
    'id="sidebar"',
] as $expected) {
    if (strpos($html, $expected) === false) {
        fwrite(STDERR, 'Mobile navigation is missing: ' . $expected . "\n");
        exit(1);
    }
}

if (substr_count($html, 'id="mobile-sidebar-toggle"') !== 1 || strpos($html, 'id="sidebar-toggle"') !== false) {
    fwrite(STDERR, "Mobile navigation contains duplicate or legacy toggle controls.\n");
    exit(1);
}

$script = file_get_contents(__DIR__ . '/../assets/js/app.js');
$styles = file_get_contents(__DIR__ . '/../assets/css/app.css');
foreach (['aria-expanded', 'mobile-sidebar-open', "event.key === 'Escape'", 'sidebar.inert'] as $expected) {
    if (strpos($script, $expected) === false) {
        fwrite(STDERR, 'Mobile navigation script is missing: ' . $expected . "\n");
        exit(1);
    }
}
foreach (['.mobile-app-bar', '.mobile-sidebar-backdrop.open', '.sidebar.open', '100dvh'] as $expected) {
    if (strpos($styles, $expected) === false) {
        fwrite(STDERR, 'Mobile navigation styles are missing: ' . $expected . "\n");
        exit(1);
    }
}

echo "Mobile navigation page test passed.\n";
