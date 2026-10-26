<?php
require_once __DIR__ . '/../src/DestinationConnectionManager.php';

use GTMAuditor\DestinationConnectionManager;

$manager = new DestinationConnectionManager();
$definitions = $manager->definitions();
if (array_keys($definitions) !== ['ga4', 'posthog', 'meta', 'google_ads']) {
    fwrite(STDERR, "Destination definitions are incomplete.\n");
    exit(1);
}
foreach ($definitions as $platform => $definition) {
    if (empty($definition['docs_url']) || empty($definition['account_url']) || count($definition['setup_steps'] ?? []) !== 3) {
        fwrite(STDERR, "Destination guidance is incomplete for {$platform}.\n");
        exit(1);
    }
}

$ga4 = $manager->validate('ga4', ['measurement_id' => 'g-abc123', 'api_secret' => 'secret'], false);
if (($ga4['configuration']['measurement_id'] ?? '') !== 'G-ABC123' || ($ga4['secrets']['api_secret'] ?? '') !== 'secret') {
    fwrite(STDERR, "GA4 connection values were not separated or normalised.\n");
    exit(1);
}

$posthog = $manager->validate('posthog', ['host' => 'https://eu.i.posthog.com/', 'project_api_key' => 'phc_test'], false);
if (($posthog['configuration']['host'] ?? '') !== 'https://eu.i.posthog.com') {
    fwrite(STDERR, "PostHog host should be normalised.\n");
    exit(1);
}

$ads = $manager->validate('google_ads', ['customer_id' => '123-456-7890', 'conversion_action_id' => '987'], false);
if (($ads['configuration']['customer_id'] ?? '') !== '1234567890') {
    fwrite(STDERR, "Google Ads customer ID should be normalised.\n");
    exit(1);
}

try {
    $manager->validate('posthog', ['host' => 'http://example.com', 'project_api_key' => 'key'], false);
    fwrite(STDERR, "Insecure PostHog hosts should be rejected.\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    if ($e->getMessage() !== 'invalid_posthog_host') throw $e;
}

echo "DestinationConnectionManager tests passed.\n";
