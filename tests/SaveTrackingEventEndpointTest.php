<?php
// Static guard for api/save-tracking-event.php. The endpoint has no namespace, so every
// GTMAuditor class it uses (new X, X::) must be both required from src/ and imported with
// `use GTMAuditor\X;`. A missing import resolves to a global class and only fails at runtime.

$root = dirname(__DIR__);
$endpointPath = $root . '/api/save-tracking-event.php';
$endpoint = str_replace("\r\n", "\n", (string)file_get_contents($endpointPath));

function save_endpoint_fail(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// Classes declared under src/, keyed by class name, mapped to their file.
$srcClasses = [];
foreach (glob($root . '/src/*.php') ?: [] as $file) {
    $source = (string)file_get_contents($file);
    if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $matches)) {
        foreach ($matches[1] as $class) $srcClasses[$class] = basename($file);
    }
}

// Class names the endpoint instantiates or calls statically.
$used = [];
if (preg_match_all('/\bnew\s+(\w+)/', $endpoint, $matches)) $used = array_merge($used, $matches[1]);
if (preg_match_all('/\b(\w+)::/', $endpoint, $matches)) $used = array_merge($used, $matches[1]);
$used = array_values(array_unique($used));

$checked = 0;
foreach ($used as $class) {
    if (!isset($srcClasses[$class])) continue;
    $checked++;
    $file = $srcClasses[$class];
    if (!preg_match('/^use\s+GTMAuditor\\\\' . preg_quote($class, '/') . '\s*;/m', $endpoint)) {
        save_endpoint_fail("api/save-tracking-event.php uses {$class} without `use GTMAuditor\\{$class};`, so it resolves to a global class.");
    }
    if (strpos($endpoint, "/../src/{$file}'") === false) {
        save_endpoint_fail("api/save-tracking-event.php uses {$class} without requiring src/{$file}.");
    }
}

// The classes this task depends on must be among those checked.
foreach (['ManualParameters', 'EventDestinationRouter', 'TrackingWorkspace'] as $required) {
    if (!in_array($required, $used, true)) {
        save_endpoint_fail("Expected api/save-tracking-event.php to reference {$required}; the guard would not cover it.");
    }
}
if ($checked === 0) save_endpoint_fail('No src classes were found in api/save-tracking-event.php; the guard is not checking anything.');

echo "SaveTrackingEventEndpointTest passed.\n";
