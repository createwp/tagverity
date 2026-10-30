<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Single-command runner for the standalone tests in tests/.
//
// Usage: php tests/run.php [dir]
//   dir defaults to tests/ (relative paths resolve against the repo root).
//   Non-recursive: runs *Test.php with PHP_BINARY and *Test.js with node,
//   each with the repo root as its working directory.
//
// Exit code: 0 when no test fails, 1 when any test fails.

$root = dirname(__DIR__);

$dirArg = $argv[1] ?? 'tests';
$dir = $dirArg;
if (!preg_match('/^([A-Za-z]:[\\\\\/]|[\\\\\/])/', $dirArg)) {
    $dir = $root . DIRECTORY_SEPARATOR . $dirArg;
}
$realDir = realpath($dir);
if ($realDir === false || !is_dir($realDir)) {
    fwrite(STDERR, "run.php: directory not found: {$dirArg}\n");
    exit(1);
}

// RunnerTest.php drives the runner against fixtures; never run it recursively from a fixtures dir.
$inFixtures = strpos(str_replace('\\', '/', $realDir), '/tests/fixtures') !== false;

$files = array_merge(
    glob($realDir . DIRECTORY_SEPARATOR . '*Test.php') ?: [],
    glob($realDir . DIRECTORY_SEPARATOR . '*Test.js') ?: []
);
sort($files);

$nodeAvailable = nodeAvailable();

$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($files as $file) {
    $name = basename($file);

    if ($inFixtures && $name === 'RunnerTest.php') {
        continue;
    }

    if (isJs($name) && !$nodeAvailable) {
        echo "SKIP {$name} (node not found on PATH)\n";
        $skipped++;
        continue;
    }

    $interpreter = isJs($name) ? 'node' : PHP_BINARY;
    [$exitCode, $output] = runTest($interpreter, $file, $root);

    $isSkip = $exitCode === 0 && preg_match('/\bskipped\b/i', $output) === 1;

    if ($exitCode !== 0) {
        echo "FAIL {$name} (exit {$exitCode})\n";
        echo indent(trim($output) === '' ? '(no output)' : trim($output)) . "\n";
        $failed++;
    } elseif ($isSkip) {
        echo "SKIP {$name}\n";
        $skipped++;
    } else {
        echo "PASS {$name}\n";
        $passed++;
    }
}

echo "{$passed} passed, {$failed} failed, {$skipped} skipped\n";
exit($failed > 0 ? 1 : 0);

/**
 * Runs one test script from the repo root and captures its combined output.
 * Output goes to temp files, not pipes, so a noisy test cannot deadlock the runner.
 *
 * @return array{0: int, 1: string} exit code and captured output
 */
function runTest(string $interpreter, string $file, string $cwd): array
{
    $outFile = tempnam(sys_get_temp_dir(), 'tvrun');
    $errFile = tempnam(sys_get_temp_dir(), 'tvrun');

    $command = $interpreter === 'node'
        ? ['node', $file]
        : [$interpreter, $file];

    $process = proc_open(
        $command,
        [
            0 => ['file', nullDevice(), 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ],
        $pipes,
        $cwd
    );

    if (!is_resource($process)) {
        @unlink($outFile);
        @unlink($errFile);
        return [1, 'runner could not start: ' . $command[0]];
    }

    $exitCode = proc_close($process);

    $output = (string) file_get_contents($outFile) . (string) file_get_contents($errFile);
    @unlink($outFile);
    @unlink($errFile);

    return [$exitCode, $output];
}

/**
 * True when `node --version` runs successfully.
 */
function nodeAvailable(): bool
{
    $process = @proc_open(
        ['node', '--version'],
        [
            0 => ['file', nullDevice(), 'r'],
            1 => ['file', nullDevice(), 'w'],
            2 => ['file', nullDevice(), 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return false;
    }

    return proc_close($process) === 0;
}

function isJs(string $name): bool
{
    return substr($name, -3) === '.js';
}

function nullDevice(): string
{
    return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
}

function indent(string $text): string
{
    $lines = preg_split('/\R/', $text) ?: [];
    return implode("\n", array_map(static fn(string $line): string => '    ' . $line, $lines));
}
