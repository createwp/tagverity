<?php
require_once __DIR__ . '/../src/EventDestinationRouter.php';

use GTMAuditor\EventDestinationRouter;

$router = new EventDestinationRouter();
$routes = $router->routeOptions([], [[
    'platform' => 'posthog',
    'template' => 'PostHog Capture',
]], 'book_a_call', 'lead');

$byPlatform = [];
foreach ($routes as $route) $byPlatform[$route['platform']] = $route;

if (!$byPlatform['posthog']['enabled'] || $byPlatform['posthog']['event_name'] !== 'book_a_call') {
    fwrite(STDERR, "PostHog should inherit the canonical event name.\n");
    exit(1);
}
if ($byPlatform['meta']['event_name'] !== 'Lead' || $byPlatform['google_ads']['event_name'] !== 'Book A Call') {
    fwrite(STDERR, "Destination defaults do not match the lead classification.\n");
    exit(1);
}
if (!in_array('generate_lead', $byPlatform['ga4']['recommendations'], true)
    || !in_array('Lead', $byPlatform['meta']['recommendations'], true)) {
    fwrite(STDERR, "Destination recommendations should follow the business classification.\n");
    exit(1);
}

$normalised = $router->normaliseInput([
    ['platform' => 'ga4', 'enabled' => true, 'event_name' => 'generate_lead', 'parameters' => ['destination_url']],
    ['platform' => 'meta', 'enabled' => true, 'event_name' => 'Lead', 'user_identifiers' => ['email']],
], 'book_a_call', 'lead', ['destination_url'], ['email']);
$tags = $router->tagTemplates($normalised, 'element_click', 'lead');
$activePlatforms = array_column($tags, 'platform');
sort($activePlatforms);
if ($activePlatforms !== ['ga4', 'meta']) {
    fwrite(STDERR, "Only selected destinations should produce delivery templates.\n");
    exit(1);
}
if (($tags[0]['parameter_mappings'][0]['destination_name'] ?? '') !== 'link_url') {
    fwrite(STDERR, "GA4 destination_url should map to link_url.\n");
    exit(1);
}
if (($tags[1]['user_identifier_mappings'][0]['destination_name'] ?? '') !== 'em'
    || ($tags[1]['user_identifier_mappings'][0]['transform'] ?? '') !== 'normalise_sha256') {
    fwrite(STDERR, "Meta email should map to a normalized SHA-256 em identifier.\n");
    exit(1);
}

$identifierRoutes = $router->routeOptions([], [], 'book_a_call', 'lead', [], [], [
    'hashed_pii_enabled' => true,
    'allowed_hashed_fields' => ['email', 'phone', 'first_name'],
]);
$identifierPlatforms = [];
foreach ($identifierRoutes as $route) $identifierPlatforms[$route['platform']] = $route['user_identifiers'];
if ($identifierPlatforms['ga4'] !== [] || count($identifierPlatforms['google_ads']) !== 2 || count($identifierPlatforms['meta']) !== 3) {
    fwrite(STDERR, "Destination identifier availability does not follow platform privacy rules.\n");
    exit(1);
}

try {
    $router->normaliseInput([['platform' => 'ga4', 'enabled' => true, 'event_name' => 'Invalid Name']], 'book_a_call', 'lead');
    fwrite(STDERR, "Invalid GA4 names should be rejected.\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    if ($e->getMessage() !== 'invalid_ga4_event_name') throw $e;
}

echo "EventDestinationRouter tests passed.\n";
