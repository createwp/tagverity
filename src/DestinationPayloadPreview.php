<?php
namespace GTMAuditor;

final class DestinationPayloadPreview {
    public function build(array $event, string $platform, array $privacy, array $connection): array {
        $route = null;
        foreach ($event['tag_templates'] ?? [] as $template) {
            if (($template['platform'] ?? '') === $platform) $route = $template;
        }
        if (!$route) throw new \InvalidArgumentException('destination_not_enabled_for_event');

        $manualValues = [];
        foreach ($event['manual_parameters'] ?? [] as $manual) {
            if (is_array($manual) && isset($manual['name'], $manual['value'])) $manualValues[(string)$manual['name']] = (string)$manual['value'];
        }

        $canonicalParameters = [];
        $destinationParameters = [];
        foreach ($route['parameter_mappings'] ?? [] as $mapping) {
            $canonical = (string)($mapping['canonical_name'] ?? '');
            $destination = (string)($mapping['destination_name'] ?? '');
            if ($canonical === '' || $destination === '') continue;
            // Manual literals are sent as typed; Meta nests them in custom_data, which is built from the flat map.
            $value = array_key_exists($canonical, $manualValues) ? $manualValues[$canonical] : $this->sampleParameter($canonical);
            $canonicalParameters[$canonical] = $value;
            $destinationKey = str_starts_with($destination, 'custom_data.') ? substr($destination, strlen('custom_data.')) : $destination;
            $destinationParameters[$destinationKey] = $value;
        }

        $destinationIdentifiers = [];
        $identifierSummary = [];
        $allowed = !empty($privacy['hashed_pii_enabled']) && is_array($privacy['allowed_hashed_fields'] ?? null)
            ? $privacy['allowed_hashed_fields'] : [];
        $sampleIdentifiers = ['email' => 'Preview.User@example.com ', 'phone' => '+61 400 000 000', 'first_name' => ' Preview ', 'last_name' => ' User ', 'full_name' => ' Preview User '];
        $processor = new IdentifierProcessor();
        foreach ($route['user_identifier_mappings'] ?? [] as $mapping) {
            $canonical = (string)($mapping['canonical_name'] ?? '');
            $destination = (string)($mapping['destination_name'] ?? '');
            if ($canonical === '' || $destination === '' || !in_array($canonical, $allowed, true)) continue;
            $processed = $processor->process($sampleIdentifiers, [$canonical], $platform);
            if (!isset($processed['hashed'][$canonical])) continue;
            $destinationIdentifiers[$destination] = $processed['hashed'][$canonical];
            $identifierSummary[] = ['field' => $canonical, 'destination_field' => $destination, 'transform' => 'normalise_sha256'];
        }

        $eventName = (string)($route['destination_event_name'] ?? $event['approved_event_name'] ?? 'event');
        $configuration = is_array($connection['configuration'] ?? null) ? $connection['configuration'] : [];
        $preview = $this->platformPayload($platform, $eventName, $destinationParameters, $destinationIdentifiers, $configuration);
        $warnings = [];
        if (($connection['status'] ?? 'not_configured') === 'not_configured') $warnings[] = 'Connect this destination before release testing can deliver an event.';
        if (($connection['status'] ?? '') === 'configured') $warnings[] = 'Credentials are saved but have not yet been verified with the destination.';

        return [
            'platform' => $platform,
            'event_name' => $eventName,
            'connection_status' => (string)($connection['status'] ?? 'not_configured'),
            'canonical_event' => [
                'event_name' => (string)($event['approved_event_name'] ?? ''),
                'event_group' => (string)($event['event_group'] ?? ''),
                'trigger' => (string)($event['trigger']['type'] ?? ''),
                'parameters' => $canonicalParameters,
                'user_identifiers' => $identifierSummary,
            ],
            'destination_payload' => $preview['payload'],
            'delivery_context' => $preview['context'],
            'warnings' => $warnings,
            'safe_preview' => true,
        ];
    }

    private function platformPayload(string $platform, string $eventName, array $parameters, array $identifiers, array $configuration): array {
        $eventId = 'preview_' . substr(hash('sha256', $platform . ':' . $eventName), 0, 16);
        if ($platform === 'ga4') {
            return ['payload' => ['client_id' => 'preview-client-id', 'events' => [['name' => $eventName, 'params' => $parameters]]], 'context' => ['measurement_id' => $configuration['measurement_id'] ?? 'not_configured']];
        }
        if ($platform === 'posthog') {
            $properties = $parameters;
            foreach ($identifiers as $key => $value) $properties['$set'][$key] = $value;
            return ['payload' => ['event' => $eventName, 'distinct_id' => 'preview-distinct-id', 'properties' => $properties], 'context' => ['host' => $configuration['host'] ?? 'not_configured']];
        }
        if ($platform === 'meta') {
            return ['payload' => ['data' => [[
                'event_name' => $eventName,
                'event_time' => '<runtime_unix_timestamp>',
                'event_id' => $eventId,
                'action_source' => 'website',
                'event_source_url' => 'https://example.com/preview',
                'user_data' => $identifiers,
                'custom_data' => $parameters,
            ]]], 'context' => ['pixel_id' => $configuration['pixel_id'] ?? 'not_configured']];
        }
        if ($platform === 'google_ads') {
            $userIdentifiers = [];
            foreach ($identifiers as $key => $value) $userIdentifiers[] = [$key => $value];
            $customerId = $configuration['customer_id'] ?? '<customer_id>';
            $actionId = $configuration['conversion_action_id'] ?? '<conversion_action_id>';
            return ['payload' => ['conversions' => [[
                'conversionAction' => 'customers/' . $customerId . '/conversionActions/' . $actionId,
                'conversionDateTime' => '<runtime_timestamp_with_timezone>',
                'conversionValue' => $parameters['conversion_value'] ?? null,
                'currencyCode' => $parameters['currency_code'] ?? null,
                'userIdentifiers' => $userIdentifiers,
            ]]], 'context' => ['customer_id' => $customerId, 'partial_failure' => true]];
        }
        throw new \InvalidArgumentException('unsupported_platform');
    }

    private function sampleParameter(string $parameter) {
        if ($parameter === 'value') return 120.50;
        if ($parameter === 'quantity') return 1;
        if ($parameter === 'currency') return 'AUD';
        if (in_array($parameter, ['page_url', 'destination_url'], true)) return 'https://example.com/preview';
        if (in_array($parameter, ['route_path', 'page_path'], true)) return '/preview';
        if (in_array($parameter, ['product_id', 'item_id'], true)) return 'SKU-123';
        if (in_array($parameter, ['transaction_id', 'order_id'], true)) return 'ORDER-123';
        if (in_array($parameter, ['gclid', 'wbraid', 'gbraid'], true)) return '<captured_click_id>';
        return 'sample_' . $parameter;
    }
}
