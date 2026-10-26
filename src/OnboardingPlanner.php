<?php
namespace GTMAuditor;

final class OnboardingPlanner {
    public function buildQuestionnaire(array $scan): array {
        $summary = $scan['summary'] ?? [];
        $suggested = $summary['suggested_business_type'] ?? 'lead_generation';
        $services = array_slice($summary['services'] ?? [], 0, 12);
        $products = array_slice($summary['products'] ?? [], 0, 12);
        $forms = array_slice($summary['forms'] ?? [], 0, 20);

        return [
            'version' => 1,
            'intro' => 'We found the likely structure below. Confirm or edit it before TagVerity creates your draft measurement plan.',
            'questions' => [
                [
                    'id' => 'business_type',
                    'type' => 'single_choice',
                    'label' => 'What best describes this website?',
                    'suggested' => $suggested,
                    'options' => [
                        ['value' => 'lead_generation', 'label' => 'Lead generation or services'],
                        ['value' => 'ecommerce', 'label' => 'Ecommerce'],
                        ['value' => 'saas', 'label' => 'SaaS or membership'],
                        ['value' => 'hybrid', 'label' => 'A mix of these'],
                    ],
                ],
                [
                    'id' => 'primary_goals',
                    'type' => 'multi_choice',
                    'label' => 'Which outcomes matter most?',
                    'options' => [
                        ['value' => 'qualified_leads', 'label' => 'Qualified leads'],
                        ['value' => 'sales', 'label' => 'Online sales'],
                        ['value' => 'signups', 'label' => 'Trials or sign-ups'],
                        ['value' => 'engagement', 'label' => 'Content engagement'],
                    ],
                ],
                [
                    'id' => 'destinations',
                    'type' => 'multi_choice',
                    'label' => 'Where should the verified events eventually go?',
                    'options' => [
                        ['value' => 'ga4', 'label' => 'Google Analytics 4'],
                        ['value' => 'posthog', 'label' => 'PostHog'],
                        ['value' => 'google_ads', 'label' => 'Google Ads'],
                        ['value' => 'meta', 'label' => 'Meta'],
                    ],
                ],
                [
                    'id' => 'services',
                    'type' => 'list',
                    'label' => 'Confirm the services or offers we found',
                    'suggested' => $services,
                    'help' => 'One name per line. These names enrich page, click, and form events.',
                ],
                [
                    'id' => 'products',
                    'type' => 'list',
                    'label' => 'Confirm the product groups we found',
                    'suggested' => $products,
                    'help' => 'Leave blank if this is not an ecommerce website.',
                ],
                [
                    'id' => 'forms',
                    'type' => 'form_labels',
                    'label' => 'Give each important form a clear business name',
                    'suggested' => $forms,
                    'help' => 'For example: Contact us, Solar quote, Demo request, or Checkout details.',
                ],
                [
                    'id' => 'ecommerce_capture',
                    'type' => 'single_choice',
                    'label' => 'Should we include a standard ecommerce data model?',
                    'suggested' => in_array($suggested, ['ecommerce', 'hybrid'], true) ? 'yes' : 'not_now',
                    'options' => [
                        ['value' => 'yes', 'label' => 'Yes, include ecommerce'],
                        ['value' => 'not_now', 'label' => 'Not now'],
                        ['value' => 'not_sure', 'label' => 'I am not sure'],
                    ],
                ],
            ],
        ];
    }

