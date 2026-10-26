<?php
require_once __DIR__ . '/../src/ElementTemplateBuilder.php';

use GTMAuditor\ElementTemplateBuilder;

function assert_builder_value($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " failed\nExpected: " . json_encode($expected) . "\nActual: " . json_encode($actual) . "\n");
        exit(1);
    }
}

$builder = new ElementTemplateBuilder();
$base = [
    'element_name' => 'Book a consultation',
    'tracking_intent' => 'click',
    'measurement_goal' => 'product_analytics',
    'css_selector' => '.book-consultation',
    'page_url' => 'https://example.com/',
    'data_key' => null,
];

$page = $builder->build($base + ['trigger_scope' => 'page'], ['ga4']);
$global = $builder->build($base + ['trigger_scope' => 'global'], ['ga4']);
$unassigned = $builder->build($base + ['trigger_scope' => 'page'], []);

assert_builder_value('page', $page['trigger_template']['scope'], 'Page trigger scope');
assert_builder_value('https://example.com/', $page['trigger_template']['page_url'], 'Page URL restriction');
assert_builder_value('global', $global['trigger_template']['scope'], 'Global trigger scope');
assert_builder_value(null, $global['trigger_template']['page_url'], 'Global URL restriction removal');
assert_builder_value('sample_multiple_pages', $global['trigger_template']['verification_requirement'], 'Global verification requirement');
assert_builder_value('{{runtime_page_url}}', $global['event_template']['shared_parameters']['page_url'], 'Global runtime URL');
assert_builder_value('element_click', $unassigned['trigger_template']['type'], 'Unassigned tag keeps click trigger');
assert_builder_value('unassigned', $unassigned['tag_templates'][0]['platform'], 'Missing destination is explicit');
assert_builder_value('Destination not selected', $unassigned['tag_templates'][0]['template'], 'Missing destination label');

echo "ElementTemplateBuilder tests passed.\n";
