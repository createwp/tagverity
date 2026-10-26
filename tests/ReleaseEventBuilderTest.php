<?php
require_once __DIR__ . '/../src/ReleaseEventBuilder.php';

use GTMAuditor\ReleaseEventBuilder;

function assert_release_value($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " failed\nExpected: " . json_encode($expected) . "\nActual: " . json_encode($actual) . "\n");
        exit(1);
    }
}

$builder = new ReleaseEventBuilder();
$baseEvents = $builder->baseEvents([
    'destinations' => ['ga4', 'posthog'],
    'events' => [
        ['name' => 'page_view', 'parameters' => ['page_name', 'page_type']],
        ['name' => 'element_click', 'parameters' => ['element_name']],
        ['name' => 'form_start', 'when' => 'A visitor first interacts with a form.', 'parameters' => ['form_name']],
    ],
    'entities' => ['form_labels' => ['contact' => 'Contact us', 'quote' => 'Quote request']],
]);
assert_release_value(3, count($baseEvents), 'Recommended event count');
assert_release_value('page_view', $baseEvents[0]['event_template']['events'][0]['name'], 'Base page view');
assert_release_value(true, $baseEvents[0]['default_enabled'], 'Page view is included by default');
assert_release_value(false, $baseEvents[1]['default_enabled'], 'Additional recommendations require selection');
assert_release_value('element_click', $baseEvents[1]['trigger_template']['type'], 'Recommended click trigger');
assert_release_value('form_lifecycle', $baseEvents[2]['trigger_template']['type'], 'Recommended form trigger');
assert_release_value('2 named forms', $baseEvents[2]['trigger_template']['coverage_label'], 'Detected form coverage');
$selectedRecommendations = $builder->selectBaseEvents($baseEvents, [[
    'key' => 'base:form_start',
    'events' => [['source_name' => 'form_start', 'event_name' => 'form_start', 'selected_parameters' => ['form_name']]],
]]);
assert_release_value(1, count($selectedRecommendations), 'Selected recommendation count');
assert_release_value('form_start', $selectedRecommendations[0]['event_template']['events'][0]['name'], 'Selected recommendation event');
$unassignedRecommendations = $builder->baseEvents([
    'events' => [['name' => 'element_click', 'parameters' => ['element_name']]],
]);
assert_release_value('element_click', $unassignedRecommendations[0]['trigger_template']['type'], 'Click recommendation trigger');
assert_release_value('unassigned', $unassignedRecommendations[0]['tag_templates'][0]['platform'], 'Recommendation destination is not assumed');

$mapping = [
    'id' => 9,
    'event_template' => ['events' => [
        ['name' => 'form_start', 'parameters' => ['form_name']],
        ['name' => 'form_submit', 'parameters' => ['form_name']],
    ]],
    'tag_templates' => [[
        'platform' => 'ga4',
        'template' => 'GA4 Event',
        'events' => ['form_start', 'form_submit'],
    ]],
];
$selected = $builder->selectMappings([$mapping], [[
    'mapping_id' => 9,
    'events' => [['source_name' => 'form_submit', 'event_name' => 'generate_lead', 'selected_parameters' => []]],
]]);
assert_release_value(1, count($selected), 'Selected mapping count');
assert_release_value('generate_lead', $selected[0]['event_template']['events'][0]['name'], 'Edited event name');
assert_release_value('form_submit', $selected[0]['event_template']['events'][0]['source_name'], 'Original event name preserved');
assert_release_value([], $selected[0]['event_template']['events'][0]['parameters'], 'Parameter opt-out preserved');
assert_release_value(['generate_lead'], $selected[0]['tag_templates'][0]['events'], 'Destination event rename');
assert_release_value(false, $builder->validEventName('Click Event'), 'Invalid event name');
assert_release_value(true, $builder->validEventName('element_click'), 'Valid event name');

$invalidParameterRejected = false;
try {
    $builder->selectMappings([$mapping], [[
        'mapping_id' => 9,
        'events' => [['source_name' => 'form_submit', 'event_name' => 'form_submit', 'selected_parameters' => ['email']]],
    ]]);
} catch (InvalidArgumentException $e) {
    $invalidParameterRejected = $e->getMessage() === 'invalid_event_parameter';
}
assert_release_value(true, $invalidParameterRejected, 'Unknown parameter rejected');

echo "ReleaseEventBuilder tests passed.\n";
