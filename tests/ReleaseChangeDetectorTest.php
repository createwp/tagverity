<?php
require_once __DIR__ . '/../src/ReleaseChangeDetector.php';

use GTMAuditor\ReleaseChangeDetector;

function releaseItem(string $key, string $name, string $configuredName, string $parameter = 'page_name'): array {
    return [
        'key' => $key,
        'element_name' => 'Site page views',
        'trigger_scope' => 'global',
        'trigger_template' => ['type' => 'page_view'],
        'tag_templates' => [['platform' => 'ga4', 'template' => 'GA4 Event', 'events' => [$name]]],
        'event_template' => ['events' => [[
            'event_key' => $key,
            'name' => $name,
            'configured_name' => $configuredName,
            'parameters' => [$parameter],
            'selected_parameters' => [$parameter],
            'enabled' => true,
        ]]],
    ];
}

$detector = new ReleaseChangeDetector();
$currentUnchanged = releaseItem('base:page_view', 'page_view', 'page_view');
$currentModified = releaseItem('base:lead', 'generate_lead', 'qualified_lead');
$currentNew = releaseItem('base:search', 'search', 'search');
$baselineUnchanged = releaseItem('base:page_view', 'page_view', 'page_view');
$baselineModified = releaseItem('base:lead', 'generate_lead', 'generate_lead');
foreach ([$baselineUnchanged, $baselineModified] as &$item) {
    foreach ($item['event_template']['events'] as &$event) $event['name'] = $event['configured_name'];
}
unset($item, $event);

$result = $detector->detect(
    [$currentUnchanged, $currentModified, $currentNew],
    [],
    ['base_events' => [$baselineUnchanged, $baselineModified], 'mappings' => []]
);

if ($result['summary'] !== ['new' => 1, 'modified' => 1, 'total' => 2]) {
    fwrite(STDERR, "Release change counts are incorrect.\n");
    exit(1);
}
if (count($result['base_events']) !== 2) {
    fwrite(STDERR, "Unchanged events were not removed from release review.\n");
    exit(1);
}

$firstRelease = $detector->detect([$currentUnchanged, $currentModified], [], []);
if ($firstRelease['summary']['new'] !== 2 || $firstRelease['summary']['modified'] !== 0) {
    fwrite(STDERR, "First release items should all be new.\n");
    exit(1);
}

echo "ReleaseChangeDetector tests passed.\n";
