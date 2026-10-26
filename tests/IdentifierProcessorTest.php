<?php
require_once __DIR__ . '/../src/IdentifierProcessor.php';

use GTMAuditor\IdentifierProcessor;

$processor = new IdentifierProcessor();

$result = $processor->process([
    'email' => '  Jane.Doe+Sale@Gmail.com  ',
    'phone' => '  +61 412 345 678 ',
    'name' => '  ERIC   DE KOCK ',
    'first_name' => '  ERIC  ',
    'last_name' => '  DE KOCK ',
    'message' => 'must never be processed',
], IdentifierProcessor::FIELDS, 'google_ads');

$expectedValues = [
    'email' => 'janedoe@gmail.com',
    'phone' => '+61412345678',
    'full_name' => 'eric de kock',
    'first_name' => 'eric',
    'last_name' => 'de kock',
];
foreach ($expectedValues as $field => $normalised) {
    if (($result['hashed'][$field] ?? '') !== hash('sha256', $normalised)) {
        fwrite(STDERR, 'Identifier normalisation failed for ' . $field . ".\n");
        exit(1);
    }
}
if (isset($result['hashed']['message'])) {
    fwrite(STDERR, "A non-allowlisted value reached the identifier payload.\n");
    exit(1);
}

$aliases = $processor->process([
    'emailAddress' => ' ALIAS@EXAMPLE.COM ',
    'email' => ' CANONICAL@EXAMPLE.COM ',
], ['email'], 'common');
if (($aliases['hashed']['email'] ?? '') !== hash('sha256', 'canonical@example.com')) {
    fwrite(STDERR, "Identifier source aliases were not canonicalised.\n");
    exit(1);
}

$invalidPhone = $processor->process(['phone' => '0412 345 678'], ['phone'], 'google_ads');
if (($invalidPhone['errors']['phone'] ?? '') !== 'e164_required' || isset($invalidPhone['hashed']['phone'])) {
    fwrite(STDERR, "A non-E.164 phone number was hashed.\n");
    exit(1);
}

echo "IdentifierProcessor tests passed.\n";
