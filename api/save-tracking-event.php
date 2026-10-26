<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/ReleaseEventBuilder.php';
require_once __DIR__ . '/../src/EventDestinationRouter.php';
require_once __DIR__ . '/../src/TrackingWorkspace.php';

use GTMAuditor\EventDestinationRouter;
use GTMAuditor\ReleaseEventBuilder;
use GTMAuditor\TrackingWorkspace;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
$eventKey = trim((string)($input['event_key'] ?? ''));
$approvedName = trim((string)($input['approved_event_name'] ?? ''));
$enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$selectedParameters = is_array($input['selected_parameters'] ?? null) ? $input['selected_parameters'] : [];
$triggerIntent = trim((string)($input['trigger_intent'] ?? ''));
$eventGroup = trim((string)($input['event_group'] ?? ''));
$destinationInput = is_array($input['destinations'] ?? null) ? $input['destinations'] : [];
assert_client_access($clientId);

$nameValidator = new ReleaseEventBuilder();
if ($eventKey === '' || mb_strlen($eventKey) > 191 || !$nameValidator->validEventName($approvedName)) {
    api_respond(['error' => 'invalid_event_configuration', 'message' => 'Use an event name that starts with a letter and contains only lowercase letters, numbers, and underscores.'], 422);
}

try {
    $destinationRouter = new EventDestinationRouter();
    $workspaceService = new TrackingWorkspace($nameValidator);
    $workspace = $workspaceService->load(db(), $clientId);
    $availableEvent = $workspaceService->findEvent($workspace, $eventKey);
    if (!$availableEvent) {
        api_respond(['error' => 'event_not_found', 'message' => 'This event is no longer available in the current tracking plan.'], 404);
    }

    $availableTriggerIntents = $availableEvent['available_trigger_intents'] ?? [];
    if ($triggerIntent === '') $triggerIntent = (string)($availableEvent['trigger_intent'] ?? '');
    if ($availableTriggerIntents && !in_array($triggerIntent, $availableTriggerIntents, true)) {
        api_respond(['error' => 'invalid_trigger_intent', 'message' => 'Choose a compatible trigger for this mapped element.'], 422);
    }
    if (!$availableTriggerIntents) $triggerIntent = '';
    if ($eventGroup === '') $eventGroup = (string)($availableEvent['event_group'] ?? 'interaction');
    if (!$destinationRouter->validGroup($eventGroup)) {
        api_respond(['error' => 'invalid_event_group', 'message' => 'Choose a valid business classification.'], 422);
    }
    $triggerChanged = $triggerIntent !== '' && $triggerIntent !== ($availableEvent['trigger_intent'] ?? '');
    $availableParameters = $triggerChanged
        ? $workspaceService->parametersForCompatibleTrigger($triggerIntent)
        : $availableEvent['available_parameters'];
    if ($triggerChanged) $selectedParameters = $availableParameters;
    $cleanParameters = [];
    foreach ($selectedParameters as $parameter) {
        if (is_string($parameter) && in_array($parameter, $availableParameters, true)) $cleanParameters[] = $parameter;
    }
    $cleanParameters = array_values(array_unique($cleanParameters));
    if (count($cleanParameters) !== count(array_unique(array_filter($selectedParameters, 'is_string')))) {
        api_respond(['error' => 'invalid_parameter', 'message' => 'One or more selected parameters are not available for this event.'], 422);
    }
    try {
        $privacy = $workspace['privacy'] ?? [];
        $allowedIdentifiers = !empty($privacy['hashed_pii_enabled']) && is_array($privacy['allowed_hashed_fields'] ?? null)
            ? $privacy['allowed_hashed_fields']
            : [];
        $destinationRoutes = $destinationRouter->normaliseInput($destinationInput, $approvedName, $eventGroup, $cleanParameters, $allowedIdentifiers);
    } catch (InvalidArgumentException $e) {
        $messages = [
            'invalid_ga4_event_name' => 'GA4 names must start with a letter and contain no more than 40 lowercase letters, numbers, or underscores.',
            'invalid_posthog_event_name' => 'PostHog names must use lowercase snake_case and contain no more than 80 characters.',
            'invalid_meta_event_name' => 'Choose a Meta standard event or use a letter-led custom name without spaces.',
            'invalid_google_ads_event_name' => 'Google Ads conversion action names must be unique, readable, and no more than 100 characters.',
            'invalid_destination_parameter' => 'A destination parameter is not enabled for this event.',
            'unsupported_destination_parameter' => 'A selected parameter is not supported by that destination.',
            'identifier_not_allowed_by_privacy_policy' => 'A selected identifier is not allowed by the saved privacy policy.',
            'unsupported_destination_identifier' => 'That destination does not support one of the selected user identifiers.',
        ];
        api_respond(['error' => $e->getMessage(), 'message' => $messages[$e->getMessage()] ?? 'Check the selected destinations and their event names.'], 422);
    }

    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO tracking_event_settings
         (client_id, event_key, source_event_name, approved_event_name, event_group, enabled, selected_parameters, trigger_intent, updated_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE source_event_name = VALUES(source_event_name),
             approved_event_name = VALUES(approved_event_name), enabled = VALUES(enabled),
             event_group = VALUES(event_group),
             selected_parameters = VALUES(selected_parameters), trigger_intent = VALUES(trigger_intent),
             updated_by_user_id = VALUES(updated_by_user_id),
             updated_at = NOW()'
    );
    $stmt->execute([
        $clientId,
        $eventKey,
        $triggerIntent === 'click' ? 'element_click' : ($triggerIntent === 'visibility' ? 'element_view' : $availableEvent['source_name']),
        $approvedName,
        $eventGroup,
        $enabled ? 1 : 0,
        json_encode($cleanParameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $triggerIntent !== '' ? $triggerIntent : null,
        (int)$user['id'],
    ]);

    $destinationStmt = $pdo->prepare(
        'INSERT INTO tracking_event_destinations
         (client_id, event_key, platform, destination_event_name, mapping_type, settings, status, updated_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE destination_event_name = VALUES(destination_event_name),
             mapping_type = VALUES(mapping_type), settings = VALUES(settings), status = VALUES(status),
             updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()'
    );
    foreach ($destinationRoutes as $route) {
        $destinationStmt->execute([
            $clientId,
            $eventKey,
            $route['platform'],
            $route['event_name'],
            $route['mapping_type'],
            json_encode([
                'parameter_mappings' => $route['parameter_mappings'],
                'user_identifier_mappings' => $route['user_identifier_mappings'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $route['enabled'] ? 'active' : 'inactive',
            (int)$user['id'],
        ]);
    }
    $pdo->commit();

    api_respond(['ok' => true, 'data' => [
        'event_key' => $eventKey,
        'approved_event_name' => $approvedName,
        'enabled' => $enabled,
        'selected_parameters' => $cleanParameters,
        'trigger_intent' => $triggerIntent !== '' ? $triggerIntent : null,
        'event_group' => $eventGroup,
        'destinations' => $destinationRoutes,
        'status' => 'configured_not_live',
    ]]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Tracking event save failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'event_save_failed', 'message' => 'The event configuration could not be saved.'], 500);
}
