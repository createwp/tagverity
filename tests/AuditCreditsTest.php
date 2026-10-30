<?php
// DB-backed test for monthly audit credit counting. Creates its own account and client,
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

$accountId = TestFixtures::createAccount($pdo);
try {
    $clientId = TestFixtures::createClient($pdo, $accountId);

    // created_at is set by MySQL NOW() so it shares the clock the counter's window uses.
    $insert = $pdo->prepare(
        "INSERT INTO audit_jobs (client_id, triggered_by, status, started_at, created_at)
         VALUES (?, 'manual', ?, ?, NOW())"
    );
    $insertOld = $pdo->prepare(
        "INSERT INTO audit_jobs (client_id, triggered_by, status, started_at, created_at)
         VALUES (?, 'manual', ?, ?, NOW() - INTERVAL 40 DAY)"
    );

    // Queued job: started_at is NULL until the runner picks it up, so it must still count.
    $insert->execute([$clientId, 'queued', null]);
    // Complete and partial jobs are charged; failed jobs are not.
    $insert->execute([$clientId, 'complete', date('Y-m-d H:i:s')]);
    $insert->execute([$clientId, 'partial', date('Y-m-d H:i:s')]);
    $insert->execute([$clientId, 'failed', date('Y-m-d H:i:s')]);

    assert_credits('queued + complete + partial this month, failed excluded', 3, AuditCredits::usedThisMonth($pdo, $clientId));

    // A complete job from 40 days ago is outside the current month and must not count.
    $insertOld->execute([$clientId, 'complete', date('Y-m-d H:i:s', strtotime('-40 days'))]);

    assert_credits('job from last month not counted', 3, AuditCredits::usedThisMonth($pdo, $clientId));

    echo "AuditCreditsTest ... PASS\n";
} finally {
    TestFixtures::cleanupAccount($pdo, $accountId);
}
