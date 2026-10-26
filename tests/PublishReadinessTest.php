<?php
require_once __DIR__ . '/../src/PublishReadiness.php';

use GTMAuditor\PublishReadiness;

$readiness = (new PublishReadiness())->evaluate([[
    'trigger_scope' => 'page',
    'trigger_template' => ['type' => 'element_click'],
    'event_template' => ['events' => [['name' => 'element_click']]],
    'tag_templates' => [[
        'platform' => 'unassigned',
        'template' => 'Destination not selected',
        'events' => ['element_click'],
    ]],
]], [
    'google_connections' => 0,
    'gtm_containers' => 0,
    'gtm_write_scope' => false,
    'ga4_streams' => 0,
    'ga4_configured' => false,
    'posthog_configured' => false,
    'google_ads_configured' => false,
    'meta_configured' => false,
    'consent_policy_configured' => false,
    'deployment_adapter_ready' => false,
]);

$blockerIds = array_column($readiness['blockers'], 'id');
if (!in_array('destination_assignment', $blockerIds, true)) {
    fwrite(STDERR, "An unassigned destination did not block publication.\n");
    exit(1);
}
if (!in_array('unassigned', $readiness['platforms'], true)) {
    fwrite(STDERR, "The unassigned destination was not reported.\n");
    exit(1);
}

echo "PublishReadiness tests passed.\n";
