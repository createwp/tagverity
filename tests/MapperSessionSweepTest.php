<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/MapperSessionManager.php';
use GTMAuditor\MapperSessionManager;

$pdo = db();
$manager = new MapperSessionManager();

// Parent rows owned by this test: account -> user -> client -> site_scan.
// Every row is deleted in `finally`, children first.
$suffix = bin2hex(random_bytes(6));
$accountId = null;
$userId = null;
$clientId = null;
$siteScanId = null;

// Expiries are written in UTC, matching MapperSessionManager::createToken().
$utcAt = static function (string $modifier): string {
    return (new DateTime('now', new DateTimeZone('UTC')))->modify($modifier)->format('Y-m-d H:i:s');
};

try {
    $pdo->prepare("INSERT INTO accounts (name) VALUES (?)")
        ->execute(["sweep-test-{$suffix}"]);
    $accountId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (account_id, email, name) VALUES (?, ?, ?)")
        ->execute([$accountId, "sweep-{$suffix}@example.invalid", 'Sweep Test']);
    $userId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO clients (account_id, name) VALUES (?, ?)")
        ->execute([$accountId, "sweep-test-{$suffix}"]);
    $clientId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO site_scans (client_id, requested_by_user_id, root_url, status) VALUES (?, ?, ?, 'complete')")
        ->execute([$clientId, $userId, "https://sweep-{$suffix}.example.invalid/"]);
    $siteScanId = (int)$pdo->lastInsertId();

    // Three sessions: expired 2h ago (stale), expired 10min ago (inside the
    // 1h grace), and expiring in 10min (live).
    $insert = $pdo->prepare(
        "INSERT INTO mapper_sessions (client_id, site_scan_id, created_by_user_id, token_hash, expires_at)
         VALUES (?, ?, ?, ?, ?)"
    );
    $sessionIds = [];
    foreach (['stale' => '-2 hours', 'grace' => '-10 minutes', 'live' => '+10 minutes'] as $label => $modifier) {
        $insert->execute([$clientId, $siteScanId, $userId, bin2hex(random_bytes(32)), $utcAt($modifier)]);
        $sessionIds[$label] = (int)$pdo->lastInsertId();
    }

    // The sweep is global, so the expected count is whatever is stale right now
    // in the whole table (fresh DBs: exactly our one stale row).
    $countStale = $pdo->prepare("SELECT COUNT(*) FROM mapper_sessions WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)");
    $countStale->bindValue(1, 3600, PDO::PARAM_INT);
    $countStale->execute();
    $expectedDeleted = (int)$countStale->fetchColumn();
    if ($expectedDeleted < 1) {
        fwrite(STDERR, "Setup failed: the stale test row is not counted as expired\n");
        exit(1);
    }

    $deleted = $manager->purgeExpired($pdo, 3600);
    if ($deleted !== $expectedDeleted) {
        fwrite(STDERR, "purgeExpired() returned {$deleted}, expected {$expectedDeleted}\n");
        exit(1);
    }

    $remaining = $pdo->prepare("SELECT id FROM mapper_sessions WHERE site_scan_id = ? ORDER BY id");
    $remaining->execute([$siteScanId]);
    $remainingIds = array_map('intval', array_column($remaining->fetchAll(), 'id'));
    $expectedRemaining = [$sessionIds['grace'], $sessionIds['live']];
    if ($remainingIds !== $expectedRemaining) {
        fwrite(STDERR, "Wrong sessions kept: expected " . json_encode($expectedRemaining) . ", got " . json_encode($remainingIds) . "\n");
        fwrite(STDERR, "(a stale row must go, and the grace and live rows must stay)\n");
        exit(1);
    }

    echo "MapperSessionSweep tests passed.\n";
} finally {
    if ($siteScanId !== null) {
        $pdo->prepare("DELETE FROM mapper_sessions WHERE site_scan_id = ?")->execute([$siteScanId]);
        $pdo->prepare("DELETE FROM site_scans WHERE id = ?")->execute([$siteScanId]);
    }
    if ($clientId !== null) {
        $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$clientId]);
    }
    if ($userId !== null) {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    }
    if ($accountId !== null) {
        $pdo->prepare("DELETE FROM accounts WHERE id = ?")->execute([$accountId]);
    }
}
