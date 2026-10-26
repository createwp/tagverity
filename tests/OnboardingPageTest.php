<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$userId = (int)db()->query(
    'SELECT u.id FROM users u JOIN clients c ON c.account_id = u.account_id ORDER BY u.id ASC LIMIT 1'
)->fetchColumn();
if ($userId === 0) {
    echo "Onboarding page test skipped: no user with a client workspace.\n";
    exit(0);
}

$_SESSION['user_id'] = $userId;
ob_start();
require __DIR__ . '/../onboarding.php';
$html = ob_get_clean();

foreach ([
    'data-step-panel="scan"',
    'data-step-panel="review"',
    'data-step-panel="verify"',
    'data-step-panel="ready"',
    'Verify business meaning',
    'Review draft tags',
    'previewing and testing every trigger',
] as $expected) {
    if (strpos($html, $expected) === false) {
        fwrite(STDERR, 'Onboarding page is missing: ' . $expected . "\n");
        exit(1);
    }
}

if (strpos($html, 'id="plan-events"') !== false) {
    fwrite(STDERR, "Onboarding page still exposes the full proposed event list.\n");
    exit(1);
}

echo "Onboarding page test passed.\n";
