<?php
require_once __DIR__ . '/../src/AuditReportPdf.php';

use GTMAuditor\AuditReportPdf;

function pdfReportFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function pdfReportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        pdfReportFail($message);
    }
}

$job = [
    'id'           => 42,
    'status'       => 'complete',
    'health_score' => 72,
    'completed_at' => '2026-10-29 10:15:00',
];

$coverage = [[
    'source'  => 'Google Tag Manager',
    'status'  => 'success',
    'tone'    => 'ok',
    'message' => 'Checked successfully.',
]];

$findings = [[
    'severity'        => 'critical',
    'category'        => 'container_health',
    'title'           => '<script>alert(1)</script> "quoted"',
    'description'     => 'A description.',
    'recommendation'  => 'A recommendation.',
    'fix_owner'       => 'gtm',
    'affected_item'   => 'GTM-TEST01',
    'developer_notes' => 'DEVNOTE_MARKER_TEXT',
]];

$view = new AuditReportPdf();

// 1. Escaping: a hostile finding title is rendered as text, never as markup.
$html = $view->html($job, 'Acme <b>Client</b>', $findings, $coverage, false);
pdfReportAssert(strpos($html, '&lt;script&gt;') !== false, 'finding title must be escaped');
pdfReportAssert(strpos($html, '<script') === false, 'no raw <script> tag may appear in the PDF HTML');
pdfReportAssert(strpos($html, '&quot;quoted&quot;') !== false, 'double quotes must be escaped');
pdfReportAssert(strpos($html, '<b>Client</b>') === false, 'client name must be escaped');

// 2. Developer notes are plan-gated by the caller through the boolean.
$without = $view->html($job, 'Acme', $findings, $coverage, false);
pdfReportAssert(strpos($without, 'DEVNOTE_MARKER_TEXT') === false, 'developer notes must be absent when not included');
pdfReportAssert(strpos($without, 'Developer notes') === false, 'developer notes heading must be absent when not included');

$with = $view->html($job, 'Acme', $findings, $coverage, true);
pdfReportAssert(strpos($with, 'DEVNOTE_MARKER_TEXT') !== false, 'developer notes must be present when included');

// 3. Partial audits show the unscored sentence, as the web report does.
$partialJob = $job;
$partialJob['status'] = 'partial';
$partialJob['health_score'] = null;
$partial = $view->html($partialJob, 'Acme', $findings, $coverage, false);
pdfReportAssert(strpos($partial, 'Score unavailable') !== false, 'partial audit must show "Score unavailable"');
pdfReportAssert(
    strpos($partial, "Some data sources couldn't be checked, so we can't give a trustworthy score yet.") !== false,
    'partial audit must carry the web report sentence'
);

// 4. render() returns PDF bytes.
$pdf = $view->render($job, 'Acme', $findings, $coverage, false);
pdfReportAssert(substr($pdf, 0, 5) === '%PDF-', 'render() output must start with %PDF-');

// 5. Coverage footnote: shown when source_status records the rule set and GA4 window.
$footnoteJob = $job + ['source_status' => json_encode([
    'rule_set_version' => '2026.10.1',
    'ga4_window_days'  => 28,
])];
$footnoted = $view->html($footnoteJob, 'Acme', $findings, $coverage, false);
pdfReportAssert(
    strpos($footnoted, 'Rule set 2026.10.1 · GA4 window 28 days') !== false,
    'footnote must show the rule set and GA4 window when source_status has them'
);

// 6. Coverage footnote: absent when source_status is null.
$noStatus = $view->html($job + ['source_status' => null], 'Acme', $findings, $coverage, false);
pdfReportAssert(strpos($noStatus, 'Rule set') === false, 'footnote must be absent when source_status is null');
pdfReportAssert(strpos($noStatus, 'GA4 window') === false, 'GA4 window must be absent when source_status is null');
pdfReportAssert(strpos($noStatus, '<div class="coverage-note">') === false, 'coverage-note block must be absent when source_status is null');

// 7. Group headings stay with their first finding and are never left at a page foot.
pdfReportAssert(strpos($footnoted, 'page-break-after: avoid') !== false, 'group headings must carry page-break-after: avoid');
pdfReportAssert(
    preg_match('/<div class="finding-group"><div class="group-heading">Critical \(1\)<\/div><div class="finding finding-critical">/', $footnoted) === 1,
    'the Critical heading must be wrapped with its first finding'
);

echo "AuditReportPdfTest: all assertions passed\n";
