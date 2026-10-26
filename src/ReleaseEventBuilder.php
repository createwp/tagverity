<?php
namespace GTMAuditor;

final class ReleaseEventBuilder {
    public function baseEvents(array $measurementPlan): array {
        $destinations = is_array($measurementPlan['destinations'] ?? null) ? $measurementPlan['destinations'] : [];
        $events = is_array($measurementPlan['events'] ?? null) ? $measurementPlan['events'] : [];
        $baseEvents = [];
        $seen = [];
        foreach ($events as $event) {
            $eventName = (string)($event['name'] ?? '');
            if (!$this->validEventName($eventName) || isset($seen[$eventName])) continue;
            $seen[$eventName] = true;
            $trigger = $this->recommendedTrigger($eventName, $measurementPlan);
            $baseEvents[] = [
                'key' => 'base:' . $eventName,
                'element_name' => $this->eventTitle($eventName),
                'source_type' => 'scan_recommendation',
                'recommendation_when' => trim((string)($event['when'] ?? '')),
                'default_enabled' => $eventName === 'page_view',
                'trigger_scope' => 'global',
                'event_template' => ['events' => [[
                    'name' => $eventName,
                    'parameters' => array_values(array_filter($event['parameters'] ?? [], 'is_string')),
                ]]],
                'trigger_template' => $trigger,
                'tag_templates' => $this->tagTemplates($eventName, $destinations),
            ];
        }
        return $baseEvents;
    }

    public function selectMappings(array $mappings, array $selections): array {
        $selectionById = [];
        foreach ($selections as $selection) {
            $mappingId = (int)($selection['mapping_id'] ?? 0);
            if ($mappingId > 0) $selectionById[$mappingId] = $selection['events'] ?? [];
        }

        $selected = [];
        foreach ($mappings as $mapping) {
            $mappingId = (int)($mapping['id'] ?? 0);
            if (!array_key_exists($mappingId, $selectionById)) continue;
            $filtered = $this->filterEvents($mapping, $selectionById[$mappingId]);
            if ($filtered !== null) $selected[] = $filtered;
        }
        return $selected;
    }

    public function selectBaseEvents(array $baseEvents, array $selections): array {
        $selectionByKey = [];
        foreach ($selections as $selection) {
            $key = (string)($selection['key'] ?? '');
            if ($key !== '') $selectionByKey[$key] = $selection['events'] ?? [];
        }

        $selected = [];
        foreach ($baseEvents as $baseEvent) {
            $key = (string)($baseEvent['key'] ?? '');
            if (!array_key_exists($key, $selectionByKey)) continue;
            $filtered = $this->filterEvents($baseEvent, $selectionByKey[$key]);
            if ($filtered !== null) $selected[] = $filtered;
        }
        return $selected;
    }

