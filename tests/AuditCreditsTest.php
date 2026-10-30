<?php
// DB-backed test for monthly audit credit counting. Creates its own accounts and clients,
// inserts audit_jobs rows with explicit status and created_at, and removes them in finally.

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/AuditCredits.php';
require_once __DIR__ . '/Fakes/TestFixtures.php';

use GTMAuditor\AuditCredits;
use GTMAuditor\Tests\Fakes\TestFixtures;

try {
    $pdo = db();
} catch (Throwable $e) {
    echo "AuditCreditsTest ... skipped: no database\n";
    exit(0);
}

function assert_credits(string $label, $expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ' failed. Expected: ' . json_encode($expected) . ' Actual: ' . json_encode($actual)
        );
    }
}

// hasCreditsLeft needs no database, so it is checked before the DB work.
assert_credits('hasCreditsLeft: at the limit is out of credits', false, AuditCredits::hasCreditsLeft(5, 5));
assert_credits('hasCreditsLeft: one under the limit has credits', true, AuditCredits::hasCreditsLeft(4, 5));
assert_credits('hasCreditsLeft: unlimited (-1) always has credits', true, AuditCredits::hasCreditsLeft(999, -1));

$accountId = TestFixtures::createAccount($pdo);
$otherAccountId = TestFixtures::createAccount($pdo);
try {
    $clientA = TestFixtures::createClient($pdo, $accountId);
    $clientB = TestFixtures::createClient($pdo, $accountId);
    $otherClient = TestFixtures::createClient($pdo, $otherAccountId);

    // created_at is set by MySQL NOW() so it shares the clock the counter's window uses.
    $insert = $pdo->prepare(
        "INSERT INTO audit_jobs (client_id, triggered_by, status, started_at, created_at)
         VALUES (?, 'manual', ?, ?, NOW())"
    );
    $insertOld = $pdo->prepare(
        "INSERT INTO audit_jobs (client_id, triggered_by, status, started_at, created_at)
         VALUES (?, 'manual', ?, ?, NOW() - INTERVAL 40 DAY)"
    );

    // Client A: two complete jobs and one queued job (started_at is NULL until the runner picks it up).
    $insert->execute([$clientA, 'complete', date('Y-m-d H:i:s')]);
    $insert->execute([$clientA, 'complete', date('Y-m-d H:i:s')]);
    $insert->execute([$clientA, 'queued', null]);
    // Client B: one partial job. A failed job is not charged.
    $insert->execute([$clientB, 'partial', date('Y-m-d H:i:s')]);
    $insert->execute([$clientB, 'failed', date('Y-m-d H:i:s')]);

    assert_credits('account total across both clients', 4, AuditCredits::usedThisMonthForAccount($pdo, $accountId));

    // A job from 40 days ago is outside the current month and must not count.
    $insertOld->execute([$clientB, 'complete', date('Y-m-d H:i:s', strtotime('-40 days'))]);

    assert_credits('job from last month not counted', 4, AuditCredits::usedThisMonthForAccount($pdo, $accountId));

    // Another account's jobs must not count against this account.
    $insert->execute([$otherClient, 'complete', date('Y-m-d H:i:s')]);
    $insert->execute([$otherClient, 'running', date('Y-m-d H:i:s')]);

    assert_credits('other account jobs not counted', 4, AuditCredits::usedThisMonthForAccount($pdo, $accountId));
    assert_credits('other account sees only its own jobs', 2, AuditCredits::usedThisMonthForAccount($pdo, $otherAccountId));

    echo "AuditCreditsTest ... PASS\n";
} finally {
    TestFixtures::cleanupAccount($pdo, $accountId);
    TestFixtures::cleanupAccount($pdo, $otherAccountId);
}
