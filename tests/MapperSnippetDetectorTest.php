<?php
require_once __DIR__ . '/../src/MapperSnippetDetector.php';
use GTMAuditor\MapperSnippetDetector;

function assert_detector($expected, $actual, string $label): void {
    if ($expected !== $actual) { fwrite(STDERR, $label . " failed\n"); exit(1); }
}

$detector = new MapperSnippetDetector();
assert_detector(true, $detector->detect('<head><script src="https://app.tagverity.com/assets/js/mapper-snippet.js" data-tagverity-api="https://app.tagverity.com" async></script></head>'), 'Detects installed snippet');
assert_detector(false, $detector->detect('<head><script src="https://app.tagverity.com/assets/js/app.js"></script></head>'), 'Does not false-positive on unrelated script');
echo "MapperSnippetDetector tests passed.\n";
