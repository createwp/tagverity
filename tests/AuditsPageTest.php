<?php
// Static checks for the account-wide audit history page and its dashboard link.

$root = __DIR__ . '/..';
$page = file_get_contents($root . '/audits.php');
$dashboard = file_get_contents($root . '/dashboard.php');

foreach ([
    'require_auth(',
    'AuditHistory::page(',
    'htmlspecialchars(',
    "\$active_page = 'audits'",
    'audit-report.php?id=',
    'No audits yet.',
    'Run your first audit from the',
] as $expected) {
    if (strpos($page, $expected) === false) {
        fwrite(STDERR, 'audits.php is missing: ' . $expected . "\n");
        exit(1);
    }
}

if (strpos($dashboard, 'audits.php?client_id=') === false) {
    fwrite(STDERR, "dashboard.php does not link to audits.php?client_id=\n");
    exit(1);
}

echo "Audits page test passed.\n";
