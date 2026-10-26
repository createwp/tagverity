<?php
require_once __DIR__ . '/../src/SiteScanner.php';

use GTMAuditor\SiteScanner;

function assert_same($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " failed\nExpected: " . json_encode($expected) . "\nActual: " . json_encode($actual) . "\n");
        exit(1);
    }
}

$scanner = new SiteScanner();
$parsePage = new ReflectionMethod($scanner, 'parsePage');
$summarise = new ReflectionMethod($scanner, 'summarise');
$canonicalPageUrl = new ReflectionMethod($scanner, 'canonicalPageUrl');
$parsePage->setAccessible(true);
$summarise->setAccessible(true);
$canonicalPageUrl->setAccessible(true);

$serviceHtml = <<<'HTML'
<!doctype html><html><head>
<title>Marketing Attribution | CreateWP</title>
<script type="application/ld+json">
{"@graph":[
  {"@type":"WebPage","name":"Marketing Attribution | CreateWP"},
  {"@type":"BreadcrumbList","itemListElement":[
    {"@type":"ListItem","position":1,"name":"Home"},
    {"@type":"ListItem","position":2,"name":"Marketing Attribution &amp; Reporting"}
  ]},
  {"@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Do I need GA4?"}]}
]}
</script></head><body>
<h1>Marketing Attribution &amp; Reporting</h1>
<form method="post"><input name="first_name"><input type="email" name="email"></form>
</body></html>
HTML;

$productHtml = <<<'HTML'
<!doctype html><html><head>
<title>Magical UTM</title>
<script type="application/ld+json">
{"@graph":[
  {"@type":"SoftwareApplication","name":"Magical UTM"},
  {"@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Is it free?"}]}
]}
</script></head><body><h1>Magical UTM Builder</h1></body></html>
HTML;

$servicePage = $parsePage->invoke($scanner, 'https://createwp.com.au/services/marketing-attribution-reporting', $serviceHtml);
$productPage = $parsePage->invoke($scanner, 'https://createwp.com.au/products/magical-utm', $productHtml);
$summary = $summarise->invoke($scanner, [$servicePage, $servicePage, $productPage]);

assert_same('https://createwp.com.au/', $canonicalPageUrl->invoke($scanner, 'https://CREATEWP.com.au/?source=sitemap'), 'Root URL canonicalisation');
assert_same('https://createwp.com.au/services/example', $canonicalPageUrl->invoke($scanner, 'https://createwp.com.au/services/example/?ref=test'), 'Page URL canonicalisation');
assert_same(1, count($summary['forms']), 'Duplicate form removal');
assert_same(['Marketing Attribution & Reporting'], $summary['services'], 'Service name filtering');
assert_same(['Magical UTM'], $summary['products'], 'Product name filtering');
assert_same('', $summary['forms'][0]['form_id'], 'Form ID capture');
assert_same(['first_name', 'email'], $summary['forms'][0]['field_names'], 'Form field capture');

echo "SiteScanner regression tests passed.\n";
