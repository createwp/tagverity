<?php
require_once __DIR__ . '/../src/AuditDataCollector.php';
require_once __DIR__ . '/Fakes/FakeAuditDataSource.php';

use GTMAuditor\AuditDataCollector;
use GTMAuditor\Tests\Fakes\FakeAuditDataSource;

function assert_collector_value($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " failed\nExpected: " . json_encode($expected) . "\nActual: " . json_encode($actual) . "\n");
        exit(1);
    }
}

$gtmTarget = [
    'account_id'   => '123456',
    'container_id' => '7890',
    'public_id'    => 'GTM-ABC123',
];
$ga4Rows = [
    'landingPage'   => [['landingPage' => '/', 'sessions' => 40]],
    '_total'        => [['sessions' => 120]],
    'transactionId' => [],
    'pageLocation'  => [['pageLocation' => 'https://example.com/', 'screenPageViews' => 40]],
    'hostName'      => [['hostName' => 'example.com', 'sessions' => 120]],
];
$containerVersion = [
    'tags'      => [['tagId' => '1', 'name' => 'GA4 config'], ['tagId' => '2', 'name' => 'Purchase']],
    'triggers'  => [],
    'variables' => [],
    'container' => ['account_id' => '123456', 'container_id' => '7890'],
];

$collector = new AuditDataCollector();

// test_all_sources_succeed
$result = $collector->collect(new FakeAuditDataSource($containerVersion, $ga4Rows), [
    'gtm'             => $gtmTarget,
    'gtm_reason'      => '',
    'ga4_property_id' => 'properties/111',
    'ga4_reason'      => '',
]);
assert_collector_value('success', $result['source_status']['gtm']['status'], 'all succeed: gtm status');
assert_collector_value('success', $result['source_status']['ga4']['status'], 'all succeed: ga4 status');
assert_collector_value(2, count($result['gtm']['tags']), 'all succeed: gtm tag count');
assert_collector_value(true, is_int($result['ga4']['total_sessions']), 'all succeed: total_sessions is int');
assert_collector_value(120, $result['ga4']['total_sessions'], 'all succeed: total_sessions value');
assert_collector_value(
    ['landing_page', 'total_sessions', 'purchase_transactions', 'page_location', 'hostname'],
    array_keys($result['ga4']),
    'all succeed: ga4 keys'
);
assert_collector_value(['status' => 'not_applicable', 'detail' => ''], $result['source_status']['bigquery'], 'bigquery not applicable');
assert_collector_value(['status' => 'not_applicable', 'detail' => ''], $result['source_status']['crawl'], 'crawl not applicable');

// test_gtm_permission_denied
$result = $collector->collect(new FakeAuditDataSource($containerVersion, $ga4Rows, new \RuntimeException('forbidden', 403)), [
    'gtm'             => $gtmTarget,
    'gtm_reason'      => '',
    'ga4_property_id' => '111',
    'ga4_reason'      => '',
]);
assert_collector_value('permission_denied', $result['source_status']['gtm']['status'], 'gtm 403: status');
assert_collector_value([], $result['gtm'], 'gtm 403: data empty');
assert_collector_value('success', $result['source_status']['ga4']['status'], 'gtm 403: ga4 still success');
assert_collector_value(120, $result['ga4']['total_sessions'], 'gtm 403: ga4 data kept');

// test_no_ga4_target
$result = $collector->collect(new FakeAuditDataSource($containerVersion, $ga4Rows), [
    'gtm'             => $gtmTarget,
    'gtm_reason'      => '',
    'ga4_property_id' => null,
    'ga4_reason'      => 'target_not_selected',
]);
assert_collector_value('insufficient_coverage', $result['source_status']['ga4']['status'], 'no ga4 target: status');
assert_collector_value('target_not_selected', $result['source_status']['ga4']['detail'], 'no ga4 target: detail');
assert_collector_value([], $result['ga4'], 'no ga4 target: data empty');

$result = $collector->collect(new FakeAuditDataSource($containerVersion, $ga4Rows), [
    'gtm'             => $gtmTarget,
    'gtm_reason'      => '',
    'ga4_property_id' => null,
    'ga4_reason'      => 'not_connected',
]);
assert_collector_value('unavailable', $result['source_status']['ga4']['status'], 'ga4 not connected: status');
assert_collector_value('not_connected', $result['source_status']['ga4']['detail'], 'ga4 not connected: detail');

// GTM target null maps its reason
$result = $collector->collect(new FakeAuditDataSource($containerVersion, $ga4Rows), [
    'gtm'             => null,
    'gtm_reason'      => 'target_not_selected',
    'ga4_property_id' => '111',
    'ga4_reason'      => '',
]);
assert_collector_value('insufficient_coverage', $result['source_status']['gtm']['status'], 'gtm target null: status');
assert_collector_value('target_not_selected', $result['source_status']['gtm']['detail'], 'gtm target null: detail');

// Any GA4 query failure fails the whole GA4 source
$result = $collector->collect(new FakeAuditDataSource($containerVersion, $ga4Rows, null, new \RuntimeException('quota', 429)), [
    'gtm'             => $gtmTarget,
    'gtm_reason'      => '',
    'ga4_property_id' => '111',
    'ga4_reason'      => '',
]);
assert_collector_value('quota_limited', $result['source_status']['ga4']['status'], 'ga4 429: status');
assert_collector_value('quota', $result['source_status']['ga4']['detail'], 'ga4 429: detail');
assert_collector_value([], $result['ga4'], 'ga4 429: data empty');
assert_collector_value('success', $result['source_status']['gtm']['status'], 'ga4 429: gtm still success');

// test_zero_purchases_is_success
$result = $collector->collect(new FakeAuditDataSource($containerVersion, $ga4Rows), [
    'gtm'             => $gtmTarget,
    'gtm_reason'      => '',
    'ga4_property_id' => '111',
    'ga4_reason'      => '',
]);
assert_collector_value([], $result['ga4']['purchase_transactions'], 'zero purchases: rows empty');
assert_collector_value('success', $result['source_status']['ga4']['status'], 'zero purchases: status success');

echo "AuditDataCollectorTest passed\n";
exit(0);
