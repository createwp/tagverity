<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/SourceStatus.php';

use GTMAuditor\SourceStatus;

$cases = [
    'Google service 401 maps to authentication_failed' => [new \Google\Service\Exception('x', 401), 'authentication_failed'],
    'Google service 403 maps to permission_denied' => [new \Google\Service\Exception('x', 403), 'permission_denied'],
    'Google service 429 maps to quota_limited' => [new \Google\Service\Exception('x', 429), 'quota_limited'],
    'RuntimeException authentication_failed/401 maps to authentication_failed' => [new \RuntimeException('authentication_failed', 401), 'authentication_failed'],
    'Guzzle ConnectException maps to timed_out' => [new \GuzzleHttp\Exception\ConnectException('timeout', new \GuzzleHttp\Psr7\Request('GET', '/')), 'timed_out'],
    'Plain RuntimeException maps to unavailable' => [new \RuntimeException('boom'), 'unavailable'],
];

foreach ($cases as $label => [$throwable, $expected]) {
    $actual = SourceStatus::fromThrowable($throwable);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$label}: expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

echo "SourceStatusTest passed (" . count($cases) . " cases)\n";
exit(0);
