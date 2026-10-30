<?php
// Static checks for the Google linking endpoints (Task 8). Each endpoint is a thin
// controller: auth, role, CSRF, client access, ownership of the connection, then a class call.

$root = __DIR__ . '/..';

$endpoints = [
    'api/get-google-resources.php'    => ['get' => true,  'client' => true,  'connection' => true,  'post' => false],
    'api/link-google-resources.php'   => ['get' => false, 'client' => true,  'connection' => true,  'post' => true],
    'api/unlink-google-resource.php'  => ['get' => false, 'client' => true,  'connection' => false, 'post' => true],
    'api/disconnect-google.php'       => ['get' => false, 'client' => false, 'connection' => true,  'post' => true],
    'api/save-audit-target.php'       => ['get' => false, 'client' => true,  'connection' => false, 'post' => true],
];

$failures = [];

foreach ($endpoints as $relative => $expect) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $failures[] = $relative . ' does not exist';
        continue;
    }
    $source = (string) file_get_contents($path);

    if (strpos($source, 'require_auth()') === false) {
        $failures[] = $relative . ' does not call require_auth()';
    }
    if (strpos($source, 'api_respond(') === false) {
        $failures[] = $relative . ' does not respond through api_respond()';
    }
    if ($expect['post']) {
        if (strpos($source, 'api_require_post(') === false) {
            $failures[] = $relative . ' does not call api_require_post()';
        }
        if (strpos($source, 'api_require_csrf(') === false) {
            $failures[] = $relative . ' does not call api_require_csrf()';
        }
        if (strpos($source, 'api_require_editor(') === false) {
            $failures[] = $relative . ' does not call api_require_editor()';
        }
    }
    if ($expect['client'] && strpos($source, 'assert_client_access(') === false) {
        $failures[] = $relative . ' does not call assert_client_access()';
    }
    if ($expect['connection']) {
        if (strpos($source, 'FROM google_connections WHERE id = ? AND account_id = ?') === false) {
            $failures[] = $relative . ' does not load google_connections filtered by account_id';
        }
        if (strpos($source, "['account_id']") === false) {
            $failures[] = $relative . ' does not bind the account_id from the logged-in user';
        }
    }
}

$disconnect = (string) @file_get_contents($root . '/api/disconnect-google.php');
if (strpos($disconnect, "'owner'") === false || strpos($disconnect, "'superadmin'") === false) {
    $failures[] = 'api/disconnect-google.php does not check the owner and superadmin roles';
}

$getResources = (string) @file_get_contents($root . '/api/get-google-resources.php');
if (strpos($getResources, "rate_limit('google_discovery'") === false) {
    $failures[] = 'api/get-google-resources.php does not rate-limit google_discovery';
}

if ($failures) {
    fwrite(STDERR, "Google linking endpoints test failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Google linking endpoints test passed.\n";
