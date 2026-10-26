<?php
$pages = [
    'dashboard.php',
    'account-settings.php',
    'clients.php',
    'connections.php',
    'billing.php',
    'audit-report.php',
    'onboarding.php',
    'element-mapper.php',
    'publish-tags.php',
    'tracking-workspace.php',
];

foreach ($pages as $page) {
    $source = file_get_contents(__DIR__ . '/../' . $page);
    foreach (['class="page-header"', 'class="page-header-copy"', 'class="page-title"', 'class="page-subtitle"'] as $expected) {
        if (strpos($source, $expected) === false) {
            fwrite(STDERR, $page . ' is missing the shared header structure: ' . $expected . "\n");
            exit(1);
        }
    }
}

$styles = file_get_contents(__DIR__ . '/../assets/css/app.css');
foreach (['--page-gutter:', 'padding: 16px var(--page-gutter)', 'padding: var(--page-gutter)', '.page-header-actions'] as $expected) {
    if (strpos($styles, $expected) === false) {
        fwrite(STDERR, 'Shared page-header styles are missing: ' . $expected . "\n");
        exit(1);
    }
}

echo "Page header consistency test passed.\n";
