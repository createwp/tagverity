<?php
// Migration 013 test: runs run-migrations.php twice from the CLI and checks the
// audit outcome, source status and resource linking schema it adds.

$php = PHP_BINARY;
$root = dirname(__DIR__);
$runner = $root . '/run-migrations.php';

require_once $root . '/includes/config.php';
require_once $root . '/includes/db.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    echo "Migration013Test ... skipped: no database\n";
    exit(0);
}

function fail_migration013(string $message): void {
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
        fail_migration013("Migration run {$run} did not report ok:true. Output: {$text}");
    }
}

function column_exists013(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

// Returns the ordered column list of a UNIQUE index, or [] when it does not exist.
function unique_key_columns013(PDO $pdo, string $table, string $index): array {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0
         ORDER BY SEQ_IN_INDEX"
    );
    $stmt->execute([$table, $index]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
}

// audit_jobs: outcome enum and source status
$stmt = $pdo->prepare(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
);
$stmt->execute(['audit_jobs', 'status']);
if (!column_exists013($pdo, 'audit_jobs', 'source_status')) {
    fail_migration013('audit_jobs is missing source_status.');
}
$statusType = (string)$stmt->fetchColumn();
if (strpos($statusType, "'partial'") === false) {
    fail_migration013("audit_jobs.status enum is missing 'partial'.");
}

// gtm_containers: audit target and linking columns, plus unique key
foreach (['audit_target', 'gtm_account_name', 'linked_by_user_id', 'linked_at'] as $column) {
    if (!column_exists013($pdo, 'gtm_containers', $column)) {
        fail_migration013("gtm_containers is missing {$column}.");
    }
}
if (unique_key_columns013($pdo, 'gtm_containers', 'uq_gtm_containers_client_container') !== ['client_id', 'container_id']) {
    fail_migration013('gtm_containers is missing uq_gtm_containers_client_container (client_id, container_id).');
}

// ga4_properties: audit target and linking columns, plus unique key
foreach (['audit_target', 'ga4_account_id', 'ga4_account_name', 'linked_by_user_id', 'linked_at'] as $column) {
    if (!column_exists013($pdo, 'ga4_properties', $column)) {
        fail_migration013("ga4_properties is missing {$column}.");
    }
}
if (unique_key_columns013($pdo, 'ga4_properties', 'uq_ga4_properties_client_property') !== ['client_id', 'property_id']) {
    fail_migration013('ga4_properties is missing uq_ga4_properties_client_property (client_id, property_id).');
}

// ga4_streams: unique key only
if (unique_key_columns013($pdo, 'ga4_streams', 'uq_ga4_streams_property_stream') !== ['ga4_property_id', 'stream_id']) {
    fail_migration013('ga4_streams is missing uq_ga4_streams_property_stream (ga4_property_id, stream_id).');
}

echo "Migration013Test passed.\n";
