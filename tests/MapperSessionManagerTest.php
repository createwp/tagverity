<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/MapperSessionManager.php';
use GTMAuditor\MapperSessionManager;

$pdo = db();
$scan = $pdo->query("SELECT id, client_id, root_url FROM site_scans WHERE status = 'complete' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$scan) { echo "MapperSessionManager tests skipped: no completed site scan.\n"; exit(0); }
$user = $pdo->query("SELECT id FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$manager = new MapperSessionManager();

// The origin a legitimate caller's browser would send: scheme + host, lowercased,
// matching the canonical form validateToken() builds from the scan's root_url.
$scheme = strtolower((string)parse_url($scan['root_url'], PHP_URL_SCHEME));
$host = strtolower((string)parse_url($scan['root_url'], PHP_URL_HOST));
$origin = "{$scheme}://{$host}";

// 1. Happy path
$tokenA = $manager->createToken($pdo, (int)$scan['client_id'], (int)$scan['id'], (int)$user['id']);
$validated = $manager->validateToken($pdo, $tokenA['token'], $origin);
if ($validated['client_id'] !== (int)$scan['client_id']) { fwrite(STDERR, "Happy path failed\n"); exit(1); }

// 2. Unknown token
try {
    $manager->validateToken($pdo, 'not-a-real-token', $origin);
    fwrite(STDERR, "Unknown token should have thrown\n"); exit(1);
} catch (DomainException $e) {
    if ($e->getMessage() !== 'invalid_or_expired_token') { fwrite(STDERR, "Wrong message for unknown token\n"); exit(1); }
}

// 3. Origin mismatch does not consume the token
$tokenB = $manager->createToken($pdo, (int)$scan['client_id'], (int)$scan['id'], (int)$user['id']);
try {
    $manager->validateToken($pdo, $tokenB['token'], 'https://attacker.example');
    fwrite(STDERR, "Origin mismatch should have thrown\n"); exit(1);
} catch (DomainException $e) {
    if ($e->getMessage() !== 'origin_mismatch') { fwrite(STDERR, "Wrong message for origin mismatch\n"); exit(1); }
}
$manager->validateToken($pdo, $tokenB['token'], $origin); // still redeemable — the failed attempt didn't burn it

// 4. Issuing a second session does not invalidate an earlier, unused one
$tokenE = $manager->createToken($pdo, (int)$scan['client_id'], (int)$scan['id'], (int)$user['id']);
$tokenF = $manager->createToken($pdo, (int)$scan['client_id'], (int)$scan['id'], (int)$user['id']); // issued after E, before E is used
$manager->validateToken($pdo, $tokenE['token'], $origin); // must still succeed

// 5. Expired token
$tokenG = $manager->createToken($pdo, (int)$scan['client_id'], (int)$scan['id'], (int)$user['id']);
$pastTimestamp = (new DateTime('now', new DateTimeZone('UTC')))->modify('-1 minute')->format('Y-m-d H:i:s');
$pdo->prepare("UPDATE mapper_sessions SET expires_at = ? WHERE token_hash = ?")
    ->execute([$pastTimestamp, hash('sha256', $tokenG['token'])]);
try {
    $manager->validateToken($pdo, $tokenG['token'], $origin);
    fwrite(STDERR, "Expired token should have thrown\n"); exit(1);
} catch (DomainException $e) {
    if ($e->getMessage() !== 'invalid_or_expired_token') { fwrite(STDERR, "Wrong message for expired token\n"); exit(1); }
}

// 6. A concurrent validateToken() call for the SAME token is blocked by the
//    row lock (SELECT ... FOR UPDATE), not just allowed through twice. Prove
//    this with a second, independent connection that opens a transaction and
//    holds the lock on the token's row without committing, then show that
//    validateToken() on the original connection genuinely blocks on that lock
//    (a short session-level lock-wait timeout turns the block into a
//    PDOException rather than hanging the test) — and that releasing the
//    lock afterwards leaves the token intact and still redeemable.
$tokenH = $manager->createToken($pdo, (int)$scan['client_id'], (int)$scan['id'], (int)$user['id']);
$tokenHHash = hash('sha256', $tokenH['token']);

$pdo2 = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);
$pdo2->beginTransaction();
$pdo2->prepare("SELECT id FROM mapper_sessions WHERE token_hash = ? FOR UPDATE")->execute([$tokenHHash]);
// $pdo2 now holds the row lock open, uncommitted.

$pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
try {
    $manager->validateToken($pdo, $tokenH['token'], $origin);
    fwrite(STDERR, "Concurrent validateToken() for the same token should have blocked on the lock\n"); exit(1);
} catch (DomainException $e) {
    fwrite(STDERR, "Expected a lock-wait timeout (PDOException), got a DomainException instead — the row lock did not actually block\n"); exit(1);
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'Lock wait timeout') === false) {
        fwrite(STDERR, "Expected a lock-wait timeout, got a different PDOException: " . $e->getMessage() . "\n"); exit(1);
    }
}

$pdo2->rollBack(); // release the held lock without consuming the token
$pdo2 = null;

// The aborted concurrent attempt didn't corrupt anything — the token is
// still there, unconsumed, and validates normally now that the lock is free.
$manager->validateToken($pdo, $tokenH['token'], $origin);

echo "MapperSessionManager tests passed.\n";
