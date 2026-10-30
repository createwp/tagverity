<?php

declare(strict_types=1);

// Verifies the preview sanitiser against mutation-XSS style payloads.
// The output is re-parsed with Masterminds (an HTML5 parser) and must keep exactly one
// script (the preview script), no on* attributes and no javascript: URLs.
// Run with: php tests/SitePreviewRendererTest.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/SitePreviewRenderer.php';

use GTMAuditor\SitePreviewRenderer;
use Masterminds\HTML5;

function previewTestFail(string $message): void {
    fwrite(STDERR, "SitePreviewRendererTest FAILED: {$message}\n");
    exit(1);
}

/** @return \DOMElement[] */
function previewTestElements(\DOMDocument $dom): array {
    $elements = [];
    foreach ($dom->getElementsByTagName('*') as $element) {
        $elements[] = $element;
    }
    return $elements;
}

function previewTestLocalName(\DOMElement $element): string {
    return strtolower($element->localName ?? $element->nodeName);
}

$payloads = [
    'svg script' => '<svg><script>alert(1)</script></svg>',
    'noscript attribute break-out' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>',
    'form/math/mglyph mXSS' => '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
    'entity tab inside javascript URL' => '<a href=" jav&#x09;ascript:alert(1)">x</a>',
    'literal newline inside javascript URL' => "<a href=\"java\nscript:alert(1)\">x</a>",
    'svg xlink:href javascript URL' => '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
    'iframe srcdoc' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    'template contents' => '<template><img src=x onerror=alert(1)></template>',
];

$renderer = new SitePreviewRenderer();
$reparser = new HTML5();

foreach ($payloads as $label => $payload) {
    $output = $renderer->render($payload, 'https://example.com/');
    $dom = $reparser->loadHTML($output);
    $elements = previewTestElements($dom);

    // (a) exactly one script element, and it is the preview script.
    $scripts = array_values(array_filter($elements, static fn (\DOMElement $el): bool => previewTestLocalName($el) === 'script'));
    if (count($scripts) !== 1) {
        previewTestFail("{$label}: expected exactly one script element, found " . count($scripts) . "\nOutput:\n{$output}");
    }
    if (strpos($scripts[0]->textContent, 'tagverity') === false) {
        previewTestFail("{$label}: the only script is not the preview script\nOutput:\n{$output}");
    }

    foreach ($elements as $element) {
        foreach ($element->attributes as $attribute) {
            // (b) no on* attribute, by full name or local name.
            $name = strtolower($attribute->name);
            $local = strtolower($attribute->localName ?? $attribute->name);
            if (str_starts_with($name, 'on') || str_starts_with($local, 'on')) {
                previewTestFail("{$label}: on* attribute '{$attribute->name}' on <" . previewTestLocalName($element) . ">\nOutput:\n{$output}");
            }

            // (c) no href/src value starts with javascript: once whitespace and control characters are removed.
            if (in_array($name, ['href', 'src', 'xlink:href'], true) || in_array($local, ['href', 'src'], true)) {
                $normalised = preg_replace('/[\x00-\x20]/', '', $attribute->value) ?? '';
                if (stripos($normalised, 'javascript:') === 0) {
                    previewTestFail("{$label}: javascript: URL survived in '{$attribute->name}'\nOutput:\n{$output}");
                }
            }
        }
    }
}

// Positive case: ordinary markup keeps its attributes and text, and the base tag is injected once.
$output = $renderer->render('<button id="book" class="cta">Book now</button>', 'https://example.com/');
$dom = $reparser->loadHTML($output);
$buttons = array_values(array_filter(previewTestElements($dom), static fn (\DOMElement $el): bool => previewTestLocalName($el) === 'button'));
if (count($buttons) !== 1) {
    previewTestFail("positive: expected one button, found " . count($buttons) . "\nOutput:\n{$output}");
}
if ($buttons[0]->getAttribute('id') !== 'book' || $buttons[0]->getAttribute('class') !== 'cta') {
    previewTestFail("positive: button lost id or class\nOutput:\n{$output}");
}
if (trim($buttons[0]->textContent) !== 'Book now') {
    previewTestFail("positive: button text changed\nOutput:\n{$output}");
}
if (substr_count($output, '<base href="https://example.com/">') !== 1) {
    previewTestFail("positive: expected exactly one <base href=\"https://example.com/\">\nOutput:\n{$output}");
}

echo "SitePreviewRendererTest passed (" . count($payloads) . " payloads + positive case)\n";
exit(0);
