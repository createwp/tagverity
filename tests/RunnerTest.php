<?php

declare(strict_types=1);

// Verifies tests/run.php against known-good and known-bad fixtures.
// Run with: php tests/RunnerTest.php

$root = dirname(__DIR__);
$runner = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'run.php';
$fixtures = 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'runner';

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($fixtures);

$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptors, $pipes, $root);
if (!is_resource($process)) {
    fwrite(STDERR, "RunnerTest: could not start the runner\n");
    exit(1);
}

$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

$failures = [];

if ($exitCode !== 1) {
    $failures[] = "expected exit code 1, got {$exitCode}";
}

$expectedLines = [
    'PASS PassingTest.php',
    'FAIL FailingTest.php',
    'FAIL BrokenTest.php',
    'SKIP SkippedTest.php',
    '1 passed, 2 failed, 1 skipped',
];

foreach ($expectedLines as $line) {
    if (strpos($output, $line) === false) {
        $failures[] = "output missing line: {$line}";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "RunnerTest FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    fwrite(STDERR, "--- runner output ---\n{$output}\n");
    exit(1);
}

echo "RunnerTest passed: runner reports PASS, FAIL and SKIP correctly and exits 1 on failure\n";
exit(0);
