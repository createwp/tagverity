<?php
// Static checks for the Connections page (A1 Task 9): markup, picker and endpoint wiring.
$page = file_get_contents(__DIR__ . '/../connections.php');
if ($page === false) {
    fwrite(STDERR, "connections.php could not be read\n");
    exit(1);
}

foreach ([
    'id="resource-picker"',
    'Connect another Google account',
    'id="linked-resources"',
    'name="ga4_audit_target"',
    'name="gtm_audit_target"',
    'assets/js/connections.js',
] as $expected) {
    if (strpos($page, $expected) === false) {
        fwrite(STDERR, 'connections.php is missing: ' . $expected . "\n");
        exit(1);
    }
}

$script = file_get_contents(__DIR__ . '/../assets/js/connections.js');
if ($script === false) {
    fwrite(STDERR, "assets/js/connections.js could not be read\n");
    exit(1);
}

foreach ([
    'get-google-resources.php',
    'link-google-resources.php',
    'unlink-google-resource.php',
    'disconnect-google.php',
    'save-audit-target.php',
    'csrf_token',
] as $expected) {
    if (strpos($script, $expected) === false) {
        fwrite(STDERR, 'connections.js is missing: ' . $expected . "\n");
        exit(1);
    }
}

// Dynamic API data must be written with textContent, never innerHTML.
if (preg_match('/\.innerHTML\s*=/', $script)) {
    fwrite(STDERR, "connections.js must not assign innerHTML\n");
    exit(1);
}

// Select-all must act only on rows the search currently shows.
if (!preg_match("/selectAll\\.addEventListener\\('change'[\\s\\S]*?visibleSelectableBoxes\\(group\\)/", $script)) {
    fwrite(STDERR, "connections.js select-all handler does not use the visible-items filter\n");
    exit(1);
}
if (strpos($script, 'function visibleSelectableBoxes') === false || strpos($script, "closest('.picker-item').hidden") === false) {
    fwrite(STDERR, "connections.js visible-items filter does not check the hidden state\n");
    exit(1);
}

// The search wrapper draws the single focus ring; the inner input must not add its own.
$styles = file_get_contents(__DIR__ . '/../assets/css/app.css');
if (strpos($styles, '.picker-search-field input:focus-visible { outline: none') === false) {
    fwrite(STDERR, "search input still draws its own focus outline\n");
    exit(1);
}

echo "Connections page test passed.\n";
