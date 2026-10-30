<?php
require_once __DIR__ . '/../src/SourceStatus.php';
require_once __DIR__ . '/../src/AuditOutcome.php';
require_once __DIR__ . '/../src/AuditEngine.php';

use GTMAuditor\AuditEngine;
use GTMAuditor\AuditOutcome;
use GTMAuditor\SourceStatus;

function outcomeSources(string $gtm, string $ga4): array
{
    return [
        'gtm'      => ['status' => $gtm, 'detail' => ''],
        'ga4'      => ['status' => $ga4, 'detail' => ''],
        'bigquery' => ['status' => SourceStatus::NOT_APPLICABLE, 'detail' => ''],
        'crawl'    => ['status' => SourceStatus::NOT_APPLICABLE, 'detail' => ''],
    ];
}

function outcomeFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$outcome = new AuditOutcome();
$success = SourceStatus::SUCCESS;

// Both required sources succeed, no findings -> complete with raw score.
$result = $outcome->decide(outcomeSources($success, $success), [], 100);
if ($result !== ['status' => 'complete', 'health_score' => 100]) {
    outcomeFail('both success, no findings: ' . json_encode($result));
}

// gtm success + ga4 insufficient_coverage -> partial, no score.
$result = $outcome->decide(outcomeSources($success, SourceStatus::INSUFFICIENT_COVERAGE), [], 100);
if ($result !== ['status' => 'partial', 'health_score' => null]) {
    outcomeFail('gtm success + ga4 insufficient_coverage: ' . json_encode($result));
}

// Both required sources authentication_failed -> failed, no score.
$result = $outcome->decide(
    outcomeSources(SourceStatus::AUTHENTICATION_FAILED, SourceStatus::AUTHENTICATION_FAILED),
    [],
    100
);
if ($result !== ['status' => 'failed', 'health_score' => null]) {
    outcomeFail('both authentication_failed: ' . json_encode($result));
}

// A required source missing from the array counts as not success.
$missing = outcomeSources($success, $success);
unset($missing['ga4']);
$result = $outcome->decide($missing, [], 100);
if ($result !== ['status' => 'partial', 'health_score' => null]) {
    outcomeFail('ga4 missing from source status: ' . json_encode($result));
}

// Both required sources not_applicable (none success) -> complete, per the literal rule.
$na = SourceStatus::NOT_APPLICABLE;
$result = $outcome->decide(outcomeSources($na, $na), [], 100);
if ($result !== ['status' => 'complete', 'health_score' => 100]) {
    outcomeFail('both not_applicable: ' . json_encode($result));
}

// A finding with score_gate true caps a complete audit at 1.
$gated = [[
    'severity' => 'critical',
    'evidence' => json_encode(['score_gate' => true]),
]];
$result = $outcome->decide(outcomeSources($success, $success), $gated, 85);
if ($result !== ['status' => 'complete', 'health_score' => 1]) {
    outcomeFail('score_gate true, raw 85: ' . json_encode($result));
}

// score_gate false does not cap the score.
$ungated = [[
    'severity' => 'warning',
    'evidence' => json_encode(['score_gate' => false]),
]];
$result = $outcome->decide(outcomeSources($success, $success), $ungated, 85);
if ($result !== ['status' => 'complete', 'health_score' => 85]) {
    outcomeFail('score_gate false, raw 85: ' . json_encode($result));
}

// Undecodable evidence is treated as no gate.
$badEvidence = [['severity' => 'critical', 'evidence' => '{not json']];
$result = $outcome->decide(outcomeSources($success, $success), $badEvidence, 85);
if ($result !== ['status' => 'complete', 'health_score' => 85]) {
    outcomeFail('undecodable evidence: ' . json_encode($result));
}

// Engine: (not set) landing page at 20% of sessions emits a gating critical finding.
$engine = new AuditEngine(
    [],
    ['landing_page' => [['landingPage' => '(not set)', 'sessions' => '200']], 'total_sessions' => 1000],
    [],
    ['id' => 1],
    1
);
$findings = $engine->run();
$notSet = null;
foreach ($findings as $finding) {
    if (($finding['severity'] ?? '') === 'critical' && str_contains($finding['title'] ?? '', '(not set)')) {
        $notSet = $finding;
        break;
    }
}
if ($notSet === null) {
    outcomeFail('engine did not emit a critical (not set) finding');
}
$evidence = json_decode($notSet['evidence'], true);
if (($evidence['score_gate'] ?? null) !== true) {
    outcomeFail('(not set) critical finding evidence lacks score_gate true: ' . $notSet['evidence']);
}

echo "AuditOutcomeTest passed\n";
exit(0);
