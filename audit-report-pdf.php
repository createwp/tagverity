<?php
// ─── audit-report-pdf.php — Download an audit report as PDF ──────────────────
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/plans.php';
require_once __DIR__ . '/src/AuditCoverageView.php';
require_once __DIR__ . '/src/AuditReportPdf.php';
$user = require_auth();

$audit_job_id = (int)($_GET['id'] ?? 0);
if (!$audit_job_id) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}
$job = assert_audit_access($audit_job_id);
$pdo = db();

// A report can only be exported once the audit has reached a final state.
$status = (string)$job['status'];
if (!in_array($status, ['complete', 'partial', 'failed'], true)) {
    http_response_code(409);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo "This audit hasn't finished yet.";
    exit;
}

// Fetch findings
$findings_stmt = $pdo->prepare(
    "SELECT * FROM audit_findings WHERE audit_job_id = ? ORDER BY
     FIELD(severity,'critical','warning','info'), category, id"
);
$findings_stmt->execute([$audit_job_id]);
$findings = $findings_stmt->fetchAll();

// Fetch client name
$client_stmt = $pdo->prepare("SELECT name FROM clients WHERE id = ? LIMIT 1");
$client_stmt->execute([$job['client_id']]);
$client_name = $client_stmt->fetchColumn() ?: 'Unknown Client';

$source_status = null;
if ($job['source_status'] !== null && $job['source_status'] !== '') {
    $decoded_status = json_decode((string)$job['source_status'], true);
    $source_status  = is_array($decoded_status) ? $decoded_status : null;
}
$coverage_rows = (new GTMAuditor\AuditCoverageView())->describe($source_status);

$pdf = (new GTMAuditor\AuditReportPdf())->render(
    $job,
    (string)$client_name,
    $findings,
    $coverage_rows,
    has_feature('developer_notes')
);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="tagverity-audit-' . $audit_job_id . '-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
