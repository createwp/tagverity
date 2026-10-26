<?php
namespace GTMAuditor;

final class ElementTemplateBuilder {
    private const INTENTS = ['click', 'visibility', 'form', 'ecommerce_value', 'site_search'];
    private const GOALS = ['product_analytics', 'marketing_conversion', 'user_experience', 'data_quality'];
    private const DATA_KEYS = ['item_name', 'item_id', 'price', 'currency', 'item_category', 'search_term', 'results_count', 'filter_name', 'filter_value'];

    public function build(array $mapping, array $destinations): array {
        $intent = in_array($mapping['tracking_intent'] ?? '', self::INTENTS, true) ? $mapping['tracking_intent'] : 'click';
        $goal = in_array($mapping['measurement_goal'] ?? '', self::GOALS, true) ? $mapping['measurement_goal'] : 'product_analytics';
        $dataKey = in_array($mapping['data_key'] ?? '', self::DATA_KEYS, true) ? $mapping['data_key'] : null;
        $elementName = $mapping['element_name'];
        $selector = $mapping['css_selector'];
        $pageUrl = $mapping['page_url'];
        $triggerScope = in_array($mapping['trigger_scope'] ?? '', ['page', 'global'], true) ? $mapping['trigger_scope'] : 'page';
        $events = $this->eventsForIntent($intent, $elementName, $dataKey);
        $trigger = $this->triggerForIntent($intent, $selector, $pageUrl, $elementName, $dataKey, $triggerScope);
        $tags = $this->tagsForDestinations($events, $destinations, $goal, $dataKey);

        return [
            'version' => 1,
            'status' => 'draft_needs_preview',
            'recommendation' => $this->recommendation($intent),
            'event_template' => [
                'events' => $events,
                'shared_parameters' => [
                    'element_name' => $elementName,
                    'trigger_scope' => $triggerScope,
                    'source_page_url' => $pageUrl,
                    'page_url' => $triggerScope === 'global' ? '{{runtime_page_url}}' : $pageUrl,
                ],
            ],
            'trigger_template' => $trigger,
            'tag_templates' => $tags,
            'consent_requirement' => $this->consentRequirement($destinations),
        ];
    }

    public function allowedIntents(): array {
        return self::INTENTS;
    }

    public function allowedGoals(): array {
        return self::GOALS;
    }

    public function allowedDataKeys(): array {
        return self::DATA_KEYS;
    }

    private function eventsForIntent(string $intent, string $elementName, ?string $dataKey): array {
        if ($intent === 'form') {
            return [
                ['name' => 'form_view', 'parameters' => ['form_name', 'form_type', 'service_name', 'page_name', 'form_step']],
                ['name' => 'form_start', 'parameters' => ['form_name', 'form_type', 'service_name', 'page_name', 'form_step']],
                ['name' => 'form_field_interaction', 'parameters' => ['form_name', 'field_name', 'field_type', 'form_step', 'page_name']],
                ['name' => 'form_abandon', 'parameters' => ['form_name', 'last_field_name', 'furthest_step', 'fields_interacted_count', 'time_in_form_seconds', 'page_name']],
                ['name' => 'form_submit', 'parameters' => ['form_name', 'form_type', 'service_name', 'form_steps_total', 'page_name']],
            ];
        }
        if ($intent === 'visibility') {
            return [['name' => 'element_view', 'parameters' => ['element_name', 'element_type', 'section_name', 'visibility_percent', 'page_name']]];
        }
        if ($intent === 'ecommerce_value') {
            return [[
                'name' => 'ecommerce_parameter_mapping',
                'parameters' => [$dataKey ?: 'item_name'],
                'note' => 'This mapping supplies a value to standard ecommerce events; it does not fire an event by itself.',
            ]];
        }
        if ($intent === 'site_search') {
            $key = $dataKey ?: 'search_term';
            return [['name' => 'view_search_results', 'parameters' => [$key, 'page_name']]];
        }
        return [['name' => 'element_click', 'parameters' => ['element_name', 'element_type', 'section_name', 'destination_url', 'page_name', 'service_name']]];
    }

