<?php
// ─── api/job-status.php — returns whether any audit jobs are still running ────
// Used by app.js to auto-refresh the page when an audit completes.
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$user = require_auth();

$stmt = db()->prepare(
    "SELECT COUNT(*) FROM audit_jobs aj
     JOIN clients c ON c.id = aj.client_id
     WHERE c.account_id = ? AND aj.status = 'running'"
);
$stmt->execute([$user['account_id']]);
$running = (int)$stmt->fetchColumn();

echo json_encode(['has_running' => $running > 0]);
