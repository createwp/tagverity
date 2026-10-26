<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$userId = (int)db()->query(
    'SELECT u.id FROM users u JOIN clients c ON c.account_id = u.account_id ORDER BY u.id ASC LIMIT 1'
)->fetchColumn();
if ($userId === 0) {
    echo "Release page test skipped: no user with a client workspace.\n";
    exit(0);
}

$_SESSION['user_id'] = $userId;
ob_start();
require __DIR__ . '/../publish-tags.php';
$html = ob_get_clean();

foreach (['Review changes', 'Preview &amp; test', 'Unpublished changes', 'New and modified tags only', 'Open visual preview'] as $expected) {
    if (strpos($html, $expected) === false) {
        fwrite(STDERR, 'Release page is missing: ' . $expected . "\n");
        exit(1);
    }
}

echo "Release page test passed.\n";
