<?php
// DB-backed test for the audit job runner. Each case creates its own rows, queues one
// audit job, processes it, and removes every row it created in finally.

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/AuditJobRunner.php';
require_once __DIR__ . '/Fakes/FakeAuditDataSource.php';
require_once __DIR__ . '/Fakes/TestFixtures.php';

use GTMAuditor\AuditJobRunner;
use GTMAuditor\Tests\Fakes\FakeAuditDataSource;
use GTMAuditor\Tests\Fakes\TestFixtures;

try {
    $pdo = db();
} catch (Throwable $e) {
    echo "AuditJobRunnerTest ... skipped: no database\n";
    exit(0);
}

// Assertions throw so that finally blocks still run and remove the rows.
function assert_runner(string $label, $expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ' failed. Expected: ' . json_encode($expected) . ' Actual: ' . json_encode($actual)
        );
    }
}

// processNext() takes the oldest queued job, so the queue must hold nothing but our job.
// Exits before any row is created, so nothing is left behind.
function skip_if_foreign_queue(\PDO $pdo): void
{
    $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_jobs WHERE status = 'queued'")->fetchColumn();
    if ($count > 0) {
        echo "AuditJobRunnerTest ... skipped: {$count} other queued audit job(s) exist, "
            . "so processNext() would act on them\n";
        exit(0);
    }
}

