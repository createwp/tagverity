<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$userId = (int)db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($userId === 0) {
    echo "TrackingWorkspace page test skipped: no users.\n";
    exit(0);
}

$_SESSION['user_id'] = $userId;
ob_start();
require __DIR__ . '/../tracking-workspace.php';
$html = ob_get_clean();

foreach (['Tags &amp; triggers', 'Configure events, triggers and publishing.', 'Raw form values are never captured', 'See how SHA-256 works', 'Map an element', 'Destinations', 'Review changes', 'id="tracking-payload-modal"', 'data-filter="recommended"', 'data-filter="mapped"', 'id="tracking-event-search"', 'id="tracking-tag-type-filter"', 'id="tracking-trigger-type-filter"', 'id="tracking-page-size"', 'id="tracking-pagination"', 'id="tracking-icon-plus"', 'id="tracking-icon-minus"', 'id="tracking-icon-pencil"', 'id="tracking-icon-chevron"'] as $expected) {
    if (strpos($html, $expected) === false) {
        fwrite(STDERR, 'TrackingWorkspace page is missing: ' . $expected . "\n");
        exit(1);
    }
}

$workspaceScript = file_get_contents(__DIR__ . '/../assets/js/tracking-workspace.js');
foreach (['tracking-icon-action', 'tracking-inclusion-action', 'populateTypeFilters', 'renderPagination', 'tracking-destination-grid', 'destination-enabled', 'destination-recommendation', 'destination-parameter', 'destination-identifier', 'Preview saved payload', 'previewDestinationPayload', 'destinationNameError', 'event-group', 'manual_parameters', 'TagVerityUI.bindPopover(', 'Remove ', 'chip-remove', 'chip-add', 'aria-haspopup', 'Custom value'] as $expectedScript) {
    if (strpos($workspaceScript, $expectedScript) === false) {
        fwrite(STDERR, 'TrackingWorkspace controls are missing: ' . $expectedScript . "\n");
        exit(1);
    }
}

// Parameter chips keep their state in one per-card object that buildEventCard creates and saveEvent reads.
$buildStart = strpos($workspaceScript, 'function buildEventCard(');
$buildEnd = strpos($workspaceScript, 'async function archiveMapping(');
$buildBody = ($buildStart === false || $buildEnd === false) ? '' : substr($workspaceScript, $buildStart, $buildEnd - $buildStart);
$saveStart = strpos($workspaceScript, 'async function saveEvent(');
$saveEnd = strpos($workspaceScript, 'function renderPrivacy(');
$saveBody = ($saveStart === false || $saveEnd === false) ? '' : substr($workspaceScript, $saveStart, $saveEnd - $saveStart);
if (strpos($buildBody, 'const cardState = {') === false || strpos($buildBody, 'form._cardState = cardState') === false) {
    fwrite(STDERR, "buildEventCard does not create the per-card cardState object.\n");
    exit(1);
}
if (strpos($saveBody, 'cardState') === false || strpos($saveBody, 'selected_parameters: selectedParameters') === false || strpos($saveBody, 'manual_parameters: manualParameters') === false) {
    fwrite(STDERR, "saveEvent does not send selected and manual parameters from cardState.\n");
    exit(1);
}
if (strpos($saveBody, 'data-role="parameter"') !== false) {
    fwrite(STDERR, "saveEvent still reads parameter checkboxes instead of cardState.\n");
    exit(1);
}
if (strpos($workspaceScript, 'buildEventGroup') !== false || strpos($workspaceScript, "buildEventGroup('Mapped by you'") !== false) {
    fwrite(STDERR, "TrackingWorkspace still splits mapped events into a separate visual section.\n");
    exit(1);
}

$workspaceStyles = file_get_contents(__DIR__ . '/../assets/css/app.css');
foreach (['.tracking-event-quick-actions .tracking-status', '.tracking-event-identity-copy p', 'white-space: nowrap'] as $expectedStyle) {
    if (strpos($workspaceStyles, $expectedStyle) === false) {
        fwrite(STDERR, 'TrackingWorkspace label alignment is missing: ' . $expectedStyle . "\n");
        exit(1);
    }
}
foreach (['tracking-event-source-column', 'grid-template-columns: 112px minmax(0, 1fr)'] as $removedStyle) {
    if (strpos($workspaceStyles, $removedStyle) !== false || strpos($workspaceScript, $removedStyle) !== false) {
        fwrite(STDERR, 'TrackingWorkspace still contains the retired source label column: ' . $removedStyle . "\n");
        exit(1);
    }
}

foreach (['class="tracking-path"', 'class="tracking-status-guide', 'class="card tracking-hash-demo"', 'Tracking choices', 'Working draft', 'Hover for status help'] as $removed) {
    if (strpos($html, $removed) !== false) {
        fwrite(STDERR, 'TrackingWorkspace page still contains busy legacy UI: ' . $removed . "\n");
        exit(1);
    }
}

echo "TrackingWorkspace page test passed.\n";
