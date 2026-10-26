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

foreach (['Event destinations', 'Connect each platform once', 'Connect your analytics and advertising tools', 'destination-connection-grid', 'destination-chevron-icon', 'destination-connections.js', 'Tags &amp; triggers'] as $expected) {
    if (strpos($html, $expected) === false) {
        fwrite(STDERR, 'Destination connections page is missing: ' . $expected . "\n");
        exit(1);
    }
}

$script = file_get_contents(__DIR__ . '/../assets/js/destination-connections.js');
foreach (['get-destination-connections.php', 'save-destination-connection.php', '<details class="card destination-connection-card"', 'Guided setup', 'Connect Google', 'docs_url', 'Stored securely', 'Save '] as $expected) {
    if (strpos($script, $expected) === false) {
        fwrite(STDERR, 'Destination connection UI is missing: ' . $expected . "\n");
        exit(1);
    }
}

echo "Destination connections page test passed.\n";
