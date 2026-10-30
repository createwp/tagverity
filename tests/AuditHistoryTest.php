<?php
// DB-backed test for the account-wide audit history. Creates its own accounts and clients,
// inserts audit_jobs rows directly, and removes them in finally.

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/AuditHistory.php';
require_once __DIR__ . '/Fakes/TestFixtures.php';

use GTMAuditor\AuditHistory;
use GTMAuditor\Tests\Fakes\TestFixtures;

try {
    $pdo = db();
} catch (Throwable $e) {
    echo "AuditHistoryTest ... skipped: no database\n";
    exit(0);
}

function assert_history(string $label, $expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ' failed. Expected: ' . json_encode($expected) . ' Actual: ' . json_encode($actual)
        );
    }
}

function assert_history_throws(string $label, string $class, string $message, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $class) || $e->getMessage() !== $message) {
            throw new RuntimeException($label . ' threw the wrong error: ' . get_class($e) . ' ' . $e->getMessage());
        }
        return;
    }
    throw new RuntimeException($label . ' did not throw');
}

$accountId = TestFixtures::createAccount($pdo);
$otherAccountId = TestFixtures::createAccount($pdo);
try {
    $clientA = TestFixtures::createClient($pdo, $accountId);
    $clientB = TestFixtures::createClient($pdo, $accountId);
    $otherClient = TestFixtures::createClient($pdo, $otherAccountId);

    $insert = $pdo->prepare(
        "INSERT INTO audit_jobs (client_id, triggered_by, status, health_score, started_at, created_at)
         VALUES (?, 'manual', ?, ?, NOW(), NOW() - INTERVAL ? MINUTE)"
    );

    // 18 jobs for client A and 12 for client B, all complete, each one minute older than the last.
    $minute = 0;
    for ($i = 0; $i < 30; $i++) {
        $clientId = $i < 18 ? $clientA : $clientB;
        $insert->execute([$clientId, 'complete', 60, $minute++]);
    }
    // One job for the other account must never show up.
    $insert->execute([$otherClient, 'complete', 60, 0]);

    $page1 = AuditHistory::page($pdo, $accountId, null, null, 1);
    assert_history('page 1 has 25 rows', 25, count($page1['rows']));
    assert_history('total counts only this account', 30, $page1['total']);
    assert_history('pages is 2', 2, $page1['pages']);
    assert_history('page echoes the page number', 1, $page1['page']);

    $page2 = AuditHistory::page($pdo, $accountId, null, null, 2);
    assert_history('page 2 has the other 5 rows', 5, count($page2['rows']));

    // Newest first: minute 0 is the most recent job and belongs to client A.
    assert_history('newest first', (int) $page1['rows'][0]['client_id'], $clientA);

    foreach (array_merge($page1['rows'], $page2['rows']) as $row) {
        if ((int) $row['client_id'] === $otherClient) {
            throw new RuntimeException('another account\'s job leaked into the history');
        }
    }

    $row = $page1['rows'][0];
    foreach (['id', 'client_id', 'client_name', 'status', 'health_score', 'triggered_by', 'created_at', 'started_at', 'completed_at'] as $key) {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException('row is missing the ' . $key . ' key');
        }
    }

    $clientOnly = AuditHistory::page($pdo, $accountId, $clientB, null, 1);
    assert_history('client filter total', 12, $clientOnly['total']);
    assert_history('client filter returns only that client', true, array_reduce(
        $clientOnly['rows'],
        fn($carry, $r) => $carry && (int) $r['client_id'] === $clientB,
        true
    ));

    // Status filter: mark two jobs of client B failed and one partial.
    $ids = $pdo->prepare("SELECT id FROM audit_jobs WHERE client_id = ? ORDER BY id");
    $ids->execute([$clientB]);
    $clientBIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    $setStatus = $pdo->prepare("UPDATE audit_jobs SET status = ?, health_score = NULL WHERE id = ?");
    $setStatus->execute(['failed', $clientBIds[0]]);
    $setStatus->execute(['failed', $clientBIds[1]]);
    $setStatus->execute(['partial', $clientBIds[2]]);

    $failed = AuditHistory::page($pdo, $accountId, null, 'failed', 1);
    assert_history('status filter total', 2, $failed['total']);
    assert_history('status filter rows are failed', true, array_reduce(
        $failed['rows'],
        fn($carry, $r) => $carry && $r['status'] === 'failed',
        true
    ));

    $partial = AuditHistory::page($pdo, $accountId, $clientB, 'partial', 1);
    assert_history('client and status filters combine', 1, $partial['total']);

    // An empty account has one empty page, not zero pages.
    $emptyAccount = TestFixtures::createAccount($pdo);
    try {
        $empty = AuditHistory::page($pdo, $emptyAccount, null, null, 1);
        assert_history('empty account has no rows', [], $empty['rows']);
        assert_history('empty account has one page', 1, $empty['pages']);
    } finally {
        TestFixtures::cleanupAccount($pdo, $emptyAccount);
    }

    // A page out of range is clamped to the last page.
    $clamped = AuditHistory::page($pdo, $accountId, null, null, 99);
    assert_history('out of range page is clamped', 2, $clamped['page']);
    assert_history('clamped page returns the last rows', 5, count($clamped['rows']));

    assert_history_throws(
        'invalid status',
        InvalidArgumentException::class,
        'invalid_status',
        fn() => AuditHistory::page($pdo, $accountId, null, 'bogus', 1)
    );
} finally {
    TestFixtures::cleanupAccount($pdo, $accountId);
    TestFixtures::cleanupAccount($pdo, $otherAccountId);
}

echo "AuditHistoryTest passed.\n";
