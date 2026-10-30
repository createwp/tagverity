<?php
require_once __DIR__ . '/../src/SourceStatus.php';
require_once __DIR__ . '/../src/AuditCoverageView.php';

use GTMAuditor\AuditCoverageView;
use GTMAuditor\SourceStatus;

function coverageFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function coverageSources(array $overrides = []): array
{
    $sources = [
        'gtm'      => ['status' => SourceStatus::SUCCESS, 'detail' => ''],
        'ga4'      => ['status' => SourceStatus::SUCCESS, 'detail' => ''],
        'bigquery' => ['status' => SourceStatus::NOT_APPLICABLE, 'detail' => ''],
        'crawl'    => ['status' => SourceStatus::NOT_APPLICABLE, 'detail' => ''],
    ];
    foreach ($overrides as $key => $entry) {
        $sources[$key] = $entry;
    }
    return $sources;
}

function coverageRowFor(array $rows, string $source): array
{
    foreach ($rows as $row) {
        if ($row['source'] === $source) {
            return $row;
        }
    }
    coverageFail("no row for source {$source}");
    return [];
}

$view = new AuditCoverageView();

// Pre-013 jobs have no source_status: one muted row.
$nullRows = $view->describe(null);
if (count($nullRows) !== 1) {
    coverageFail('null input: expected one row, got ' . count($nullRows));
}
$nullRow = $nullRows[0];
if ($nullRow['source'] !== 'Data coverage' || $nullRow['tone'] !== 'muted'
    || $nullRow['message'] !== 'Recorded before coverage tracking was added.') {
    coverageFail('null input: unexpected row ' . json_encode($nullRow));
}

// Every source is listed in a fixed order with its marketer-facing name.
$rows = $view->describe(coverageSources());
$expectedSources = ['Google Tag Manager', 'Google Analytics 4', 'BigQuery', 'Site crawl'];
$actualSources = array_column($rows, 'source');
if ($actualSources !== $expectedSources) {
    coverageFail('source order: ' . json_encode($actualSources));
}

// Each row of the copy table, one case per status (and detail where the copy depends on it).
$cases = [
    'success' => [
        ['status' => SourceStatus::SUCCESS, 'detail' => ''],
        'ok', 'Checked successfully.',
    ],
    'not_applicable' => [
        ['status' => SourceStatus::NOT_APPLICABLE, 'detail' => ''],
        'muted', 'Not part of this audit.',
    ],
    'permission_denied' => [
        ['status' => SourceStatus::PERMISSION_DENIED, 'detail' => ''],
        'warn', "We don't have permission. Ask the account owner to grant access, then reconnect Google.",
    ],
    'authentication_failed' => [
        ['status' => SourceStatus::AUTHENTICATION_FAILED, 'detail' => ''],
        'warn', 'Your Google connection has expired. Reconnect Google to continue.',
    ],
    'quota_limited' => [
        ['status' => SourceStatus::QUOTA_LIMITED, 'detail' => ''],
        'warn', "Google asked us to slow down. We'll include this next time.",
    ],
    'timed_out' => [
        ['status' => SourceStatus::TIMED_OUT, 'detail' => ''],
        'warn', "Google didn't respond in time. Try the audit again.",
    ],
    'unavailable not_connected' => [
        ['status' => SourceStatus::UNAVAILABLE, 'detail' => 'not_connected'],
        'warn', 'Not connected yet. Connect Google to include this.',
    ],
    'insufficient_coverage target_not_selected' => [
        ['status' => SourceStatus::INSUFFICIENT_COVERAGE, 'detail' => 'target_not_selected'],
        'warn', 'Several found — choose which one to audit on the Connections page.',
    ],
    'unavailable other' => [
        ['status' => SourceStatus::UNAVAILABLE, 'detail' => 'http_500'],
        'warn', "We couldn't read this source. Try again later.",
    ],
];

foreach ($cases as $label => [$entry, $tone, $message]) {
    $row = coverageRowFor($view->describe(coverageSources(['ga4' => $entry])), 'Google Analytics 4');
    if ($row['status'] !== $entry['status']) {
        coverageFail("{$label}: status should be {$entry['status']}, got {$row['status']}");
    }
    if ($row['tone'] !== $tone) {
        coverageFail("{$label}: tone should be {$tone}, got {$row['tone']}");
    }
    if ($row['message'] !== $message) {
        coverageFail("{$label}: message mismatch, got " . json_encode($row['message']));
    }
}

// The same copy applies to the GTM row and the BigQuery/crawl rows.
$gtmRow = coverageRowFor($view->describe(coverageSources(['gtm' => ['status' => SourceStatus::PERMISSION_DENIED, 'detail' => '']])), 'Google Tag Manager');
if ($gtmRow['tone'] !== 'warn' || $gtmRow['message'] !== "We don't have permission. Ask the account owner to grant access, then reconnect Google.") {
    coverageFail('gtm permission_denied row: ' . json_encode($gtmRow));
}
$crawlRow = coverageRowFor($view->describe(coverageSources(['crawl' => ['status' => SourceStatus::NOT_APPLICABLE, 'detail' => '']])), 'Site crawl');
if ($crawlRow['tone'] !== 'muted' || $crawlRow['message'] !== 'Not part of this audit.') {
    coverageFail('crawl not_applicable row: ' . json_encode($crawlRow));
}

echo "AuditCoverageViewTest passed (" . (count($cases) + 4) . " cases)\n";
exit(0);