// Queues one job for the client, runs processNext(), and returns the stored job row.
function run_queued_job(\PDO $pdo, AuditJobRunner $runner, int $clientId): array
{
    $insert = $pdo->prepare("INSERT INTO audit_jobs (client_id, triggered_by, status) VALUES (?, 'manual', 'queued')");
    $insert->execute([$clientId]);
    $jobId = (int) $pdo->lastInsertId();

    assert_runner('processNext returns our job', $jobId, $runner->processNext());

    $stmt = $pdo->prepare("SELECT * FROM audit_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    return $stmt->fetch();
}

function decoded_source_status(array $jobRow): array
{
    $decoded = json_decode((string) $jobRow['source_status'], true);
    return is_array($decoded) ? $decoded : [];
}

$containerVersion = [
    'tags'      => [['tagId' => '1', 'name' => 'GA4 config'], ['tagId' => '2', 'name' => 'Purchase']],
    'triggers'  => [],
    'variables' => [],
    'container' => ['account_id' => '123456', 'container_id' => '7890'],
];
$ga4Rows = [
    'landingPage'   => [['landingPage' => '/', 'sessions' => 40]],
    '_total'        => [['sessions' => 120]],
    'transactionId' => [],
    'pageLocation'  => [['pageLocation' => 'https://example.com/', 'screenPageViews' => 40]],
    'hostName'      => [['hostName' => 'example.com', 'sessions' => 120]],
];

function fake_factory(array $containerVersion, array $ga4Rows): callable
{
    return static fn(array $connectionRow) => new FakeAuditDataSource($containerVersion, $ga4Rows);
}

// Case 1: GTM and GA4 both selected on one connection; every source succeeds.
function case_happy_path(\PDO $pdo, array $containerVersion, array $ga4Rows): void
{
    skip_if_foreign_queue($pdo);
    $accountId = 0;
    try {
        $accountId = TestFixtures::createAccount($pdo);
        $clientId = TestFixtures::createClient($pdo, $accountId);
        $connectionId = TestFixtures::createConnection($pdo, $accountId, 'owner@example.test');
        TestFixtures::createGtmContainer($pdo, $clientId, $connectionId, '101', 1);
        TestFixtures::createGa4Property($pdo, $clientId, $connectionId, '111', 1);

        $runner = new AuditJobRunner(fake_factory($containerVersion, $ga4Rows));
        $job = run_queued_job($pdo, $runner, $clientId);

        assert_runner('happy: status', 'complete', $job['status']);
        assert_runner('happy: health score set', false, $job['health_score'] === null);
        $sourceStatus = decoded_source_status($job);
        assert_runner('happy: rule set version', '2026.10.1', $sourceStatus['rule_set_version'] ?? null);
        assert_runner('happy: gtm status', 'success', $sourceStatus['gtm']['status'] ?? null);
        assert_runner('happy: ga4 status', 'success', $sourceStatus['ga4']['status'] ?? null);
        assert_runner('happy: no raw data at top level', false, array_key_exists('tags', $sourceStatus));
        $keys = array_keys($sourceStatus);
        sort($keys);
        $expectedKeys = [
            'bigquery', 'crawl', 'ga4', 'ga4_property_id', 'ga4_window_days', 'gtm',
            'gtm_public_id', 'rule_set_version', 'scoring_version',
        ];
        assert_runner('happy: source_status key set', $expectedKeys, $keys);
        assert_runner('happy: source_status stored compactly', true, strlen((string) $job['source_status']) < 2000);
    } finally {
        if ($accountId > 0) {
            TestFixtures::cleanupAccount($pdo, $accountId);
        }
    }
}

// Case 2: the factory throws an authentication error; both sources record it.
function case_factory_throws(\PDO $pdo): void
{
    skip_if_foreign_queue($pdo);
    $accountId = 0;
    try {
        $accountId = TestFixtures::createAccount($pdo);
        $clientId = TestFixtures::createClient($pdo, $accountId);
        $connectionId = TestFixtures::createConnection($pdo, $accountId, 'owner@example.test');
        TestFixtures::createGtmContainer($pdo, $clientId, $connectionId, '102', 1);
        TestFixtures::createGa4Property($pdo, $clientId, $connectionId, '112', 1);

        $runner = new AuditJobRunner(static function (array $connectionRow): FakeAuditDataSource {
            throw new RuntimeException('authentication_failed', 401);
        });
        $job = run_queued_job($pdo, $runner, $clientId);

        assert_runner('auth: status', 'failed', $job['status']);
        assert_runner('auth: health score null', null, $job['health_score']);
        $sourceStatus = decoded_source_status($job);
        assert_runner('auth: gtm status', 'authentication_failed', $sourceStatus['gtm']['status'] ?? null);
        assert_runner('auth: ga4 status', 'authentication_failed', $sourceStatus['ga4']['status'] ?? null);
        assert_runner('auth: gtm detail', 'authentication_failed', $sourceStatus['gtm']['detail'] ?? null);
    } finally {
        if ($accountId > 0) {
            TestFixtures::cleanupAccount($pdo, $accountId);
        }
    }
}

// Case 3: GTM selected, two GA4 properties and none selected; GA4 has no coverage.
function case_ga4_not_selected(\PDO $pdo, array $containerVersion, array $ga4Rows): void
{
    skip_if_foreign_queue($pdo);
    $accountId = 0;
    try {
        $accountId = TestFixtures::createAccount($pdo);
        $clientId = TestFixtures::createClient($pdo, $accountId);
        $connectionId = TestFixtures::createConnection($pdo, $accountId, 'owner@example.test');
        TestFixtures::createGtmContainer($pdo, $clientId, $connectionId, '103', 1);
        TestFixtures::createGa4Property($pdo, $clientId, $connectionId, '113', 0);
        TestFixtures::createGa4Property($pdo, $clientId, $connectionId, '114', 0);

        $runner = new AuditJobRunner(fake_factory($containerVersion, $ga4Rows));
        $job = run_queued_job($pdo, $runner, $clientId);

        assert_runner('partial: status', 'partial', $job['status']);
        assert_runner('partial: health score null', null, $job['health_score']);
        $sourceStatus = decoded_source_status($job);
        assert_runner('partial: ga4 status', 'insufficient_coverage', $sourceStatus['ga4']['status'] ?? null);
        assert_runner('partial: gtm status', 'success', $sourceStatus['gtm']['status'] ?? null);
    } finally {
        if ($accountId > 0) {
            TestFixtures::cleanupAccount($pdo, $accountId);
        }
    }
}

// Case 4: GTM and GA4 on different Google logins; the factory is called once per connection.
function case_different_connections(\PDO $pdo, array $containerVersion, array $ga4Rows): void
{
    skip_if_foreign_queue($pdo);
    $accountId = 0;
    try {
        $accountId = TestFixtures::createAccount($pdo);
        $clientId = TestFixtures::createClient($pdo, $accountId);
        $gtmConnectionId = TestFixtures::createConnection($pdo, $accountId, 'gtm@example.test');
        $ga4ConnectionId = TestFixtures::createConnection($pdo, $accountId, 'ga4@example.test');
        TestFixtures::createGtmContainer($pdo, $clientId, $gtmConnectionId, '104', 1);
        TestFixtures::createGa4Property($pdo, $clientId, $ga4ConnectionId, '115', 1);

        $calls = [];
        $runner = new AuditJobRunner(static function (array $connectionRow) use (&$calls, $containerVersion, $ga4Rows) {
            $calls[] = (int) $connectionRow['id'];
            return new FakeAuditDataSource($containerVersion, $ga4Rows);
        });
        $job = run_queued_job($pdo, $runner, $clientId);

        assert_runner('two logins: factory call count', 2, count($calls));
        assert_runner('two logins: connection order', [$gtmConnectionId, $ga4ConnectionId], $calls);
        assert_runner('two logins: status', 'complete', $job['status']);
        assert_runner('two logins: health score set', false, $job['health_score'] === null);
    } finally {
        if ($accountId > 0) {
            TestFixtures::cleanupAccount($pdo, $accountId);
        }
    }
}

// Case 5: the GTM container's connection row is not usable for this client (here it belongs to
// another account). The GTM source is recorded as connection_missing and the factory is never called.
function case_connection_row_missing(\PDO $pdo, array $containerVersion, array $ga4Rows): void
{
    skip_if_foreign_queue($pdo);
    $accountId = 0;
    $otherAccountId = 0;
    try {
        $accountId = TestFixtures::createAccount($pdo);
        $otherAccountId = TestFixtures::createAccount($pdo);
        $clientId = TestFixtures::createClient($pdo, $accountId);
        $foreignConnectionId = TestFixtures::createConnection($pdo, $otherAccountId, 'foreign@example.test');
        TestFixtures::createGtmContainer($pdo, $clientId, $foreignConnectionId, '105', 1);

        $calls = 0;
        $runner = new AuditJobRunner(static function (array $connectionRow) use (&$calls, $containerVersion, $ga4Rows) {
            $calls++;
            return new FakeAuditDataSource($containerVersion, $ga4Rows);
        });
        $job = run_queued_job($pdo, $runner, $clientId);

        assert_runner('missing connection: factory never called', 0, $calls);
        assert_runner('missing connection: status', 'failed', $job['status']);
        $sourceStatus = decoded_source_status($job);
        assert_runner('missing connection: gtm status', 'unavailable', $sourceStatus['gtm']['status'] ?? null);
        assert_runner('missing connection: gtm detail', 'connection_missing', $sourceStatus['gtm']['detail'] ?? null);
    } finally {
        if ($accountId > 0) {
            TestFixtures::cleanupAccount($pdo, $accountId);
        }
        if ($otherAccountId > 0) {
            TestFixtures::cleanupAccount($pdo, $otherAccountId);
        }
    }
}

try {
    case_happy_path($pdo, $containerVersion, $ga4Rows);
    case_factory_throws($pdo);
    case_ga4_not_selected($pdo, $containerVersion, $ga4Rows);
    case_different_connections($pdo, $containerVersion, $ga4Rows);
    case_connection_row_missing($pdo, $containerVersion, $ga4Rows);
} catch (Throwable $e) {
    fwrite(STDERR, 'AuditJobRunnerTest ' . $e->getMessage() . "\n");
    exit(1);
}

echo "AuditJobRunnerTest passed.\n";
