<?php
// DB-backed test for Google discovery and user-selected linking. Creates its own rows and removes them.

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/AuditTargetResolver.php';
require_once __DIR__ . '/../src/DiscoverySource.php';
require_once __DIR__ . '/../src/GoogleResourceDiscovery.php';
require_once __DIR__ . '/../src/GoogleResourceLinker.php';
require_once __DIR__ . '/Fakes/TestFixtures.php';
require_once __DIR__ . '/Fakes/FakeDiscoverySource.php';

use GTMAuditor\AuditTargetResolver;
use GTMAuditor\GoogleResourceDiscovery;
use GTMAuditor\GoogleResourceLinker;
use GTMAuditor\Tests\Fakes\FakeDiscoverySource;
use GTMAuditor\Tests\Fakes\TestFixtures;

try {
    $pdo = db();
} catch (Throwable $e) {
    echo "GoogleResourceLinkerTest ... skipped: no database\n";
    exit(0);
}

function fail_linker(string $label, $expected, $actual): void {
    fwrite(STDERR, $label . " failed\nExpected: " . json_encode($expected) . "\nActual: " . json_encode($actual) . "\n");
    exit(1);
}

function assert_linker(string $label, $expected, $actual): void {
    if ($expected !== $actual) {
        fail_linker($label, $expected, $actual);
    }
}

function assert_throws_linker(string $code, callable $fn, string $label): void {
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e->getMessage() === $code) {
            return;
        }
        fail_linker($label, $code, $e->getMessage());
    }
    fail_linker($label, $code, 'no exception');
}

