<?php
// Migration 015 test: runs run-migrations.php twice from the CLI and checks that
// tracking_event_settings.manual_parameters exists afterwards.

$php = PHP_BINARY;
$root = dirname(__DIR__);
$runner = $root . '/run-migrations.php';

require_once $root . '/includes/config.php';
require_once $root . '/includes/db.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    echo "Migration015Test ... skipped: no database\n";
    exit(0);
}

function fail_migration015(string $message): void {
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
        fail_migration015("Migration run {$run} did not report ok:true. Output: {$text}");
    }
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
);
$stmt->execute(['tracking_event_settings', 'manual_parameters']);
if ((int)$stmt->fetchColumn() === 0) {
    fail_migration015('tracking_event_settings.manual_parameters is missing after migration 015.');
}

echo "Migration015Test passed.\n";
