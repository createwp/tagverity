<?php
namespace GTMAuditor;

require_once __DIR__ . '/IdentifierProcessor.php';

final class EventIdentifierEnricher {
    public function __construct(private ?IdentifierProcessor $processor = null) {
        $this->processor ??= new IdentifierProcessor();
    }

    public function enrich(array $event, array $rawIdentifiers, array $privacy, bool $consentGranted, string $destination): array {
        $event = $this->stripRawIdentifiers($event);
        $event['user_data'] = [];
        $event['identifier_processing'] = [
            'normalisation_version' => IdentifierProcessor::VERSION,
            'consent_granted' => $consentGranted,
            'errors' => [],
        ];

        if (!$consentGranted || empty($privacy['hashed_pii_enabled'])) return $event;

        $processed = $this->processor->process(
            $rawIdentifiers,
            is_array($privacy['allowed_hashed_fields'] ?? null) ? $privacy['allowed_hashed_fields'] : [],
            $destination
        );
        $event['user_data'] = $processed['hashed'];
        $event['identifier_processing']['errors'] = $processed['errors'];
        return $event;
    }

    private function stripRawIdentifiers(array $event): array {
        $sensitiveKeys = array_fill_keys(array_filter(
            $this->processor->sensitiveKeys(),
            static fn(string $key): bool => $key !== 'name'
        ), true);

        foreach ($event as $key => $value) {
            $normalisedKey = is_string($key) ? strtolower(trim($key)) : '';
            if ($normalisedKey !== 'name' && isset($sensitiveKeys[$normalisedKey])) {
                unset($event[$key]);
                continue;
            }
            if (is_array($value)) $event[$key] = $this->stripRawIdentifiers($value);
        }
        return $event;
    }
}
