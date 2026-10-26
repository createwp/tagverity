<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/ReleaseEventBuilder.php';
require_once __DIR__ . '/../src/TrackingWorkspace.php';

use GTMAuditor\TrackingWorkspace;

require_auth();
$clientId = (int)($_GET['client_id'] ?? 0);
assert_client_access($clientId);

$mappings = (new TrackingWorkspace())->load(db(), $clientId)['mappings'];

api_respond(['ok' => true, 'data' => ['mappings' => $mappings]]);
