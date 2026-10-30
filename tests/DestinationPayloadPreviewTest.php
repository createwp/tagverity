<?php
require_once __DIR__ . '/../src/IdentifierProcessor.php';
require_once __DIR__ . '/../src/DestinationPayloadPreview.php';

use GTMAuditor\DestinationPayloadPreview;

$previewer = new DestinationPayloadPreview();
$event = [
    'approved_event_name' => 'generate_lead',
    'event_group' => 'lead',
    'trigger' => ['type' => 'element_click'],
    'tag_templates' => [[
        'platform' => 'meta',
        'destination_event_name' => 'Lead',
        'parameter_mappings' => [['canonical_name' => 'value', 'destination_name' => 'value']],
        'user_identifier_mappings' => [['canonical_name' => 'email', 'destination_name' => 'em']],
    ]],
];
$result = $previewer->build($event, 'meta', ['hashed_pii_enabled' => true, 'allowed_hashed_fields' => ['email']], [
    'status' => 'configured',
    'configuration' => ['pixel_id' => '123456789'],
]);
$hash = $result['destination_payload']['data'][0]['user_data']['em'] ?? '';
if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || str_contains(json_encode($result), 'Preview.User@example.com')) {
    fwrite(STDERR, "Payload previews must contain a hash and never expose raw identifiers.\n");
    exit(1);
}
if (($result['destination_payload']['data'][0]['event_name'] ?? '') !== 'Lead') {
    fwrite(STDERR, "Meta preview did not use the destination event name.\n");
    exit(1);
}

$manualEvent = [
    'approved_event_name' => 'generate_lead',
    'event_group' => 'lead',
    'trigger' => ['type' => 'element_click'],
    'manual_parameters' => [['name' => 'promo_code', 'value' => 'SPRING24']],
    'tag_templates' => [[
        'platform' => 'meta',
        'destination_event_name' => 'Lead',
        'parameter_mappings' => [['canonical_name' => 'promo_code', 'destination_name' => 'custom_data.promo_code']],
        'user_identifier_mappings' => [],
    ], [
        'platform' => 'ga4',
        'destination_event_name' => 'generate_lead',
        'parameter_mappings' => [['canonical_name' => 'promo_code', 'destination_name' => 'promo_code']],
        'user_identifier_mappings' => [],
    ]],
];
$metaManual = $previewer->build($manualEvent, 'meta', [], ['status' => 'configured', 'configuration' => ['pixel_id' => '123456789']]);
if (($metaManual['destination_payload']['data'][0]['custom_data']['promo_code'] ?? '') !== 'SPRING24') {
    fwrite(STDERR, "Meta manual parameters should be sent inside custom_data under their name.\n");
    exit(1);
}
$ga4Manual = $previewer->build($manualEvent, 'ga4', [], ['status' => 'configured', 'configuration' => ['measurement_id' => 'G-TEST']]);
if (($ga4Manual['destination_payload']['events'][0]['params']['promo_code'] ?? '') !== 'SPRING24'
    || ($ga4Manual['canonical_event']['parameters']['promo_code'] ?? '') !== 'SPRING24') {
    fwrite(STDERR, "GA4 manual parameters should carry their literal value under their name.\n");
    exit(1);
}

echo "DestinationPayloadPreview tests passed.\n";
