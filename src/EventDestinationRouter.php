<?php
namespace GTMAuditor;

final class EventDestinationRouter {
    private const PLATFORMS = [
        'ga4' => ['label' => 'GA4', 'template' => 'GA4 Event', 'field_label' => 'GA4 event name', 'required' => ['measurement_id']],
        'posthog' => ['label' => 'PostHog', 'template' => 'PostHog Capture', 'field_label' => 'PostHog event name', 'required' => ['project_api_key', 'host']],
        'meta' => ['label' => 'Meta', 'template' => 'Meta Conversions API / Pixel', 'field_label' => 'Meta event mapping', 'required' => ['pixel_id', 'access_token']],
        'google_ads' => ['label' => 'Google Ads', 'template' => 'Google Ads Conversion', 'field_label' => 'Conversion action name', 'required' => ['customer_id', 'conversion_action_id', 'google_connection']],
    ];

    private const GROUPS = [
        'lead' => 'Lead',
        'purchase' => 'Purchase',
        'intent' => 'Intent',
        'engagement' => 'Engagement',
        'interaction' => 'Interaction',
        'lifecycle' => 'Lifecycle',
        'other' => 'Other',
    ];

    private const META_STANDARD = ['Lead', 'Purchase', 'ViewContent', 'Contact', 'CompleteRegistration', 'AddToCart', 'InitiateCheckout', 'Search'];

    public function groups(): array {
        return self::GROUPS;
    }

    public function validGroup(string $group): bool {
        return isset(self::GROUPS[$group]);
    }

    public function defaultGroup(string $eventName): string {
        if ($eventName === 'purchase' || str_contains($eventName, 'checkout') || str_contains($eventName, 'cart')) return 'purchase';
        if (in_array($eventName, ['form_submit', 'generate_lead', 'sign_up'], true)) return 'lead';
        if (str_starts_with($eventName, 'form_')) return 'lifecycle';
        if (str_contains($eventName, 'view') || str_contains($eventName, 'search')) return 'engagement';
        if (str_contains($eventName, 'click') || str_contains($eventName, 'interaction')) return 'interaction';
        return 'intent';
    }

    public function routeOptions(array $savedRoutes, array $defaultTemplates, string $canonicalName, string $eventGroup, array $availableParameters = [], array $selectedParameters = [], array $privacy = [], array $manualParameters = []): array {
        $configured = count($savedRoutes) > 0;
        $defaults = [];
        foreach ($defaultTemplates as $template) {
            $platform = (string)($template['platform'] ?? '');
            if (isset(self::PLATFORMS[$platform])) $defaults[$platform] = true;
        }
        $saved = [];
        foreach ($savedRoutes as $route) $saved[(string)($route['platform'] ?? '')] = $route;

        $options = [];
        foreach (self::PLATFORMS as $platform => $definition) {
            $route = $saved[$platform] ?? [];
            $eventName = trim((string)($route['destination_event_name'] ?? ''));
            if ($eventName === '') $eventName = $this->defaultName($platform, $canonicalName, $eventGroup);
            $routeSettings = is_array($route['settings'] ?? null) ? $route['settings'] : [];
            $savedMappings = is_array($routeSettings['parameter_mappings'] ?? null) ? $routeSettings['parameter_mappings'] : [];
            $options[] = [
                'platform' => $platform,
                'label' => $definition['label'],
                'field_label' => $definition['field_label'],
                'enabled' => $configured ? (($route['status'] ?? 'inactive') === 'active') : isset($defaults[$platform]),
                'event_name' => $eventName,
                'mapping_type' => $platform === 'meta' && in_array($eventName, self::META_STANDARD, true) ? 'standard' : 'custom',
                'recommendations' => $this->recommendations($platform, $canonicalName, $eventGroup),
                'recommendations_by_group' => $this->recommendationsByGroup($platform, $canonicalName),
                'format_help' => $this->formatHelp($platform),
                'max_length' => $platform === 'ga4' ? 40 : ($platform === 'google_ads' ? 100 : 80),
                'parameters' => $this->parameterOptions($platform, $availableParameters, $selectedParameters, $savedMappings, array_key_exists('parameter_mappings', $routeSettings), $manualParameters),
                'user_identifiers' => $this->identifierOptions($platform, $privacy, $routeSettings),
                'identifier_notice' => $this->identifierNotice($platform, $privacy),
            ];
        }
        return $options;
    }