    public function validEventName(string $name): bool {
        return preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) === 1;
    }

    private function filterEvents(array $item, array $selections): ?array {
        $configurations = [];
        foreach ($selections as $selection) {
            $sourceName = (string)($selection['source_name'] ?? '');
            $eventName = (string)($selection['event_name'] ?? '');
            if ($sourceName === '' || !$this->validEventName($eventName)) {
                throw new \InvalidArgumentException('invalid_event_name');
            }
            $configurations[$sourceName] = [
                'name' => $eventName,
                'selected_parameters' => is_array($selection['selected_parameters'] ?? null) ? $selection['selected_parameters'] : null,
            ];
        }
        if (!$configurations) return null;

        $events = [];
        foreach ($item['event_template']['events'] ?? [] as $event) {
            $sourceName = (string)($event['name'] ?? '');
            if (!isset($configurations[$sourceName])) continue;
            $availableParameters = array_values(array_unique(array_filter($event['parameters'] ?? [], 'is_string')));
            $requestedParameters = $configurations[$sourceName]['selected_parameters'];
            if ($requestedParameters !== null) {
                $requestedParameters = array_values(array_unique(array_filter($requestedParameters, 'is_string')));
                if (count(array_intersect($requestedParameters, $availableParameters)) !== count($requestedParameters)) {
                    throw new \InvalidArgumentException('invalid_event_parameter');
                }
                $event['parameters'] = $requestedParameters;
            }
            $event['source_name'] = $sourceName;
            $event['name'] = $configurations[$sourceName]['name'];
            $events[] = $event;
        }
        if (count($events) !== count($configurations)) {
            throw new \InvalidArgumentException('event_not_found');
        }

        $item['event_template']['events'] = $events;
        $tags = [];
        foreach ($item['tag_templates'] ?? [] as $tag) {
            $tagEvents = [];
            foreach ($tag['events'] ?? [] as $tagEvent) {
                if (isset($configurations[$tagEvent])) $tagEvents[] = $configurations[$tagEvent]['name'];
            }
            if (!$tagEvents) continue;
            $tag['events'] = array_values(array_unique($tagEvents));
            $tags[] = $tag;
        }
        $item['tag_templates'] = $tags;
        return $item;
    }

    private function tagTemplates(string $eventName, array $destinations): array {
        $tags = [];
        foreach (array_values(array_unique($destinations)) as $destination) {
            if ($destination === 'ga4') {
                $tags[] = ['platform' => 'ga4', 'template' => 'GA4 Event', 'events' => [$eventName], 'configuration_required' => ['measurement_id']];
            } elseif ($destination === 'posthog') {
                $tags[] = ['platform' => 'posthog', 'template' => 'PostHog Capture', 'events' => [$eventName], 'configuration_required' => ['project_api_key', 'host']];
            } elseif ($destination === 'google_ads' && in_array($eventName, ['form_submit', 'purchase', 'sign_up', 'generate_lead'], true)) {
                $tags[] = ['platform' => 'google_ads', 'template' => 'Google Ads Conversion', 'events' => [$eventName], 'configuration_required' => ['customer_id', 'conversion_action_id', 'google_connection']];
            } elseif ($destination === 'meta' && in_array($eventName, ['form_submit', 'purchase', 'sign_up', 'generate_lead'], true)) {
                $tags[] = ['platform' => 'meta', 'template' => 'Meta Conversions API / Pixel', 'events' => [$eventName], 'configuration_required' => ['pixel_id', 'access_token']];
            }
        }
        if (!$tags) $tags[] = ['platform' => 'unassigned', 'template' => 'Destination not selected', 'events' => [$eventName], 'configuration_required' => ['destination']];
        return $tags;
    }

    private function recommendedTrigger(string $eventName, array $measurementPlan): array {
        $forms = $measurementPlan['entities']['form_labels'] ?? [];
        $formCount = is_array($forms) ? count($forms) : 0;
        $base = [
            'name' => $this->eventTitle($eventName) . ' - Recommended tracking',
            'scope' => 'global',
            'page_url' => null,
            'requires_browser_preview' => true,
            'verification_requirement' => 'sample_multiple_pages',
        ];

        if ($eventName === 'page_view') {
            return $base + ['type' => 'page_view', 'coverage_label' => 'Every page and SPA route'];
        }
        if ($eventName === 'element_click') {
            return $base + [
                'type' => 'element_click',
                'collection_mode' => 'recommended_interactive_elements',
                'coverage_label' => 'Detected links, buttons and controls',
            ];
        }
        if ($eventName === 'element_view') {
            return $base + [
                'type' => 'element_visibility',
                'collection_mode' => 'mapped_high_value_elements',
                'coverage_label' => 'High-value elements selected for visibility tracking',
                'requires_element_mapping' => true,
            ];
        }
        if (str_starts_with($eventName, 'form_')) {
            return $base + [
                'type' => 'form_lifecycle',
                'lifecycle_event' => $eventName,
                'collection_mode' => 'detected_forms',
                'coverage_label' => $formCount > 0 ? $formCount . ' named form' . ($formCount === 1 ? '' : 's') : 'All detected forms',
            ];
        }
        if (in_array($eventName, ['view_item', 'add_to_cart', 'view_cart', 'begin_checkout', 'purchase'], true)) {
            return $base + [
                'type' => 'data_layer_event',
                'data_layer_event' => $eventName,
                'coverage_label' => 'Standard ecommerce data layer',
            ];
        }
        if ($eventName === 'view_search_results') {
            return $base + ['type' => 'site_search', 'coverage_label' => 'Detected site search experience'];
        }
        if (in_array($eventName, ['filter_applied', 'sort_changed', 'view_layout_changed'], true)) {
            return $base + ['type' => 'result_interaction', 'interaction_event' => $eventName, 'coverage_label' => 'Detected result controls'];
        }
        return $base + ['type' => 'custom_event', 'coverage_label' => 'Matching website interactions'];
    }

    private function eventTitle(string $eventName): string {
        $labels = [
            'page_view' => 'Page views',
            'element_click' => 'Element clicks',
            'element_view' => 'Element visibility',
            'form_view' => 'Form views',
            'form_start' => 'Form starts',
            'form_field_interaction' => 'Form field interactions',
            'form_abandon' => 'Form abandonment',
            'form_submit' => 'Form submissions',
            'view_item' => 'Product views',
            'add_to_cart' => 'Add to cart',
            'view_cart' => 'Cart views',
            'begin_checkout' => 'Checkout starts',
            'purchase' => 'Purchases',
            'view_search_results' => 'Site search results',
            'filter_applied' => 'Filters applied',
            'sort_changed' => 'Sorting changes',
            'view_layout_changed' => 'List and grid view changes',
        ];
        return $labels[$eventName] ?? ucwords(str_replace('_', ' ', $eventName));
    }
}
