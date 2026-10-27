<?php
require_once __DIR__ . '/../src/ScanHostAllowlist.php';
use GTMAuditor\ScanHostAllowlist;

function assert_allowlist($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " failed: expected " . json_encode($expected) . ", got " . json_encode($actual) . "\n");
        exit(1);
    }
}

function assert_allowlist_set(array $expected, array $actual, string $label): void {
    sort($expected);
    sort($actual);
    assert_allowlist($expected, $actual, $label);
}

$allowlist = new ScanHostAllowlist();

// 1. Apex root + a www page in scan_data: both hosts are allowed, deduplicated.
$scanDataApexAndWww = json_encode([
    'pages' => [
        ['url' => 'https://www.example.com/'],
        ['url' => 'https://www.example.com/pricing'],
        ['url' => 'https://example.com/'], // same host as root — must not duplicate
    ],
]);
assert_allowlist_set(
    ['example.com', 'www.example.com'],
    $allowlist->hosts('https://example.com/', $scanDataApexAndWww),
    'hosts() includes both apex root and redirected www host'
);

// 1b. A redirect through an unrelated third-party host (tracker, payment
// processor, etc.) during the scan must NEVER become an authorized host or
// origin — only the root host and its one apex/www sibling ever qualify,
// no matter how many other distinct hosts appear in scan_data.pages[].
$scanDataWithThirdPartyHost = json_encode([
    'pages' => [
        ['url' => 'https://www.example.com/pricing'],
        ['url' => 'https://tracker.example.net/pixel'],
        ['url' => 'https://checkout.payments.example.org/session'],
    ],
]);
assert_allowlist_set(
    ['example.com', 'www.example.com'],
    $allowlist->hosts('https://example.com/', $scanDataWithThirdPartyHost),
    'hosts() excludes unrelated third-party redirect hosts even when present in scan_data'
);
assert_allowlist_set(
    ['https://example.com', 'https://www.example.com'],
    $allowlist->origins('https://example.com/', $scanDataWithThirdPartyHost),
    'origins() excludes unrelated third-party redirect hosts even when present in scan_data'
);
// With no apex/www sibling present at all, only the root's own host/origin
// survive — not any of the third-party hosts.
$scanDataOnlyThirdPartyHosts = json_encode([
    'pages' => [
        ['url' => 'https://tracker.example.net/pixel'],
        ['url' => 'https://checkout.payments.example.org/session'],
    ],
]);
assert_allowlist_set(
    ['example.com'],
    $allowlist->hosts('https://example.com/', $scanDataOnlyThirdPartyHosts),
    'hosts() falls back to just the root host when scan_data has only third-party hosts'
);
assert_allowlist_set(
    ['https://example.com'],
    $allowlist->origins('https://example.com/', $scanDataOnlyThirdPartyHosts),
    'origins() falls back to just the root origin when scan_data has only third-party hosts'
);

// 2. No scan_data at all: falls back to just the root's own host.
assert_allowlist_set(
    ['example.com'],
    $allowlist->hosts('https://example.com/', null),
    'hosts() falls back to root host when scan_data is null'
);
assert_allowlist_set(
    ['example.com'],
    $allowlist->hosts('https://example.com/', ''),
    'hosts() falls back to root host when scan_data is blank'
);
assert_allowlist_set(
    ['example.com'],
    $allowlist->hosts('https://example.com/', 'not valid json {{{'),
    'hosts() falls back to root host when scan_data is invalid JSON'
);
assert_allowlist_set(
    ['example.com'],
    $allowlist->hosts('https://example.com/', json_encode(['summary' => []])),
    'hosts() falls back to root host when scan_data has no pages key'
);
assert_allowlist_set(
    ['example.com'],
    $allowlist->hosts('https://example.com/', json_encode(['pages' => 'not-an-array'])),
    'hosts() falls back to root host when pages is not an array'
);

// 3. Trailing-dot and case-insensitivity.
assert_allowlist_set(
    ['example.com'],
    $allowlist->hosts('https://EXAMPLE.COM./', null),
    'hosts() lowercases and strips a trailing dot from the root host'
);
assert_allowlist_set(
    ['example.com', 'www.example.com'],
    $allowlist->hosts('https://example.com/', json_encode(['pages' => [
        ['url' => 'https://WWW.Example.Com./page'],
    ]])),
    'hosts() lowercases and strips a trailing dot from a scanned page host'
);

// 4. origins() correctly omits a default port while keeping a non-default one.
assert_allowlist_set(
    ['https://example.com'],
    $allowlist->origins('https://example.com:443/', null),
    'origins() omits the default HTTPS port'
);
assert_allowlist_set(
    ['http://example.com'],
    $allowlist->origins('http://example.com:80/', null),
    'origins() omits the default HTTP port'
);
assert_allowlist_set(
    ['https://example.com:8443'],
    $allowlist->origins('https://example.com:8443/', null),
    'origins() keeps a non-default port'
);
assert_allowlist_set(
    ['https://example.com', 'https://www.example.com:8443'],
    $allowlist->origins('https://example.com/', json_encode(['pages' => [
        ['url' => 'https://www.example.com:8443/pricing'],
    ]])),
    'origins() combines the root origin with a non-default-port page origin'
);

// 5. Never throws on garbage input, and degrades to an empty array when even
// the root URL can't be parsed.
assert_allowlist([], $allowlist->hosts('not a url', null), 'hosts() returns empty array for an unparsable root URL');
assert_allowlist([], $allowlist->origins('not a url', null), 'origins() returns empty array for an unparsable root URL');
assert_allowlist_set(
    ['example.com'],
    $allowlist->hosts('https://example.com/', json_encode(['pages' => [
        ['url' => 'not a url'],
        ['url' => 123],
        ['not_url' => 'https://ignored.example.com/'],
        'not-even-an-array',
    ]])),
    'hosts() skips malformed page entries without throwing'
);

// 6. The apex/www sibling relationship works symmetrically: a root on
// `www.` allows the bare apex host (and nothing else).
$scanDataApexSiblingOfWwwRoot = json_encode([
    'pages' => [
        ['url' => 'https://example.com/pricing'],
        ['url' => 'https://tracker.example.net/pixel'],
    ],
]);
assert_allowlist_set(
    ['www.example.com', 'example.com'],
    $allowlist->hosts('https://www.example.com/', $scanDataApexSiblingOfWwwRoot),
    'hosts() allows the bare apex as the sibling of a www root, excluding third parties'
);

echo "ScanHostAllowlist tests passed.\n";
