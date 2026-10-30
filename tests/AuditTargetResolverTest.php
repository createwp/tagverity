<?php
// DB-backed test for the audit target resolver. Creates its own rows and removes them.

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/AuditTargetResolver.php';
require_once __DIR__ . '/Fakes/TestFixtures.php';

use GTMAuditor\AuditTargetResolver;
use GTMAuditor\Tests\Fakes\TestFixtures;

try {
    $pdo = db();
} catch (Throwable $e) {
    echo "AuditTargetResolverTest ... skipped: no database\n";
    exit(0);
}

function fail_resolver(string $label, $expected, $actual): void {
    fwrite(STDERR, $label . " failed\nExpected: " . json_encode($expected) . "\nActual: " . json_encode($actual) . "\n");
    exit(1);
}

function assert_resolver(string $label, $expected, $actual): void {
    if ($expected !== $actual) {
        fail_resolver($label, $expected, $actual);
    }
}

function assert_throws_foreign_target(callable $fn, string $label): void {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        if ($e->getMessage() === 'foreign_target') {
            return;
        }
        fail_resolver($label, 'foreign_target', $e->getMessage());
    }
    fail_resolver($label, 'foreign_target exception', 'no exception');
}

$resolver = new AuditTargetResolver();
$accountId = 0;
$otherAccountId = 0;