    private function triggerForIntent(string $intent, string $selector, string $pageUrl, string $elementName, ?string $dataKey, string $triggerScope): array {
        $base = [
            'name' => $this->title($elementName) . ' - ' . $this->triggerLabel($intent),
            'scope' => $triggerScope,
            'page_url' => $triggerScope === 'page' ? $pageUrl : null,
            'source_page_url' => $pageUrl,
            'selector' => $selector,
            'requires_browser_preview' => true,
            'verification_requirement' => $triggerScope === 'global' ? 'sample_multiple_pages' : 'selected_page',
        ];
        if ($intent === 'form') {
            return $base + ['type' => 'form_lifecycle', 'completion_signal' => 'must_be_verified', 'track_field_values' => false, 'supports_multistep' => true];
        }
        if ($intent === 'visibility') {
            return $base + ['type' => 'element_visibility', 'minimum_visible_percent' => 50, 'minimum_visible_ms' => 1000, 'once_per_page' => true];
        }
        if ($intent === 'ecommerce_value' || $intent === 'site_search') {
            return $base + ['type' => 'dom_value_extraction', 'data_key' => $dataKey, 'extraction' => 'text_content', 'normalisation' => $this->normalisationFor($dataKey)];
        }
        return $base + ['type' => 'element_click', 'click_scope' => 'selected_element_and_children'];
    }

    private function tagsForDestinations(array $events, array $destinations, string $goal, ?string $dataKey): array {
        $allowedDestinations = ['ga4', 'posthog', 'google_ads', 'meta'];
        $destinations = array_values(array_intersect($allowedDestinations, $destinations));
        $templates = [];
        foreach ($destinations as $destination) {
            if ($destination === 'ga4') {
                $templates[] = ['platform' => 'ga4', 'template' => $dataKey ? 'GA4 ecommerce parameter mapping' : 'GA4 Event', 'events' => array_column($events, 'name'), 'configuration_required' => ['measurement_id']];
            } elseif ($destination === 'posthog') {
                $templates[] = ['platform' => 'posthog', 'template' => 'PostHog Capture', 'events' => array_column($events, 'name'), 'configuration_required' => ['project_api_key', 'host']];
            } elseif ($destination === 'google_ads' && $goal === 'marketing_conversion') {
                $templates[] = ['platform' => 'google_ads', 'template' => 'Google Ads Conversion', 'events' => [$this->conversionEvent($events)], 'configuration_required' => ['customer_id', 'conversion_action_id', 'google_connection']];
            } elseif ($destination === 'meta' && $goal === 'marketing_conversion') {
                $templates[] = ['platform' => 'meta', 'template' => 'Meta Conversions API / Pixel', 'events' => [$this->conversionEvent($events)], 'configuration_required' => ['pixel_id', 'access_token']];
            }
        }
        if (!$templates) {
            $templates[] = ['platform' => 'unassigned', 'template' => 'Destination not selected', 'events' => array_column($events, 'name'), 'configuration_required' => ['destination']];
        }
        return $templates;
    }

    private function recommendation(string $intent): string {
        $copy = [
            'click' => 'Use a delegated click trigger scoped to this selector so child icons and labels are included.',
            'visibility' => 'Use element visibility after at least 50% is visible for one second, once per page.',
            'form' => 'Use form lifecycle observation for view, start, field interaction, abandonment, and verified completion. Never collect field values.',
            'ecommerce_value' => 'Extract and normalise this value into the ecommerce item model, then validate it before sending an ecommerce event.',
            'site_search' => 'Extract this search value or result count and attach it to the standard search-results event.',
        ];
        return $copy[$intent];
    }

    private function triggerLabel(string $intent): string {
        return [
            'click' => 'Click',
            'visibility' => 'Visibility',
            'form' => 'Form lifecycle',
            'ecommerce_value' => 'Ecommerce value',
            'site_search' => 'Search value',
        ][$intent];
    }

    private function normalisationFor(?string $dataKey): string {
        if ($dataKey === 'price') return 'decimal_number_without_currency_symbol';
        if ($dataKey === 'results_count') return 'integer';
        if ($dataKey === 'currency') return 'uppercase_iso_4217';
        return 'trimmed_text';
    }

    private function conversionEvent(array $events): string {
        $names = array_column($events, 'name');
        foreach (['form_submit', 'purchase', 'element_click'] as $preferred) {
            if (in_array($preferred, $names, true)) return $preferred;
        }
        return $names[0] ?? 'conversion';
    }

    private function consentRequirement(array $destinations): string {
        return array_intersect(['google_ads', 'meta'], $destinations) ? 'advertising' : 'analytics';
    }

    private function title(string $value): string {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }
}