    public function normaliseInput(array $input, string $canonicalName, string $eventGroup, array $allowedParameters = [], array $allowedIdentifiers = [], array $manualParameterNames = []): array {
        if (!$this->validGroup($eventGroup) || count($input) > count(self::PLATFORMS)) {
            throw new \InvalidArgumentException('invalid_destination_configuration');
        }
        $routes = [];
        foreach ($input as $route) {
            if (!is_array($route)) throw new \InvalidArgumentException('invalid_destination_configuration');
            $platform = trim((string)($route['platform'] ?? ''));
            if (!isset(self::PLATFORMS[$platform]) || isset($routes[$platform])) throw new \InvalidArgumentException('invalid_destination_configuration');
            $eventName = trim((string)($route['event_name'] ?? ''));
            if ($eventName === '') $eventName = $this->defaultName($platform, $canonicalName, $eventGroup);
            if (!$this->validName($platform, $eventName)) throw new \InvalidArgumentException('invalid_' . $platform . '_event_name');
            $requestedParameters = is_array($route['parameters'] ?? null) ? $route['parameters'] : [];
            $parameterMappings = [];
            foreach ($requestedParameters as $parameter) {
                if (!is_string($parameter) || !in_array($parameter, array_merge($allowedParameters, $manualParameterNames), true)) throw new \InvalidArgumentException('invalid_destination_parameter');
                $destinationName = in_array($parameter, $manualParameterNames, true)
                    ? $this->manualDestinationName($platform, $parameter)
                    : $this->destinationParameterName($platform, $parameter);
                if ($destinationName === null) throw new \InvalidArgumentException('unsupported_destination_parameter');
                $parameterMappings[] = ['canonical_name' => $parameter, 'destination_name' => $destinationName];
            }
            $requestedIdentifiers = is_array($route['user_identifiers'] ?? null) ? $route['user_identifiers'] : [];
            $identifierMappings = [];
            foreach ($requestedIdentifiers as $identifier) {
                if (!is_string($identifier) || !in_array($identifier, $allowedIdentifiers, true)) throw new \InvalidArgumentException('identifier_not_allowed_by_privacy_policy');
                $destinationName = $this->destinationIdentifierName($platform, $identifier);
                if ($destinationName === null) throw new \InvalidArgumentException('unsupported_destination_identifier');
                $identifierMappings[] = ['canonical_name' => $identifier, 'destination_name' => $destinationName, 'transform' => 'normalise_sha256'];
            }
            $routes[$platform] = [
                'platform' => $platform,
                'enabled' => filter_var($route['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'event_name' => $eventName,
                'mapping_type' => $platform === 'meta' && in_array($eventName, self::META_STANDARD, true) ? 'standard' : 'custom',
                'parameter_mappings' => $parameterMappings,
                'user_identifier_mappings' => $identifierMappings,
            ];
        }
        foreach (array_keys(self::PLATFORMS) as $platform) {
            if (!isset($routes[$platform])) {
                $routes[$platform] = ['platform' => $platform, 'enabled' => false, 'event_name' => $this->defaultName($platform, $canonicalName, $eventGroup), 'mapping_type' => 'custom', 'parameter_mappings' => [], 'user_identifier_mappings' => []];
            }
        }
        return array_values($routes);
    }

    public function tagTemplates(array $routes, string $sourceName, string $eventGroup): array {
        $templates = [];
        foreach ($routes as $route) {
            $platform = (string)($route['platform'] ?? '');
            if (empty($route['enabled']) || !isset(self::PLATFORMS[$platform])) continue;
            $definition = self::PLATFORMS[$platform];
            $templates[] = [
                'platform' => $platform,
                'template' => $definition['template'],
                'events' => [$sourceName],
                'destination_event_name' => (string)$route['event_name'],
                'event_group' => $eventGroup,
                'mapping_type' => (string)($route['mapping_type'] ?? 'custom'),
                'parameter_mappings' => $route['parameter_mappings'] ?? [],
                'user_identifier_mappings' => $route['user_identifier_mappings'] ?? [],
                'configuration_required' => $definition['required'],
            ];
        }
        if (!$templates) {
            $templates[] = ['platform' => 'unassigned', 'template' => 'Destination not selected', 'events' => [$sourceName], 'event_group' => $eventGroup, 'configuration_required' => ['destination']];
        }
        return $templates;
    }

    private function defaultName(string $platform, string $canonicalName, string $eventGroup): string {
        if ($platform === 'meta') {
            return ['lead' => 'Lead', 'purchase' => 'Purchase', 'intent' => 'Contact', 'engagement' => 'ViewContent', 'lifecycle' => 'CompleteRegistration'][$eventGroup] ?? $canonicalName;
        }
        if ($platform === 'google_ads') return ucwords(str_replace('_', ' ', $canonicalName));
        return $canonicalName;
    }

    private function recommendationsByGroup(string $platform, string $canonicalName): array {
        $recommendations = [];
        foreach (array_keys(self::GROUPS) as $group) {
            $recommendations[$group] = $this->recommendations($platform, $canonicalName, $group);
        }
        return $recommendations;
    }

    private function recommendations(string $platform, string $canonicalName, string $eventGroup): array {
        $title = ucwords(str_replace('_', ' ', $canonicalName));
        if ($platform === 'ga4') {
            $values = [
                'lead' => ['generate_lead', 'sign_up'],
                'purchase' => ['purchase', 'add_to_cart', 'begin_checkout'],
                'intent' => ['generate_lead', 'select_content'],
                'engagement' => ['page_view', 'view_item', 'view_search_results', 'select_content'],
                'interaction' => ['select_content', 'element_click'],
                'lifecycle' => ['form_start', 'form_submit', 'generate_lead'],
                'other' => [],
            ][$eventGroup] ?? [];
        } elseif ($platform === 'posthog') {
            $values = [
                'lead' => ['lead_generated', 'form_submitted'],
                'purchase' => ['purchase_completed', 'checkout_started', 'product_added_to_cart'],
                'intent' => ['cta_clicked', 'contact_started'],
                'engagement' => ['content_viewed', 'page_viewed'],
                'interaction' => ['element_clicked', 'element_viewed'],
                'lifecycle' => ['form_started', 'form_submitted', 'form_abandoned'],
                'other' => [],
            ][$eventGroup] ?? [];
        } elseif ($platform === 'meta') {
            $values = [
                'lead' => ['Lead', 'Contact', 'CompleteRegistration'],
                'purchase' => ['Purchase', 'AddToCart', 'InitiateCheckout'],
                'intent' => ['Contact', 'Lead'],
                'engagement' => ['ViewContent', 'Search'],
                'interaction' => ['ViewContent'],
                'lifecycle' => ['CompleteRegistration', 'Lead', 'Contact'],
                'other' => [],
            ][$eventGroup] ?? [];
        } else {
            $values = [
                'lead' => ['Lead - ' . $title, 'Contact - ' . $title, 'Qualified Lead - ' . $title],
                'purchase' => [$title, 'Begin Checkout - ' . $title, 'Add To Cart - ' . $title],
                'intent' => ['Intent - ' . $title, 'Outbound Click - ' . $title],
                'engagement' => [$title, 'Engagement - ' . $title],
                'interaction' => [$title, 'Interaction - ' . $title],
                'lifecycle' => [$title, 'Form Progress - ' . $title, 'Submit Lead Form - ' . $title],
                'other' => [$title],
            ][$eventGroup] ?? [$title];
        }
        $defaults = [$this->defaultName($platform, $canonicalName, $eventGroup)];
        if ($platform !== 'google_ads') $defaults[] = $canonicalName;
        return array_values(array_unique(array_merge($defaults, $values)));
    }

    private function formatHelp(string $platform): string {
        if ($platform === 'ga4') return 'Starts with a letter; lowercase letters, numbers and underscores only; maximum 40 characters.';
        if ($platform === 'posthog') return 'TagVerity standard: lowercase snake_case; maximum 80 characters.';
        if ($platform === 'meta') return 'Choose a Meta standard event or use a custom letter-led name without spaces.';
        return 'A unique, human-readable Google Ads conversion action name; maximum 100 characters.';
    }

    private function parameterOptions(string $platform, array $available, array $selected, array $savedMappings, bool $configured, array $manualParameters = []): array {
        $saved = [];
        foreach ($savedMappings as $mapping) {
            if (is_array($mapping) && isset($mapping['canonical_name'])) $saved[(string)$mapping['canonical_name']] = true;
        }
        $options = [];
        foreach (array_values(array_unique(array_filter($available, 'is_string'))) as $parameter) {
            $destinationName = $this->destinationParameterName($platform, $parameter);
            if ($destinationName === null) continue;
            $options[] = [
                'canonical_name' => $parameter,
                'destination_name' => $destinationName,
                'selected' => $configured ? isset($saved[$parameter]) : in_array($parameter, $selected, true),
                'manual' => false,
                'supported' => true,
            ];
        }
        foreach ($manualParameters as $manual) {
            $name = is_array($manual) ? (string)($manual['name'] ?? '') : '';
            if ($name === '') continue;
            $options[] = [
                'canonical_name' => $name,
                'destination_name' => $this->manualDestinationName($platform, $name),
                'selected' => $configured && isset($saved[$name]),
                'manual' => true,
                'supported' => $platform !== 'google_ads',
            ];
        }
        return $options;
    }

    /** Custom literal parameters: GA4 and PostHog keep the name, Meta nests it in custom_data, Google Ads cannot take it. */
    private function manualDestinationName(string $platform, string $name): ?string {
        if ($platform === 'google_ads') return null;
        if ($platform === 'meta') return 'custom_data.' . $name;
        return $name;
    }

    private function destinationParameterName(string $platform, string $parameter): ?string {
        if ($platform === 'ga4') {
            return ['destination_url' => 'link_url', 'element_name' => 'link_text', 'product_id' => 'item_id', 'product_name' => 'item_name'][$parameter] ?? $parameter;
        }
        if ($platform === 'posthog') {
            return ['route_path' => '$pathname', 'page_url' => '$current_url', 'page_name' => '$title'][$parameter] ?? $parameter;
        }
        if ($platform === 'meta') {
            return [
                'value' => 'value', 'currency' => 'currency', 'item_id' => 'content_ids', 'product_id' => 'content_ids',
                'item_name' => 'content_name', 'product_name' => 'content_name', 'product_group' => 'content_category',
                'quantity' => 'num_items', 'search_term' => 'search_string', 'content_type' => 'content_type',
                'form_name' => 'content_name', 'service_name' => 'content_name',
            ][$parameter] ?? null;
        }
        return [
            'value' => 'conversion_value', 'currency' => 'currency_code', 'transaction_id' => 'order_id',
            'order_id' => 'order_id', 'gclid' => 'gclid', 'wbraid' => 'wbraid', 'gbraid' => 'gbraid',
        ][$parameter] ?? null;
    }

    private function identifierOptions(string $platform, array $privacy, array $routeSettings): array {
        if (empty($privacy['hashed_pii_enabled']) || $platform === 'ga4') return [];
        $allowed = is_array($privacy['allowed_hashed_fields'] ?? null) ? $privacy['allowed_hashed_fields'] : [];
        $savedMappings = is_array($routeSettings['user_identifier_mappings'] ?? null) ? $routeSettings['user_identifier_mappings'] : [];
        $saved = [];
        foreach ($savedMappings as $mapping) {
            if (is_array($mapping) && isset($mapping['canonical_name'])) $saved[(string)$mapping['canonical_name']] = true;
        }
        $options = [];
        foreach ($allowed as $identifier) {
            if (!is_string($identifier)) continue;
            $destinationName = $this->destinationIdentifierName($platform, $identifier);
            if ($destinationName === null) continue;
            $options[] = ['canonical_name' => $identifier, 'destination_name' => $destinationName, 'selected' => isset($saved[$identifier]), 'transform' => 'normalise_sha256'];
        }
        return $options;
    }

    private function identifierNotice(string $platform, array $privacy): string {
        if ($platform === 'ga4') return 'Personal identifiers are never offered to GA4 because Google Analytics prohibits PII.';
        if (empty($privacy['hashed_pii_enabled'])) return 'Enable selected SHA-256 identifiers in Privacy control before assigning user identifiers.';
        return 'Only identifiers allowed by the privacy policy are available. Values are normalised and SHA-256 hashed before delivery.';
    }

    private function destinationIdentifierName(string $platform, string $identifier): ?string {
        if ($platform === 'posthog') {
            return ['email' => 'email_sha256', 'phone' => 'phone_sha256', 'full_name' => 'name_sha256', 'first_name' => 'first_name_sha256', 'last_name' => 'last_name_sha256'][$identifier] ?? null;
        }
        if ($platform === 'meta') {
            return ['email' => 'em', 'phone' => 'ph', 'first_name' => 'fn', 'last_name' => 'ln'][$identifier] ?? null;
        }
        if ($platform === 'google_ads') {
            return ['email' => 'hashed_email', 'phone' => 'hashed_phone_number'][$identifier] ?? null;
        }
        return null;
    }

    private function validName(string $platform, string $eventName): bool {
        if ($eventName === '' || preg_match('/[\x00-\x1F\x7F]/u', $eventName)) return false;
        if ($platform === 'ga4') return preg_match('/^[a-z][a-z0-9_]{0,39}$/', $eventName) === 1;
        if ($platform === 'posthog') return preg_match('/^[a-z][a-z0-9_]{0,79}$/', $eventName) === 1;
        if ($platform === 'meta') return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/', $eventName) === 1;
        return mb_strlen($eventName) <= 100;
    }
}
