<?php
require_once __DIR__ . '/../src/AuditEngine.php';

use GTMAuditor\AuditEngine;

function domainFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function domainFindings(array $gtm, array $ga4, array $flagged): array
{
    $engine = new AuditEngine($gtm, $ga4, [], ['id' => 1], 1, '', [], $flagged);
    return $engine->run();
}

function domainTitled(array $findings, string $title): ?array
{
    foreach ($findings as $finding) {
        if (($finding['title'] ?? '') === $title) {
            return $finding;
        }
    }
    return null;
}

// Minimal GTM payload: one tag, so the engine runs its container checks.
$gtm = ['tags' => [['name' => 'GA4 Config', 'type' => 'gaawc', 'tagId' => '1', 'domainName' => ['example.com']]]];

// not_yours -> critical finding with the exact title, host as affected item.
$findings = domainFindings($gtm, [], [['host' => 'spam.example', 'confirmation' => 'not_yours']]);
$finding = domainTitled($findings, 'Domain marked as not yours');
if ($finding === null) {
    domainFail('not_yours: no "Domain marked as not yours" finding');
}
if ($finding['severity'] !== 'critical' || $finding['category'] !== 'data_quality') {
    domainFail('not_yours: wrong severity/category: ' . json_encode($finding));
}
if ($finding['fix_owner'] !== 'multiple' || $finding['affected_item'] !== 'spam.example') {
    domainFail('not_yours: wrong fix_owner/affected_item: ' . json_encode($finding));
}
if ($finding['description'] !== 'Someone on your team said spam.example is not one of your sites (or asked to look into it), but it is connected to your tracking. Data from it may be polluting your reports.') {
    domainFail('not_yours: wrong description: ' . $finding['description']);
}
if ($finding['recommendation'] !== "Check where this domain comes from. If it isn't yours, add it to GA4's unwanted referrals or filter it out, and remove it from your tags. If it is yours, mark it as yours on the Domain map.") {
    domainFail('not_yours: wrong recommendation: ' . $finding['recommendation']);
}
if (json_decode($finding['evidence'], true) !== ['host' => 'spam.example', 'confirmation' => 'not_yours']) {
    domainFail('not_yours: wrong evidence: ' . $finding['evidence']);
}
if ($finding['developer_notes'] !== 'Domain ownership answer recorded on the Domain map. Change the answer there if this was a mistake.') {
    domainFail('not_yours: wrong developer notes: ' . $finding['developer_notes']);
}

// investigate -> critical finding with the investigate title.
$findings = domainFindings($gtm, [], [['host' => 'maybe.example', 'confirmation' => 'investigate']]);
$finding = domainTitled($findings, 'Domain under investigation');
if ($finding === null) {
    domainFail('investigate: no "Domain under investigation" finding');
}
if ($finding['severity'] !== 'critical' || $finding['affected_item'] !== 'maybe.example') {
    domainFail('investigate: wrong severity/affected_item: ' . json_encode($finding));
}
if (!str_contains($finding['description'], 'Someone on your team said maybe.example is not one of your sites (or asked to look into it)')) {
    domainFail('investigate: description does not name the host: ' . $finding['description']);
}

// GA4 data alone also runs the domain check.
$findings = domainFindings([], ['hostname' => []], [['host' => 'spam.example', 'confirmation' => 'not_yours']]);
if (domainTitled($findings, 'Domain marked as not yours') === null) {
    domainFail('ga4-only data: domain finding missing');
}

// Empty GTM and GA4 data -> nothing ran, so no domain finding.
$findings = domainFindings([], [], [['host' => 'spam.example', 'confirmation' => 'not_yours']]);
if (domainTitled($findings, 'Domain marked as not yours') !== null) {
    domainFail('empty data: domain finding should not be emitted');
}

// No flagged domains -> no domain finding.
$findings = domainFindings($gtm, [], []);
foreach ($findings as $f) {
    if (in_array($f['title'], ['Domain marked as not yours', 'Domain under investigation'], true)) {
        domainFail('no flagged domains: unexpected domain finding');
    }
}

echo "AuditEngineDomainTest: all assertions passed\n";