function count_linker(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

// Flattens a discovery list into the 'linked' flags of its child rows, in order.
function linked_flags(array $accounts, string $childKey): array {
    $flags = [];
    foreach ($accounts as $account) {
        foreach ($account[$childKey] as $child) {
            $flags[] = $child['linked'];
        }
    }
    return $flags;
}

function ga4_accounts_fixture(): array {
    return [
        ['account_id' => '1001', 'account_name' => 'Acme Ltd', 'properties' => [
            ['property_id' => '5001', 'display_name' => 'Acme Web'],
            ['property_id' => '5002', 'display_name' => 'Acme Shop'],
        ]],
        ['account_id' => '1002', 'account_name' => 'Acme Legacy', 'properties' => [
            ['property_id' => '6001', 'display_name' => 'Legacy Site'],
            ['property_id' => '6002', 'display_name' => 'Legacy Blog'],
        ]],
    ];
}

function ga4_streams_fixture(): array {
    return [
        '5001' => [['stream_id' => '9001', 'measurement_id' => 'G-AAA001', 'stream_url' => 'https://acme.test']],
        '5002' => [['stream_id' => '9002', 'measurement_id' => 'G-AAA002', 'stream_url' => 'https://shop.acme.test']],
        '6001' => [['stream_id' => '9003', 'measurement_id' => 'G-BBB001', 'stream_url' => 'https://legacy.test']],
        '6002' => [],
    ];
}

function gtm_fixture(): array {
    return [
        ['account_id' => '7001', 'account_name' => 'Acme GTM', 'containers' => [
            ['container_id' => '8001', 'public_id' => 'GTM-AAA111', 'name' => 'Acme web'],
            ['container_id' => '8002', 'public_id' => 'GTM-AAA222', 'name' => 'Acme shop'],
        ]],
    ];
}

$discovery = new GoogleResourceDiscovery();
$linker = new GoogleResourceLinker();
$resolver = new AuditTargetResolver();
$accountId = 0;
$otherAccountId = 0;

try {
    $userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
    $accountId = TestFixtures::createAccount($pdo);
    $clientId = TestFixtures::createClient($pdo, $accountId);
    $connectionOne = TestFixtures::createConnection($pdo, $accountId, 'one@example.test');
    $connectionTwo = TestFixtures::createConnection($pdo, $accountId, 'two@example.test');
    $sourceOne = new FakeDiscoverySource(ga4_accounts_fixture(), ga4_streams_fixture(), gtm_fixture());
    $sourceTwo = new FakeDiscoverySource(
        [['account_id' => '2001', 'account_name' => 'Client Other', 'properties' => [
            ['property_id' => '7001', 'display_name' => 'Other site'],
        ]]],
        ['7001' => [['stream_id' => '9100', 'measurement_id' => 'G-CCC001', 'stream_url' => 'https://other.test']]],
        []
    );

    // 1. Discovery marks nothing linked at first.
    $found = $discovery->discover($pdo, $clientId, $sourceOne);
    assert_linker('discover_ga4_account_count', 2, count($found['ga4_accounts']));
    assert_linker('discover_nothing_ga4_linked', [false, false, false, false], linked_flags($found['ga4_accounts'], 'properties'));
    assert_linker('discover_nothing_gtm_linked', [false, false], linked_flags($found['gtm_accounts'], 'containers'));
    assert_linker('discover_no_errors', ['ga4' => null, 'gtm' => null], $found['errors']);

    // 2. Linking 3 properties from both GA4 accounts plus 1 container.
    $result = $linker->link($pdo, $clientId, $connectionOne, $userId, $sourceOne,
        ['5001', '5002', '6001'], ['8001']);
    assert_linker('link_counts', ['ga4_linked' => 3, 'gtm_linked' => 1], $result);

    $ga4Rows = $pdo->prepare(
        "SELECT id, property_id, ga4_account_id, ga4_account_name, audit_target, google_connection_id
         FROM ga4_properties WHERE client_id = ? ORDER BY property_id"
    );
    $ga4Rows->execute([$clientId]);
    $ga4Rows = $ga4Rows->fetchAll(PDO::FETCH_ASSOC);
    assert_linker('linked_property_count', 3, count($ga4Rows));
    assert_linker('linked_property_ids', ['5001', '5002', '6001'], array_column($ga4Rows, 'property_id'));
    assert_linker('linked_property_account_name', 'Acme Ltd', $ga4Rows[0]['ga4_account_name']);
    assert_linker('linked_property_account_id', '1001', $ga4Rows[0]['ga4_account_id']);
    assert_linker('linked_property_connection', $connectionOne, (int) $ga4Rows[0]['google_connection_id']);
    assert_linker('three_properties_not_auto_targeted', 0, count_linker($pdo,
        "SELECT COUNT(*) FROM ga4_properties WHERE client_id = ? AND audit_target = 1", [$clientId]));

    $ga4RowFor5001 = (int) $ga4Rows[0]['id'];
    assert_linker('stream_stored_for_5001', 1, count_linker($pdo,
        "SELECT COUNT(*) FROM ga4_streams WHERE ga4_property_id = ? AND measurement_id = 'G-AAA001'", [$ga4RowFor5001]));

    $gtmRows = $pdo->prepare(
        "SELECT container_id, gtm_account_name, audit_target FROM gtm_containers WHERE client_id = ?"
    );
    $gtmRows->execute([$clientId]);
    $gtmRows = $gtmRows->fetchAll(PDO::FETCH_ASSOC);
    assert_linker('linked_container_count', 1, count($gtmRows));
    assert_linker('linked_container_account_name', 'Acme GTM', $gtmRows[0]['gtm_account_name']);
    assert_linker('single_container_auto_targeted', 1, (int) $gtmRows[0]['audit_target']);

    $found = $discovery->discover($pdo, $clientId, $sourceOne);
    assert_linker('discover_after_link_ga4', [true, true, true, false], linked_flags($found['ga4_accounts'], 'properties'));
    assert_linker('discover_after_link_gtm', [true, false], linked_flags($found['gtm_accounts'], 'containers'));

    // 3. Ids the login cannot see are rejected and nothing is written.
    assert_throws_linker('not_accessible', fn() => $linker->link($pdo, $clientId, $connectionOne, $userId,
        $sourceOne, ['6002', '9999'], []), 'mixed_ga4_not_accessible');
    assert_linker('mixed_ga4_wrote_nothing', 0, count_linker($pdo,
        "SELECT COUNT(*) FROM ga4_properties WHERE client_id = ? AND property_id = '6002'", [$clientId]));
    assert_throws_linker('not_accessible', fn() => $linker->link($pdo, $clientId, $connectionOne, $userId,
        $sourceOne, [], ['9999']), 'unknown_container_not_accessible');

    // 4. A second Google login adds its properties to the same client.
    $result = $linker->link($pdo, $clientId, $connectionTwo, $userId, $sourceTwo, ['7001'], []);
    assert_linker('second_connection_ga4_linked', 1, $result['ga4_linked']);
    assert_linker('second_connection_property_count', 4, count_linker($pdo,
        "SELECT COUNT(*) FROM ga4_properties WHERE client_id = ?", [$clientId]));
    assert_throws_linker('not_accessible', fn() => $linker->link($pdo, $clientId, $connectionTwo, $userId,
        $sourceTwo, ['5001'], []), 'second_connection_cannot_see_first_account');

    // 5. Linking an already-linked property again keeps one row and its audit target.
    $resolver->setTargets($pdo, $clientId, null, $ga4RowFor5001);
    $linker->link($pdo, $clientId, $connectionOne, $userId, $sourceOne, ['5001'], ['8002']);
    assert_linker('relink_no_duplicate', 4, count_linker($pdo,
        "SELECT COUNT(*) FROM ga4_properties WHERE client_id = ?", [$clientId]));
    assert_linker('relink_keeps_audit_target', 1, count_linker($pdo,
        "SELECT audit_target FROM ga4_properties WHERE id = ?", [$ga4RowFor5001]));
    assert_linker('relink_no_duplicate_stream', 1, count_linker($pdo,
        "SELECT COUNT(*) FROM ga4_streams WHERE ga4_property_id = ?", [$ga4RowFor5001]));
    assert_linker('second_container_keeps_first_target', 2, count_linker($pdo,
        "SELECT COUNT(*) FROM gtm_containers WHERE client_id = ?", [$clientId]));

    // 6. Unlink: other client's row is refused; own rows are removed with their dependants.
    $otherClientId = TestFixtures::createClient($pdo, $accountId);
    $foreignRow = TestFixtures::createGa4Property($pdo, $otherClientId, $connectionOne, '9100');
    assert_throws_linker('foreign_target', fn() => $linker->unlink($pdo, $clientId, 'ga4', $foreignRow),
        'unlink_foreign_ga4');
    assert_linker('foreign_row_kept', 1, count_linker($pdo,
        "SELECT COUNT(*) FROM ga4_properties WHERE id = ?", [$foreignRow]));

    $row6001 = (int) $pdo->query("SELECT id FROM ga4_properties WHERE client_id = {$clientId} AND property_id = '6001'")->fetchColumn();
    $pdo->prepare("INSERT INTO bigquery_connections (ga4_property_id, gcp_project_id, dataset_id) VALUES (?, 'tv-test-project', 'tv_test_dataset')")
        ->execute([$row6001]);
    $pdo->prepare("INSERT INTO domains (client_id, url, name) VALUES (?, 'https://legacy.test', 'TV test domain')")
        ->execute([$clientId]);
    $domainId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO domain_ga4_properties (domain_id, property_id) VALUES (?, ?)")->execute([$domainId, $row6001]);

    $linker->unlink($pdo, $clientId, 'ga4', $row6001);
    assert_linker('unlink_ga4_row_gone', 0, count_linker($pdo, "SELECT COUNT(*) FROM ga4_properties WHERE id = ?", [$row6001]));
    assert_linker('unlink_ga4_streams_gone', 0, count_linker($pdo, "SELECT COUNT(*) FROM ga4_streams WHERE ga4_property_id = ?", [$row6001]));
    assert_linker('unlink_ga4_bigquery_gone', 0, count_linker($pdo, "SELECT COUNT(*) FROM bigquery_connections WHERE ga4_property_id = ?", [$row6001]));
    assert_linker('unlink_ga4_domain_link_gone', 0, count_linker($pdo, "SELECT COUNT(*) FROM domain_ga4_properties WHERE property_id = ?", [$row6001]));

    $row8002 = (int) $pdo->query("SELECT id FROM gtm_containers WHERE client_id = {$clientId} AND container_id = '8002'")->fetchColumn();
    $pdo->prepare("INSERT INTO domain_containers (domain_id, container_id) VALUES (?, ?)")->execute([$domainId, $row8002]);
    $linker->unlink($pdo, $clientId, 'gtm', $row8002);
    assert_linker('unlink_gtm_row_gone', 0, count_linker($pdo, "SELECT COUNT(*) FROM gtm_containers WHERE id = ?", [$row8002]));
    assert_linker('unlink_gtm_domain_link_gone', 0, count_linker($pdo, "SELECT COUNT(*) FROM domain_containers WHERE container_id = ?", [$row8002]));

    // 7. Disconnect: another account's connection is refused.
    $otherAccountId = TestFixtures::createAccount($pdo);
    assert_throws_linker('foreign_target', fn() => $linker->disconnect($pdo, $otherAccountId, $connectionTwo),
        'disconnect_foreign_connection');
    assert_linker('foreign_connection_kept', 1, count_linker($pdo, "SELECT COUNT(*) FROM google_connections WHERE id = ?", [$connectionTwo]));

    // Disconnect connection one: its rows go across the account's clients, connection two's stay.
    $linker->disconnect($pdo, $accountId, $connectionOne);
    assert_linker('disconnect_connection_one_gone', 0, count_linker($pdo, "SELECT COUNT(*) FROM google_connections WHERE id = ?", [$connectionOne]));
    assert_linker('disconnect_other_client_row_gone', 0, count_linker($pdo, "SELECT COUNT(*) FROM ga4_properties WHERE id = ?", [$foreignRow]));
    assert_linker('disconnect_ga4_streams_gone', 0, count_linker($pdo, "SELECT COUNT(*) FROM ga4_streams WHERE ga4_property_id = ?", [$ga4RowFor5001]));
    assert_linker('disconnect_gtm_gone', 0, count_linker($pdo, "SELECT COUNT(*) FROM gtm_containers WHERE client_id = ?", [$clientId]));
    assert_linker('disconnect_keeps_other_connection_row', 1, count_linker($pdo,
        "SELECT COUNT(*) FROM ga4_properties WHERE client_id = ? AND property_id = '7001' AND google_connection_id = ?",
        [$clientId, $connectionTwo]));
    assert_linker('disconnect_keeps_other_connection', 1, count_linker($pdo, "SELECT COUNT(*) FROM google_connections WHERE id = ?", [$connectionTwo]));

    echo "GoogleResourceLinkerTest passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, "GoogleResourceLinkerTest error: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($accountId > 0) {
        TestFixtures::cleanupAccount($pdo, $accountId);
    }
    if ($otherAccountId > 0) {
        TestFixtures::cleanupAccount($pdo, $otherAccountId);
    }
}
