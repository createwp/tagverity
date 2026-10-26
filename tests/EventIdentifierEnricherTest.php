<?php
require_once __DIR__ . '/../src/EventIdentifierEnricher.php';

use GTMAuditor\EventIdentifierEnricher;

$enricher = new EventIdentifierEnricher();
$raw = ['email' => ' Test@Example.com ', 'first_name' => ' ERIC ', 'message' => 'private message'];
$privacy = ['hashed_pii_enabled' => true, 'allowed_hashed_fields' => ['email', 'first_name']];
$event = $enricher->enrich([
    'event_name' => 'generate_lead',
    'email' => 'raw@example.com',
    'parameters' => ['first_name' => 'Raw', 'form_name' => 'Contact us'],
], $raw, $privacy, true, 'google_ads');

if (($event['user_data']['email'] ?? '') !== hash('sha256', 'test@example.com')) {
    fwrite(STDERR, "Actual event email was not normalised and hashed.\n");
    exit(1);
}
if (($event['user_data']['first_name'] ?? '') !== hash('sha256', 'eric')) {
    fwrite(STDERR, "Actual event first name was not normalised and hashed.\n");
    exit(1);
}
$encoded = json_encode($event);
foreach ($raw as $value) {
    if (str_contains($encoded, trim($value))) {
        fwrite(STDERR, "A raw identifier leaked into the enriched event.\n");
        exit(1);
    }
}
if (isset($event['email']) || isset($event['parameters']['first_name']) || ($event['parameters']['form_name'] ?? '') !== 'Contact us') {
    fwrite(STDERR, "Raw identifier fields were not removed from the actual event payload.\n");
    exit(1);
}

$withoutConsent = $enricher->enrich(['event_name' => 'generate_lead'], $raw, $privacy, false, 'google_ads');
if ($withoutConsent['user_data'] !== []) {
    fwrite(STDERR, "Identifiers were added without consent.\n");
    exit(1);
}

echo "EventIdentifierEnricher tests passed.\n";
