<?php
// Static checks for the Domain map page and its endpoints (A2 Task 5): markup, gating and wiring.
function read_or_fail(string $relative): string {
    $contents = file_get_contents(__DIR__ . '/../' . $relative);
    if ($contents === false) {
        fwrite(STDERR, $relative . " could not be read\n");
        exit(1);
    }
    return $contents;
}

function require_fragments(string $label, string $source, array $fragments): void {
    foreach ($fragments as $expected) {
        if (strpos($source, $expected) === false) {
            fwrite(STDERR, $label . ' is missing: ' . $expected . "\n");
            exit(1);
        }
    }
}

$page = read_or_fail('domain-map.php');
if (strpos($page, '(stub)') !== false) {
    fwrite(STDERR, "domain-map.php still contains the stub text\n");
    exit(1);
}
require_fragments('domain-map.php', $page, [
    'require_auth(',
    'DomainMap::refresh(',
    'DomainMap::load(',
    'role="img"',
    'Is this yours?',
    'assets/js/domain-map.js',
    'assets/js/app.js',
]);

foreach (['api/confirm-domain.php', 'api/add-domain.php'] as $endpoint) {
    require_fragments($endpoint, read_or_fail($endpoint), [
        'api_require_post(',
        'api_require_csrf(',
        'api_require_editor(',
        'assert_client_access(',
    ]);
}

$script = read_or_fail('assets/js/domain-map.js');
require_fragments('domain-map.js', $script, [
    'confirm-domain.php',
    'add-domain.php',
    'csrf_token',
    'showToast(',
]);

// Dynamic API data must be written with textContent, never innerHTML.
if (preg_match('/\.innerHTML\s*=/', $script) || strpos($script, 'innerHTML') !== false) {
    fwrite(STDERR, "domain-map.js must not use innerHTML\n");
    exit(1);
}

echo "Domain map page test passed.\n";