try {
    $accountId = TestFixtures::createAccount($pdo);
    $clientId = TestFixtures::createClient($pdo, $accountId);
    $connectionId = TestFixtures::createConnection($pdo, $accountId, 'owner@example.test');
    $gtmA = TestFixtures::createGtmContainer($pdo, $clientId, $connectionId, '101');
    $gtmB = TestFixtures::createGtmContainer($pdo, $clientId, $connectionId, '102');
    $ga4A = TestFixtures::createGa4Property($pdo, $clientId, $connectionId, '111');
    $ga4B = TestFixtures::createGa4Property($pdo, $clientId, $connectionId, '222');

    // Two of each, nothing chosen.
    $target = $resolver->resolve($pdo, $clientId);
    assert_resolver('no_targets_gtm_reason', 'target_not_selected', $target['gtm_reason']);
    assert_resolver('no_targets_ga4_reason', 'target_not_selected', $target['ga4_reason']);
    assert_resolver('no_targets_gtm', null, $target['gtm']);
    assert_resolver('no_targets_ga4', null, $target['ga4_property_id']);
    assert_resolver('no_targets_connection', null, $target['connection']);

    // Choose the second container and the second property.
    $resolver->setTargets($pdo, $clientId, $gtmB, $ga4B);
    $target = $resolver->resolve($pdo, $clientId);
    assert_resolver('chosen_gtm_id', $gtmB, $target['gtm']['id']);
    assert_resolver('chosen_gtm_account_id', true, is_string($target['gtm']['account_id']) && $target['gtm']['account_id'] !== '');
    assert_resolver('chosen_gtm_container_id', '102', $target['gtm']['container_id']);
    assert_resolver('chosen_gtm_reason', '', $target['gtm_reason']);
    assert_resolver('chosen_ga4_property_id', '222', $target['ga4_property_id']);
    assert_resolver('chosen_ga4_row_id', $ga4B, $target['ga4_row_id']);
    assert_resolver('chosen_ga4_reason', '', $target['ga4_reason']);
    assert_resolver('chosen_connection_id', $connectionId, (int) $target['connection']['id']);
    assert_resolver('same_connection_no_ga4_connection', false, array_key_exists('ga4_connection', $target));

    // Choosing a property alone clears the other one.
    $resolver->setTargets($pdo, $clientId, null, $ga4A);
    $target = $resolver->resolve($pdo, $clientId);
    assert_resolver('ga4_only_gtm_null', null, $target['gtm']);
    assert_resolver('ga4_only_gtm_reason', 'target_not_selected', $target['gtm_reason']);
    assert_resolver('ga4_only_property', '111', $target['ga4_property_id']);
    assert_resolver('ga4_only_connection', $connectionId, (int) $target['connection']['id']);

    // A second client with a single property and no target: the only row is chosen.
    $singleClientId = TestFixtures::createClient($pdo, $accountId);
    $singlePropertyId = TestFixtures::createGa4Property($pdo, $singleClientId, $connectionId, '333');
    $target = $resolver->resolve($pdo, $singleClientId);
    assert_resolver('single_property_chosen', '333', $target['ga4_property_id']);
    assert_resolver('single_property_row', $singlePropertyId, $target['ga4_row_id']);
    assert_resolver('single_property_reason', '', $target['ga4_reason']);
    assert_resolver('single_property_gtm_reason', 'not_connected', $target['gtm_reason']);

    // A client with nothing connected.
    $emptyClientId = TestFixtures::createClient($pdo, $accountId);
    $target = $resolver->resolve($pdo, $emptyClientId);
    assert_resolver('empty_gtm_reason', 'not_connected', $target['gtm_reason']);
    assert_resolver('empty_ga4_reason', 'not_connected', $target['ga4_reason']);
    assert_resolver('empty_connection', null, $target['connection']);

    // Targets belonging to another client are refused.
    $otherClientId = TestFixtures::createClient($pdo, $accountId);
    $otherGtm = TestFixtures::createGtmContainer($pdo, $otherClientId, $connectionId, '901');
    $otherGa4 = TestFixtures::createGa4Property($pdo, $otherClientId, $connectionId, '901');
    assert_throws_foreign_target(
        fn() => $resolver->setTargets($pdo, $clientId, $otherGtm, null),
        'foreign_gtm_rejected'
    );
    assert_throws_foreign_target(
        fn() => $resolver->setTargets($pdo, $clientId, null, $otherGa4),
        'foreign_ga4_rejected'
    );
    // The refused call must not have changed the earlier selection.
    $target = $resolver->resolve($pdo, $clientId);
    assert_resolver('foreign_rejected_keeps_ga4', '111', $target['ga4_property_id']);

    // GTM and GA4 from different connections: GTM connection is primary, GA4 one is added.
    $splitClientId = TestFixtures::createClient($pdo, $accountId);
    $gtmConnectionId = TestFixtures::createConnection($pdo, $accountId, 'gtm@example.test');
    $ga4ConnectionId = TestFixtures::createConnection($pdo, $accountId, 'ga4@example.test');
    $splitGtm = TestFixtures::createGtmContainer($pdo, $splitClientId, $gtmConnectionId, '701', 1);
    TestFixtures::createGa4Property($pdo, $splitClientId, $ga4ConnectionId, '701', 1);
    $target = $resolver->resolve($pdo, $splitClientId);
    assert_resolver('split_connection', $gtmConnectionId, (int) $target['connection']['id']);
    assert_resolver('split_ga4_connection', $ga4ConnectionId, (int) $target['ga4_connection']['id']);
    assert_resolver('split_gtm_id', $splitGtm, $target['gtm']['id']);

    // A GTM row whose connection belongs to another account is not usable.
    $otherAccountId = TestFixtures::createAccount($pdo);
    $foreignConnectionId = TestFixtures::createConnection($pdo, $otherAccountId, 'foreign@example.test');
    $crossClientId = TestFixtures::createClient($pdo, $accountId);
    TestFixtures::createGtmContainer($pdo, $crossClientId, $foreignConnectionId, '801', 1);
    $target = $resolver->resolve($pdo, $crossClientId);
    assert_resolver('foreign_connection_not_used', null, $target['connection']);

    echo "AuditTargetResolverTest ... ok\n";
} finally {
    if ($accountId > 0) {
        TestFixtures::cleanupAccount($pdo, $accountId);
    }
    if ($otherAccountId > 0) {
        TestFixtures::cleanupAccount($pdo, $otherAccountId);
    }
}
