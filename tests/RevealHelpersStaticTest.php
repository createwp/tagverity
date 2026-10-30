<?php
// Static checks for the shared reveal helpers (C9 Task 1).
$root = dirname(__DIR__);

function read_or_fail(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, basename($path) . " could not be read\n");
        exit(1);
    }
    return $contents;
}

function fail_if_missing(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . ' (missing: ' . $needle . ")\n");
        exit(1);
    }
}

$app = read_or_fail($root . '/assets/js/app.js');
fail_if_missing($app, 'window.TagVerityUI', 'app.js does not expose TagVerityUI');
foreach (['openDialog', 'closeDialog', 'openDrawer', 'closeDrawer', 'bindPopover', 'isOutside'] as $name) {
    fail_if_missing($app, $name . ':', 'app.js TagVerityUI is missing ' . $name);
}

$connections = read_or_fail($root . '/assets/js/connections.js');
fail_if_missing($connections, 'TagVerityUI.openDialog(', 'connections.js does not use TagVerityUI.openDialog');
if (strpos($connections, 'showModal(') !== false) {
    fwrite(STDERR, "connections.js still calls showModal() itself\n");
    exit(1);
}

$css = read_or_fail($root . '/assets/css/app.css');
fail_if_missing($css, '.reveal-drawer', 'app.css is missing the drawer styles');
fail_if_missing($css, 'prefers-reduced-motion', 'app.css is missing a reduced-motion rule');
fail_if_missing($css, '/* Reveal patterns */', 'app.css is missing the Reveal patterns section');

echo "Reveal helpers static test passed.\n";
