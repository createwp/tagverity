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

echo "DestinationPayloadPreview tests passed.\n";