    public function normaliseAnswers(array $answers, array $questionnaire): array {
        $allowed = [];
        foreach ($questionnaire['questions'] ?? [] as $question) {
            $allowed[$question['id']] = $question;
        }

        $normalised = [];
        foreach ($allowed as $id => $question) {
            $value = $answers[$id] ?? null;
            if ($question['type'] === 'multi_choice') {
                $valid = array_column($question['options'] ?? [], 'value');
                $normalised[$id] = array_values(array_intersect($valid, is_array($value) ? $value : []));
                continue;
            }

            if ($question['type'] === 'single_choice') {
                $valid = array_column($question['options'] ?? [], 'value');
                $normalised[$id] = in_array($value, $valid, true) ? $value : ($question['suggested'] ?? $valid[0] ?? '');
                continue;
            }

            if ($question['type'] === 'list') {
                $items = is_array($value) ? $value : preg_split('/\R/', (string)$value);
                $normalised[$id] = $this->cleanList($items, 30);
                continue;
            }

            if ($question['type'] === 'form_labels') {
                $labels = [];
                foreach (is_array($value) ? $value : [] as $key => $label) {
                    $key = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$key), 0, 80);
                    $label = trim(strip_tags((string)$label));
                    if ($key !== '' && $label !== '') {
                        $labels[$key] = mb_substr($label, 0, 120);
                    }
                }
                $normalised[$id] = $labels;
            }
        }

        return $normalised;
    }

    public function buildMeasurementPlan(array $scan, array $answers): array {
        $summary = $scan['summary'] ?? [];
        $businessType = $answers['business_type'] ?? $summary['suggested_business_type'] ?? 'lead_generation';
        $events = [
            $this->event('page_view', 'A meaningful page or SPA route is viewed.', ['page_name', 'page_type', 'content_group', 'service_name', 'product_group', 'route_path']),
            $this->event('element_click', 'A named navigation, CTA, download, phone, email, or outbound element is clicked.', ['element_name', 'element_type', 'section_name', 'destination_url', 'page_name', 'service_name']),
            $this->event('element_view', 'A named high-value element becomes visible.', ['element_name', 'element_type', 'section_name', 'visibility_percent', 'page_name']),
        ];

        if (!empty($summary['forms']) || in_array($businessType, ['lead_generation', 'hybrid'], true)) {
            $events = array_merge($events, [
                $this->event('form_view', 'An important form becomes visible.', ['form_name', 'form_type', 'service_name', 'page_name', 'form_step']),
                $this->event('form_start', 'The visitor first interacts with an important form.', ['form_name', 'form_type', 'service_name', 'page_name', 'form_step']),
                $this->event('form_field_interaction', 'A field is focused or changed without collecting its value.', ['form_name', 'field_name', 'field_type', 'form_step', 'page_name']),
                $this->event('form_abandon', 'A started form is left without a verified completion.', ['form_name', 'last_field_name', 'furthest_step', 'fields_interacted_count', 'time_in_form_seconds', 'page_name']),
                $this->event('form_submit', 'The form reports a successful completion.', ['form_name', 'form_type', 'service_name', 'form_steps_total', 'page_name']),
            ]);
        }

        if (($answers['ecommerce_capture'] ?? '') === 'yes' || in_array($businessType, ['ecommerce', 'hybrid'], true)) {
            $events = array_merge($events, [
                $this->event('view_item', 'A product detail is viewed.', ['items', 'currency', 'value', 'page_name']),
                $this->event('add_to_cart', 'A product is added to the cart.', ['items', 'currency', 'value']),
                $this->event('view_cart', 'The cart is viewed.', ['items', 'currency', 'value']),
                $this->event('begin_checkout', 'Checkout begins.', ['items', 'currency', 'value', 'coupon']),
                $this->event('purchase', 'A purchase is verified.', ['transaction_id', 'items', 'currency', 'value', 'tax', 'shipping', 'coupon']),
            ]);
        }

        if (!empty($summary['features']['site_search'])) {
            $events[] = $this->event('view_search_results', 'Search results are shown.', ['search_term', 'results_count', 'page_name']);
        }
        if (!empty($summary['features']['filters'])) {
            $events[] = $this->event('filter_applied', 'A visitor applies a filter.', ['filter_name', 'filter_value', 'results_count', 'page_name']);
            $events[] = $this->event('sort_changed', 'A visitor changes result sorting.', ['sort_option', 'results_count', 'page_name']);
            $events[] = $this->event('view_layout_changed', 'A visitor switches a list or grid view.', ['view_type', 'page_name']);
        }

        return [
            'version' => 1,
            'status' => 'draft_needs_review',
            'business_type' => $businessType,
            'primary_goals' => $answers['primary_goals'] ?? [],
            'destinations' => $answers['destinations'] ?? [],
            'entities' => [
                'services' => $answers['services'] ?? [],
                'products' => $answers['products'] ?? [],
                'form_labels' => $answers['forms'] ?? [],
            ],
            'events' => $events,
            'privacy_rules' => [
                'Do not collect raw form values, passwords, free text, email addresses, phone numbers, or names in analytics events.',
                'Consent must be checked before non-essential destinations receive an event.',
                'Any permitted advertising identifiers must be normalised and SHA-256 hashed server-side before transmission.',
            ],
            'next_steps' => [
                'Review the proposed names and events.',
                'Connect destinations and consent categories.',
                'Preview each trigger against the live site.',
                'Publish only after test events pass validation.',
            ],
        ];
    }

    private function event(string $name, string $when, array $parameters): array {
        return ['name' => $name, 'when' => $when, 'parameters' => $parameters];
    }

    private function cleanList(array $items, int $limit): array {
        $clean = [];
        foreach ($items as $item) {
            $item = trim(strip_tags((string)$item));
            if ($item !== '') {
                $clean[] = mb_substr($item, 0, 120);
            }
        }
        return array_slice(array_values(array_unique($clean)), 0, $limit);
    }
}

