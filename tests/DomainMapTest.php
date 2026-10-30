<?php
// DB-backed tests for DomainMap: host normalising, discovery, ownership answers and manual adds.
// Creates its own rows and removes them in finally.

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/DomainMap.php';
require_once __DIR__ . '/Fakes/TestFixtures.php';

use GTMAuditor\DomainMap;
use GTMAuditor\Tests\Fakes\TestFixtures;

try {
    $pdo = db();
} catch (Throwable $e) {
    echo "DomainMapTest ... skipped: no database\n";
    exit(0);
}

function check_map(string $label, $expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' failed. Expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

function expect_map_throw(string $message, callable $fn): void {
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e->getMessage() === $message) {
            return;
        }
        throw new RuntimeException("Expected exception '{$message}', got '" . $e->getMessage() . "'");
    }
    throw new RuntimeException("Expected exception '{$message}', but nothing was thrown");
}

function domain_id_by_host(PDO $pdo, int $clientId, string $host): int {
    $stmt = $pdo->prepare("SELECT id FROM domains WHERE client_id = ? AND host = ?");
    $stmt->execute([$clientId, $host]);
    return (int)$stmt->fetchColumn();
}

// ─── normaliseHost (no database needed) ──────────────────────────────────────
check_map('full url with port, path and query', 'www.example.com', DomainMap::normaliseHost('https://WWW.Example.com:8443/a?b'));
check_map('bare host with path', 'example.org', DomainMap::normaliseHost('example.org/path'));
check_map('trailing dot stripped', 'example.org', DomainMap::normaliseHost('Example.org.'));
check_map('localhost rejected', null, DomainMap::normaliseHost('localhost'));
check_map('ipv4 literal rejected', null, DomainMap::normaliseHost('192.168.1.1'));
check_map('ipv6 literal rejected', null, DomainMap::normaliseHost('https://[::1]/'));
check_map('empty rejected', null, DomainMap::normaliseHost(''));
check_map('over 253 characters rejected', null, DomainMap::normaliseHost(str_repeat('a', 250) . '.com'));

// ─── Discovery, confirmation and manual adds (DB) ────────────────────────────
$suffix = bin2hex(random_bytes(4));
$streamHost = "shop.dm-{$suffix}.example.test";
$scanHost = "www.dm-{$suffix}.example.test";
$oldScanHost = "old-{$suffix}.example.test";
$accountId = 0;
$exitCode = 0;

try {
    $accountId = TestFixtures::createAccount($pdo);
    $clientId = TestFixtures::createClient($pdo, $accountId);
    $otherClientId = TestFixtures::createClient($pdo, $accountId);
    $connectionId = TestFixtures::createConnection($pdo, $accountId);

    $pdo->prepare("INSERT INTO users (account_id, email, name) VALUES (?, ?, 'DomainMap test')")
        ->execute([$accountId, "domainmap-{$suffix}@example.invalid"]);
    $userId = (int)$pdo->lastInsertId();

    $propertyRowId = TestFixtures::createGa4Property($pdo, $clientId, $connectionId, "dm{$suffix}");
    $pdo->prepare(
        "INSERT INTO ga4_streams (ga4_property_id, stream_id, measurement_id, stream_url)
         VALUES (?, 's1', 'G-TVTEST', ?)"
    )->execute([$propertyRowId, "https://{$streamHost}/checkout"]);

    // The older scan must be ignored: only the latest scan (highest id) counts.
    $scanInsert = $pdo->prepare(
        "INSERT INTO site_scans (client_id, requested_by_user_id, root_url, status) VALUES (?, ?, ?, 'complete')"
    );
    $scanInsert->execute([$clientId, $userId, "https://{$oldScanHost}/"]);
    $scanInsert->execute([$clientId, $userId, "https://{$scanHost}/"]);

    // First refresh adds the stream host and the latest scan host; the second adds nothing.
    check_map('first refresh adds stream and latest scan hosts', 2, DomainMap::refresh($pdo, $clientId));
    check_map('second refresh adds nothing', 0, DomainMap::refresh($pdo, $clientId));

    $streamDomainId = domain_id_by_host($pdo, $clientId, $streamHost);
    $scanDomainId = domain_id_by_host($pdo, $clientId, $scanHost);
    check_map('old scan host is not added', 0, domain_id_by_host($pdo, $clientId, $oldScanHost));
    check_map('stream domain has a url and name built from its host', ["https://{$streamHost}", $streamHost],
        array_values($pdo->query("SELECT url, name FROM domains WHERE id = {$streamDomainId}")->fetch(PDO::FETCH_NUM)));

    $loaded = DomainMap::load($pdo, $clientId);
    check_map('load returns an edge from the property to its stream domain',
        [['from' => "ga4:{$propertyRowId}", 'to' => "domain:{$streamDomainId}"]],
        $loaded['edges']);

    // Ownership answers: a confirmed answer survives a later refresh.
    DomainMap::confirm($pdo, $clientId, $streamDomainId, 'not_yours', $userId);
    DomainMap::refresh($pdo, $clientId);
    check_map('refresh leaves confirmation untouched', 'not_yours',
        $pdo->query("SELECT confirmation FROM domains WHERE id = {$streamDomainId}")->fetchColumn());

    DomainMap::confirm($pdo, $clientId, $scanDomainId, 'yours', $userId);
    $yours = $pdo->query("SELECT is_yours, verified, verified_by_user_id, confirmed_at FROM domains WHERE id = {$scanDomainId}")
        ->fetch(PDO::FETCH_ASSOC);
    check_map('yours sets is_yours and verified', ['is_yours' => 1, 'verified' => 1, 'verified_by_user_id' => $userId],
        ['is_yours' => (int)$yours['is_yours'], 'verified' => (int)$yours['verified'], 'verified_by_user_id' => (int)$yours['verified_by_user_id']]);
    check_map('confirm sets confirmed_at', false, $yours['confirmed_at'] === null);

    $foreignId = DomainMap::addManual($pdo, $otherClientId, "other-{$suffix}.example.test");
    expect_map_throw('foreign_target', fn() => DomainMap::confirm($pdo, $clientId, $foreignId, 'yours', $userId));
    expect_map_throw('invalid_answer', fn() => DomainMap::confirm($pdo, $clientId, $scanDomainId, 'maybe', $userId));

    // Manual adds: the path is dropped, and a repeat add returns the same row.
    $manualId = DomainMap::addManual($pdo, $clientId, 'example.org/path');
    check_map('manual add stores the bare host', 'example.org',
        $pdo->query("SELECT host FROM domains WHERE id = {$manualId}")->fetchColumn());
    check_map('manual add records source manual', 'manual',
        $pdo->query("SELECT source FROM domains WHERE id = {$manualId}")->fetchColumn());
    check_map('second manual add returns the same id', $manualId, DomainMap::addManual($pdo, $clientId, 'https://EXAMPLE.org'));
    expect_map_throw('invalid_host', fn() => DomainMap::addManual($pdo, $clientId, 'localhost'));

    echo "DomainMapTest passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($accountId > 0) {
        $pdo->prepare("DELETE FROM site_scans WHERE client_id IN (SELECT id FROM clients WHERE account_id = ?)")
            ->execute([$accountId]);
        $pdo->prepare("DELETE FROM users WHERE account_id = ?")->execute([$accountId]);
        TestFixtures::cleanupAccount($pdo, $accountId);
    }
}

exit($exitCode);
