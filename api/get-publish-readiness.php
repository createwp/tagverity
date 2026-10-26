<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/PublishReadiness.php';
require_once __DIR__ . '/../src/ReleaseEventBuilder.php';
require_once __DIR__ . '/../src/ReleaseChangeDetector.php';
require_once __DIR__ . '/../src/TrackingWorkspace.php';

use GTMAuditor\PublishReadiness;
use GTMAuditor\ReleaseChangeDetector;
use GTMAuditor\TrackingWorkspace;

require_auth();
$clientId = (int)($_GET['client_id'] ?? 0);
assert_client_access($clientId);

$workspace = (new TrackingWorkspace())->load(db(), $clientId);
$baseEvents = $workspace['base_events'];
$mappings = $workspace['mappings'];

$stmt = db()->prepare(
    "SELECT id, version_number, name, status, mapping_ids, release_snapshot, readiness_snapshot,
            approved_at, published_at, error_code, created_at, updated_at
     FROM tag_releases
     WHERE client_id = ?
     ORDER BY version_number DESC"
);
$stmt->execute([$clientId]);
$releases = [];
$baselineSnapshot = [];
$baselineVersion = null;
foreach ($stmt->fetchAll() as $row) {
    $releaseSnapshot = json_decode($row['release_snapshot'], true) ?: [];
    if ($baselineVersion === null && in_array($row['status'], ['approved', 'published'], true)) {
        $baselineSnapshot = $releaseSnapshot;
        $baselineVersion = (int)$row['version_number'];
    }
    $eventCount = 0;
    foreach (array_merge($releaseSnapshot['base_events'] ?? [], $releaseSnapshot['mappings'] ?? []) as $item) {
        $eventCount += count($item['event_template']['events'] ?? []);
    }
    $releases[] = [
        'id' => (int)$row['id'],
        'version_number' => (int)$row['version_number'],
        'name' => $row['name'],
        'status' => $row['status'],
        'mapping_ids' => json_decode($row['mapping_ids'], true) ?: [],
        'event_count' => $eventCount,
        'readiness' => json_decode($row['readiness_snapshot'], true) ?: [],
        'approved_at' => $row['approved_at'],
        'published_at' => $row['published_at'],
        'error_code' => $row['error_code'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

$changes = (new ReleaseChangeDetector())->detect($baseEvents, $mappings, $baselineSnapshot);
$baseEvents = $changes['base_events'];
$mappings = $changes['mappings'];
$readinessService = new PublishReadiness();
$readiness = $readinessService->evaluate(array_merge($baseEvents, $mappings), $readinessService->loadConnectionState(db(), $clientId));

api_respond(['ok' => true, 'data' => [
    'mappings' => $mappings,
    'base_events' => $baseEvents,
    'changes' => array_merge($changes['summary'], ['baseline_version' => $baselineVersion]),
    'readiness' => $readiness,
    'releases' => $releases,
]]);
