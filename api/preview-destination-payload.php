<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/IdentifierProcessor.php';
require_once __DIR__ . '/../src/ReleaseEventBuilder.php';
require_once __DIR__ . '/../src/TrackingWorkspace.php';
require_once __DIR__ . '/../src/DestinationConnectionManager.php';
require_once __DIR__ . '/../src/DestinationPayloadPreview.php';

use GTMAuditor\DestinationConnectionManager;
use GTMAuditor\DestinationPayloadPreview;
use GTMAuditor\TrackingWorkspace;

$user = require_auth();
api_require_post();
$input = api_json_input();
api_require_csrf($input);
$clientId = (int)($input['client_id'] ?? 0);
$eventKey = trim((string)($input['event_key'] ?? ''));
$platform = trim((string)($input['platform'] ?? ''));
assert_client_access($clientId);

try {
    $pdo = db();
    $workspaceService = new TrackingWorkspace();
    $workspace = $workspaceService->load($pdo, $clientId);
    $event = $workspaceService->findEvent($workspace, $eventKey);
    if (!$event) api_respond(['error' => 'event_not_found', 'message' => 'Save the event before previewing its payload.'], 404);

    $manager = new DestinationConnectionManager();
    $connection = null;
    foreach ($manager->load($pdo, $clientId) as $item) {
        if ($item['platform'] === $platform) $connection = $item;
    }
    if (!$connection) api_respond(['error' => 'unsupported_platform'], 422);

    $preview = (new DestinationPayloadPreview())->build($event, $platform, $workspace['privacy'] ?? [], $connection);
    api_respond(['ok' => true, 'data' => $preview]);
} catch (InvalidArgumentException $e) {
    api_respond(['error' => $e->getMessage(), 'message' => 'Enable and save this destination for the event first.'], 422);
} catch (Throwable $e) {
    error_log('Destination payload preview failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'payload_preview_failed', 'message' => 'The payload preview could not be generated.'], 500);
}
