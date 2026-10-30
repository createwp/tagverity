<?php

declare(strict_types=1);

// Verifies the deny-by-default rewrite rules in .htaccess against relative paths.
// Apache matches per-directory RewriteRule patterns against the path without its leading slash.
// Run with: php tests/HtaccessRulesTest.php

$root = dirname(__DIR__);
$htaccessPath = $root . DIRECTORY_SEPARATOR . '.htaccess';

$htaccess = file_get_contents($htaccessPath);
if ($htaccess === false) {
    fwrite(STDERR, "HtaccessRulesTest FAILED: could not read {$htaccessPath}\n");
    exit(1);
}

$patterns = [];
if (preg_match_all('/^\s*RewriteRule\s+(\S+)\s+-\s+\[F/m', $htaccess, $matches)) {
    $patterns = $matches[1];
}

$denied = [
    '.git/config',
    '.env',
    '.remember/now.md',
    '.claude/settings.json',
    'vendor/autoload.php',
    'tests/RunnerTest.php',
    'docs/PROJECT_STATUS.md',
    'logs/app.log',
    'storage/reports/a.pdf',
    'sql/001_initial_schema.sql',
    'src/AuditEngine.php',
    'includes/config.php',
    'cron/run-audits.php',
    'composer.lock',
    'design-qa.md',
];

$allowed = [
    'dashboard.php',
    'api/job-status.php',
    'assets/css/app.css',
    'assets/js/mapper-snippet.js',
    'run-migrations.php',
    'api/docs-helper.php',
];

$failures = [];

if ($patterns === []) {
    $failures[] = 'no "RewriteRule <pattern> - [F" lines found in .htaccess';
}

foreach ($denied as $path) {
    $matched = false;
    foreach ($patterns as $pattern) {
        if (preg_match('#' . $pattern . '#', $path) === 1) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $failures[] = "expected denied but no rule matches: {$path}";
    }
}

foreach ($allowed as $path) {
    foreach ($patterns as $pattern) {
        if (preg_match('#' . $pattern . '#', $path) === 1) {
            $failures[] = "expected allowed but rule '{$pattern}' matches: {$path}";
            break;
        }
    }
}

// Ruling R4: the runner entry points must refuse to run outside the CLI.
$guarded = [
    $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'run.php',
    $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'RunnerTest.php',
];

foreach ($guarded as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        $failures[] = 'could not read ' . basename($file);
        continue;
    }
    if (strpos($source, "PHP_SAPI !== 'cli'") === false) {
        $failures[] = basename($file) . " does not contain PHP_SAPI !== 'cli' guard";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "HtaccessRulesTest FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "HtaccessRulesTest passed: deny rules block sensitive paths, public paths stay reachable, and runner entry points are CLI-only\n";
exit(0);
