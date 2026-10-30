<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$userId = (int)db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($userId === 0) {
    echo "Destination connections page test skipped: no users.\n";
    exit(0);
}

$_SESSION['user_id'] = $userId;
$_GET = [];
ob_start();
require __DIR__ . '/../destination-connections.php';
$html = ob_get_clean();

foreach (['Event destinations', 'Connect each platform once', 'Connect your analytics and advertising tools', 'destination-connection-grid', 'destination-dialog', 'destination-connections.js', 'Tags &amp; triggers'] as $expected) {
    if (strpos($html, $expected) === false) {
        fwrite(STDERR, 'Destination connections page is missing: ' . $expected . "\n");
        exit(1);
    }
}

if (strpos($html, 'id="destination-dialog"') === false || strpos($html, 'class="reveal-dialog"') === false) {
    fwrite(STDERR, "Destination connections page must render the setup dialog as <dialog id=\"destination-dialog\" class=\"reveal-dialog\">\n");
    exit(1);
}

if (strpos($html, 'destination-chevron-icon') !== false || strpos($html, '<details class="card destination-connection-card"') !== false) {
    fwrite(STDERR, "Destination connections page still renders the accordion markup\n");
    exit(1);
}

$script = file_get_contents(__DIR__ . '/../assets/js/destination-connections.js');
foreach (['get-destination-connections.php', 'save-destination-connection.php', 'TagVerityUI.openDialog(', "'destination-tile'", 'Start here', 'Not connected', 'Connected', 'Guided setup', 'Connect Google', 'docs_url', 'Stored securely', 'Save '] as $expected) {
    if (strpos($script, $expected) === false) {
        fwrite(STDERR, 'Destination connection UI is missing: ' . $expected . "\n");
        exit(1);
    }
}

foreach (['innerHTML', 'escapeHtml', '<details', 'destination-chevron-icon'] as $forbidden) {
    if (strpos($script, $forbidden) !== false) {
        fwrite(STDERR, 'Destination connection UI must not use: ' . $forbidden . "\n");
        exit(1);
    }
}

$css = file_get_contents(__DIR__ . '/../assets/css/app.css');
foreach (['.destination-grid', '--brand-ga4'] as $expected) {
    if (strpos($css, $expected) === false) {
        fwrite(STDERR, 'Destination connection styles are missing: ' . $expected . "\n");
        exit(1);
    }
}

echo "Destination connections page test passed.\n";
