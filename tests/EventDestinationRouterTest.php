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

// Manual parameters: ga4/posthog keep the name, meta uses custom_data, google_ads is unsupported.
$routeByPlatform = static function (array $routes): array {
    $byPlatform = [];
    foreach ($routes as $route) $byPlatform[$route['platform']] = $route;
    return $byPlatform;
};
$findParameter = static function (array $parameters, string $name): ?array {
    foreach ($parameters as $parameter) {
        if ($parameter['canonical_name'] === $name) return $parameter;
    }
    return null;
};

$manualOptions = $routeByPlatform($router->routeOptions([], [], 'book_a_call', 'lead', [], [], [], [['name' => 'promo_code', 'value' => 'SPRING24']]));
$ga4Option = $findParameter($manualOptions['ga4']['parameters'], 'promo_code');
$metaOption = $findParameter($manualOptions['meta']['parameters'], 'promo_code');
$adsOption = $findParameter($manualOptions['google_ads']['parameters'], 'promo_code');
if ($ga4Option !== ['canonical_name' => 'promo_code', 'destination_name' => 'promo_code', 'selected' => false, 'manual' => true, 'supported' => true]
    || ($metaOption['destination_name'] ?? '') !== 'custom_data.promo_code' || ($metaOption['supported'] ?? false) !== true
    || !is_array($adsOption) || !array_key_exists('destination_name', $adsOption) || $adsOption['destination_name'] !== null || $adsOption['supported'] !== false) {
    fwrite(STDERR, "Manual parameter options should carry destination names and supported flags per platform.\n");
    exit(1);
}
$standardRoutes = $routeByPlatform($router->routeOptions([], [], 'book_a_call', 'lead', ['destination_url'], []));
$standardOption = $findParameter($standardRoutes['ga4']['parameters'], 'destination_url');
if (($standardOption['manual'] ?? true) !== false || ($standardOption['supported'] ?? false) !== true) {
    fwrite(STDERR, "Standard parameter options should be marked as not manual and supported.\n");
    exit(1);
}

$savedManualRoutes = $routeByPlatform($router->routeOptions([[
    'platform' => 'meta',
    'status' => 'active',
    'destination_event_name' => 'Lead',
    'settings' => ['parameter_mappings' => [['canonical_name' => 'promo_code', 'destination_name' => 'custom_data.promo_code']]],
]], [], 'book_a_call', 'lead', [], [], [], [['name' => 'promo_code', 'value' => 'SPRING24']]));
if ((($findParameter($savedManualRoutes['meta']['parameters'], 'promo_code')['selected']) ?? false) !== true) {
    fwrite(STDERR, "A saved manual parameter mapping should show as selected.\n");
    exit(1);
}

$manualRoutes = $router->normaliseInput([
    ['platform' => 'ga4', 'enabled' => true, 'event_name' => 'generate_lead', 'parameters' => ['promo_code']],
    ['platform' => 'meta', 'enabled' => true, 'event_name' => 'Lead', 'parameters' => ['promo_code']],
], 'book_a_call', 'lead', [], [], ['promo_code']);
$manualRouteByPlatform = $routeByPlatform($manualRoutes);
if (($manualRouteByPlatform['ga4']['parameter_mappings'][0]['destination_name'] ?? '') !== 'promo_code'
    || ($manualRouteByPlatform['meta']['parameter_mappings'][0]['destination_name'] ?? '') !== 'custom_data.promo_code') {
    fwrite(STDERR, "Manual parameters should map to promo_code for GA4 and custom_data.promo_code for Meta.\n");
    exit(1);
}

try {
    $router->normaliseInput([['platform' => 'google_ads', 'enabled' => true, 'event_name' => 'Book A Call', 'parameters' => ['promo_code']]], 'book_a_call', 'lead', [], [], ['promo_code']);
    fwrite(STDERR, "Manual parameters should be rejected for Google Ads.\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    if ($e->getMessage() !== 'unsupported_destination_parameter') throw $e;
}

try {
    $router->normaliseInput([['platform' => 'ga4', 'enabled' => true, 'event_name' => 'generate_lead', 'parameters' => ['promo_code']]], 'book_a_call', 'lead', [], [], []);
    fwrite(STDERR, "A manual parameter absent from the cleaned manual list should be rejected.\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    if ($e->getMessage() !== 'invalid_destination_parameter') throw $e;
}

echo "EventDestinationRouter tests passed.\n";
