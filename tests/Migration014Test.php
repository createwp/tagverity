<?php
// Migration 014 test: runs run-migrations.php twice from the CLI and checks the domain
// host, source and confirmation columns plus the (client_id, host) unique key it adds.

$php = PHP_BINARY;
$root = dirname(__DIR__);
$runner = $root . '/run-migrations.php';

require_once $root . '/includes/config.php';
require_once $root . '/includes/db.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    echo "Migration014Test ... skipped: no database\n";
    exit(0);
}

function fail_migration014(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// Run the migrations twice: the second run must also succeed (idempotency).
for ($run = 1; $run <= 2; $run++) {
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $exitCode);
    $text = implode("\n", $output);
    if (strpos($text, '"ok":true') === false) {
        fail_migration014("Migration run {$run} did not report ok:true. Output: {$text}");
    }
}

function column_type014(PDO $pdo, string $table, string $column): ?string {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    $type = $stmt->fetchColumn();
    return $type === false ? null : (string)$type;
}

// Returns the ordered column list of a UNIQUE index, or [] when it does not exist.
function unique_key_columns014(PDO $pdo, string $table, string $index): array {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0
         ORDER BY SEQ_IN_INDEX"
    );
    $stmt->execute([$table, $index]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
}

foreach (['host', 'source', 'confirmation', 'confirmed_at'] as $column) {
    if (column_type014($pdo, 'domains', $column) === null) {
        fail_migration014("domains is missing {$column}.");
    }
}

$source = (string)column_type014($pdo, 'domains', 'source');
if (strpos($source, "'ga4_stream'") === false || strpos($source, "'site_scan'") === false || strpos($source, "'manual'") === false) {
    fail_migration014("domains.source enum is missing a value: {$source}");
}

$confirmation = (string)column_type014($pdo, 'domains', 'confirmation');
foreach (['unconfirmed', 'yours', 'not_yours', 'investigate'] as $value) {
    if (strpos($confirmation, "'{$value}'") === false) {
        fail_migration014("domains.confirmation enum is missing '{$value}': {$confirmation}");
    }
}

if (unique_key_columns014($pdo, 'domains', 'uq_domains_client_host') !== ['client_id', 'host']) {
    fail_migration014('domains is missing uq_domains_client_host (client_id, host).');
}

echo "Migration014Test passed.\n";
