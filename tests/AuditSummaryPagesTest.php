<?php
// Static checks for the latest-audit summary on dashboard.php and clients.php, and the
// failed-run handling on audit-report.php. Each check fails the test if the rule disappears.

$root = dirname(__DIR__);

function summaryPageSource(string $root, string $page): string
{
    $source = file_get_contents($root . '/' . $page);
    if ($source === false) {
        fwrite(STDERR, "FAIL: cannot read {$page}\n");
        exit(1);
    }
    return $source;
}

function summaryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dashboard = summaryPageSource($root, 'dashboard.php');
$clients   = summaryPageSource($root, 'clients.php');
$report    = summaryPageSource($root, 'audit-report.php');

// The latest-summary query must cover the most recent finished run, including failed runs.
summaryAssert(
    (bool) preg_match("/status IN \\('complete','partial','failed'\\)\\s+ORDER BY completed_at DESC LIMIT 1/", $dashboard),
    'dashboard.php latest-summary query must include failed runs'
);
summaryAssert(
    strpos($dashboard, "last_status === 'failed'") !== false,
    'dashboard.php must have a failed-latest branch'
);
summaryAssert(
    strpos($dashboard, "Your latest audit couldn't run.") !== false
        && strpos($dashboard, 'audit-report.php?id=<?= (int)$last_job[\'id\'] ?>') !== false
        && strpos($dashboard, '>See why<') !== false,
    'dashboard.php failed latest run needs its message and a See why link'
);
summaryAssert(
    strpos($dashboard, '>View details<') !== false,
    'dashboard.php failed history rows need a View details button'
);
summaryAssert(
    strpos($dashboard, "last_status === 'failed' ? '—' : \$critical_count") !== false
        && strpos($dashboard, "last_status === 'failed' ? '—' : \$warning_count") !== false,
    'dashboard.php must show dashes for critical and warning counts when the latest run failed'
);
summaryAssert(
    strpos($dashboard, "From the sources we could check") !== false,
    'dashboard.php must caption partial counts as coming from the sources we could check'
);
summaryAssert(
    strpos($dashboard, "\$last_score = (int)\$last_job['health_score'];") === false,
    'dashboard.php must not cast a NULL health_score to 0'
);

// clients.php must summarise the same finished runs and show failed honestly.
summaryAssert(
    substr_count($clients, "aj2.status IN ('complete','partial','failed')") >= 2
        && substr_count($clients, "aj3.status IN ('complete','partial','failed')") >= 1,
    'clients.php latest-summary subqueries must include failed runs'
);
summaryAssert(
    strpos($clients, "last_status'] === 'failed'") !== false
        && strpos($clients, 'audit-report.php?id=<?= (int)$c[\'last_audit_id\'] ?>') !== false,
    'clients.php failed latest run needs a Failed label and a See why link'
);

// audit-report.php must not show 0 counts for a failed audit: nothing was checked.
foreach (['critical', 'warning', 'info'] as $severity) {
    summaryAssert(
        strpos($report, "\$status === 'failed' ? '—' : \$counts['{$severity}']") !== false,
        "audit-report.php failed branch missing for {$severity} count"
    );
}

echo "AuditSummaryPagesTest passed.\n";
exit(0);
