<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';

require_auth();
$clientId = (int)($_GET['client_id'] ?? 0);
assert_client_access($clientId);

$stmt = db()->prepare(
    "SELECT op.client_id, op.latest_scan_id, op.site_url, op.status, op.business_type,
            op.answers, op.measurement_plan, op.updated_at,
            ss.status AS scan_status, ss.pages_discovered, ss.pages_scanned,
            ss.scan_data, ss.questionnaire, ss.error_code, ss.completed_at
     FROM onboarding_profiles op
     LEFT JOIN site_scans ss ON ss.id = op.latest_scan_id
     WHERE op.client_id = ?
     LIMIT 1"
);
$stmt->execute([$clientId]);
$row = $stmt->fetch();

if (!$row) {
    api_respond(['ok' => true, 'data' => null]);
}

api_respond([
    'ok' => true,
    'data' => [
        'profile' => [
            'client_id' => (int)$row['client_id'],
            'site_url' => $row['site_url'],
            'status' => $row['status'],
            'business_type' => $row['business_type'],
            'answers' => $row['answers'] ? json_decode($row['answers'], true) : null,
            'measurement_plan' => $row['measurement_plan'] ? json_decode($row['measurement_plan'], true) : null,
            'updated_at' => $row['updated_at'],
        ],
        'scan' => [
            'id' => (int)$row['latest_scan_id'],
            'status' => $row['scan_status'],
            'pages_discovered' => (int)$row['pages_discovered'],
            'pages_scanned' => (int)$row['pages_scanned'],
            'data' => $row['scan_data'] ? json_decode($row['scan_data'], true) : null,
            'questionnaire' => $row['questionnaire'] ? json_decode($row['questionnaire'], true) : null,
            'error_code' => $row['error_code'],
            'completed_at' => $row['completed_at'],
        ],
    ],
]);

