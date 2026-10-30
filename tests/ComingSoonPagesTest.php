<?php
// Static checks for the honest "not available yet" Monitoring and BigQuery pages,
// and for the sidebar links that point at them.

$root = realpath(__DIR__ . '/..');

foreach (['monitoring.php', 'bigquery.php'] as $page) {
    $path = $root . '/' . $page;
    if (!is_file($path)) {
        fwrite(STDERR, "$page does not exist\n");
        exit(1);
    }

    $source = file_get_contents($path);
    foreach ([
        'require_auth(',
        'Not available yet',
        'Back to dashboard',
        'Included in the ',
        'class="page-header"',
        'app.js',
    ] as $expected) {
        if (strpos($source, $expected) === false) {
            fwrite(STDERR, "$page is missing: $expected\n");
            exit(1);
        }
    }

    if (stripos($source, '<form') !== false) {
        fwrite(STDERR, "$page must not contain a form\n");
        exit(1);
    }
}

$monitoring = file_get_contents($root . '/monitoring.php');
$bigquery   = file_get_contents($root . '/bigquery.php');
if (strpos($monitoring, "\$active_page = 'monitoring'") === false) {
    fwrite(STDERR, "monitoring.php must set \$active_page = 'monitoring'\n");
    exit(1);
}
if (strpos($bigquery, "\$active_page = 'bigquery'") === false) {
    fwrite(STDERR, "bigquery.php must set \$active_page = 'bigquery'\n");
    exit(1);
}

// Every sidebar link to a page in this app must point at a file that exists.
$sidebar = file_get_contents($root . '/includes/sidebar.php');
preg_match_all('/APP_URL \?>\/([A-Za-z0-9_-]+\.php)/', $sidebar, $matches);
$targets = array_unique($matches[1]);
if (count($targets) === 0) {
    fwrite(STDERR, "No sidebar hrefs found in includes/sidebar.php\n");
    exit(1);
}
foreach ($targets as $target) {
    if (!is_file($root . '/' . $target)) {
        fwrite(STDERR, "sidebar links to missing file: $target\n");
        exit(1);
    }
}
foreach (['monitoring.php', 'bigquery.php'] as $expected) {
    if (!in_array($expected, $targets, true)) {
        fwrite(STDERR, "sidebar does not link to $expected\n");
        exit(1);
    }
}

echo "Coming-soon pages test passed.\n";
