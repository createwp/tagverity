<?php
namespace GTMAuditor;

require_once __DIR__ . '/ElementTemplateBuilder.php';
require_once __DIR__ . '/EventDestinationRouter.php';
require_once __DIR__ . '/ManualParameters.php';

final class TrackingWorkspace {
    private ReleaseEventBuilder $eventBuilder;
    private EventDestinationRouter $destinationRouter;

    public function __construct(?ReleaseEventBuilder $eventBuilder = null) {
        $this->eventBuilder = $eventBuilder ?: new ReleaseEventBuilder();
        $this->destinationRouter = new EventDestinationRouter();
    }

    public function load(\PDO $pdo, int $clientId): array {
        $stmt = $pdo->prepare(
            'SELECT status, latest_scan_id, measurement_plan
             FROM onboarding_profiles
             WHERE client_id = ?
             LIMIT 1'
        );
        $stmt->execute([$clientId]);
        $profile = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $measurementPlan = json_decode($profile['measurement_plan'] ?? '', true) ?: [];

        $baseEvents = $this->eventBuilder->baseEvents($measurementPlan);
        $mappings = $this->loadMappings($pdo, $clientId);
        $settings = $this->loadEventSettings($pdo, $clientId);
        $eventDestinations = $this->loadEventDestinations($pdo, $clientId);
        $privacy = $this->loadPrivacy($pdo, $clientId);
        $destinations = is_array($measurementPlan['destinations'] ?? null) ? $measurementPlan['destinations'] : [];
        $baseEvents = $this->configureItems($baseEvents, 'base', $settings, $destinations, $eventDestinations, $privacy);
        $mappings = $this->configureItems($mappings, 'mapping', $settings, $destinations, $eventDestinations, $privacy);

        return [
            'profile_status' => $profile['status'] ?? 'not_started',
            'latest_scan_id' => (int)($profile['latest_scan_id'] ?? 0),
            'base_events' => $baseEvents,
            'mappings' => $mappings,
            'events' => $this->flattenEvents($baseEvents, $mappings),
            'privacy' => $privacy,
        ];
    }

    public function findEvent(array $workspace, string $eventKey): ?array {
        foreach ($workspace['events'] ?? [] as $event) {
            if (($event['event_key'] ?? '') === $eventKey) return $event;
        }
        return null;
    }

    public function parametersForCompatibleTrigger(string $intent): array {
        if (!in_array($intent, ['click', 'visibility'], true)) return [];
        $templates = (new ElementTemplateBuilder())->build([
            'element_name' => 'Example element',
            'tracking_intent' => $intent,
            'measurement_goal' => 'product_analytics',
            'css_selector' => '#example',
            'page_url' => 'https://example.com/',
            'trigger_scope' => 'page',
            'data_key' => null,
        ], []);
        return array_values(array_filter($templates['event_template']['events'][0]['parameters'] ?? [], 'is_string'));
    }

    private function loadMappings(\PDO $pdo, int $clientId): array {
        $stmt = $pdo->prepare(
            "SELECT id, site_scan_id, page_url, trigger_scope, element_name, element_role, tracking_intent,
                    measurement_goal, css_selector, selector_strategy, data_key, preview_text,
                    element_attributes, event_template, trigger_template, tag_templates, updated_at
             FROM measurement_elements
             WHERE client_id = ? AND status = 'active'
             ORDER BY updated_at DESC, id DESC"
        );
        $stmt->execute([$clientId]);
        $mappings = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tagTemplates = $this->normaliseTagTemplates(json_decode($row['tag_templates'], true) ?: []);
            $mappings[] = [
                'id' => (int)$row['id'],
                'site_scan_id' => (int)$row['site_scan_id'],
                'page_url' => $row['page_url'],
                'trigger_scope' => $row['trigger_scope'],
                'element_name' => $row['element_name'],
                'element_role' => $row['element_role'],
                'tracking_intent' => $row['tracking_intent'],
                'measurement_goal' => $row['measurement_goal'],
                'css_selector' => $row['css_selector'],
                'selector_strategy' => $row['selector_strategy'],
                'data_key' => $row['data_key'],
                'preview_text' => $row['preview_text'],
                'element_attributes' => json_decode($row['element_attributes'] ?: '{}', true) ?: [],
                'event_template' => json_decode($row['event_template'], true) ?: [],
                'trigger_template' => json_decode($row['trigger_template'], true) ?: [],
                'tag_templates' => $tagTemplates,
                'updated_at' => $row['updated_at'],
            ];
        }
        return $mappings;
    }

    private function normaliseTagTemplates(array $templates): array {
        foreach ($templates as &$template) {
            if (($template['platform'] ?? '') === 'generic' && ($template['template'] ?? '') === 'Data layer event') {
                $template['platform'] = 'unassigned';
                $template['template'] = 'Destination not selected';
                $template['configuration_required'] = ['destination'];
            }
        }
        unset($template);
        return $templates;
    }

    private function loadEventSettings(\PDO $pdo, int $clientId): array {
        $stmt = $pdo->prepare(
            'SELECT event_key, source_event_name, approved_event_name, event_group, enabled, selected_parameters, manual_parameters, trigger_intent, updated_at
             FROM tracking_event_settings
             WHERE client_id = ?'
        );
        $stmt->execute([$clientId]);
        $settings = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $row['selected_parameters'] = json_decode($row['selected_parameters'], true) ?: [];
            $row['manual_parameters'] = ManualParameters::decode($row['manual_parameters']);
            $row['enabled'] = (bool)$row['enabled'];
            $settings[$row['event_key']] = $row;
        }
        return $settings;
    }

    private function loadEventDestinations(\PDO $pdo, int $clientId): array {
        $stmt = $pdo->prepare(
            'SELECT event_key, platform, destination_event_name, mapping_type, status, settings, updated_at
             FROM tracking_event_destinations
             WHERE client_id = ?'
        );
        $stmt->execute([$clientId]);
        $destinations = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $row['settings'] = json_decode($row['settings'], true) ?: [];
            $destinations[$row['event_key']][] = $row;
        }
        return $destinations;
    }

    private function configureItems(array $items, string $kind, array $settings, array $destinations, array $eventDestinations, array $privacy): array {
        foreach ($items as &$item) {
            if ($kind === 'mapping') $item = $this->applyTriggerOverride($item, $settings, $destinations);
            $configuredEvents = [];
            $configuredTags = [];
            $templateEvents = $item['event_template']['events'] ?? [];
            foreach ($templateEvents as $eventIndex => $event) {
                $sourceName = (string)($event['name'] ?? '');
                $eventKey = $kind === 'base'
                    ? (string)($item['key'] ?? '')
                    : 'mapping:' . (int)($item['id'] ?? 0) . ':event:' . $eventIndex;
                $legacyKey = 'mapping:' . (int)($item['id'] ?? 0) . ':' . $sourceName;
                $setting = $settings[$eventKey] ?? ($eventIndex === 0 ? ($item['_primary_setting'] ?? null) : null) ?? ($settings[$legacyKey] ?? null);
                if ($setting && ($setting['source_event_name'] ?? '') !== $sourceName) $setting = null;
                $availableParameters = array_values(array_unique(array_filter($event['parameters'] ?? [], 'is_string')));
                $defaultName = $this->defaultEventName($item, $kind, $sourceName, count($templateEvents));
                $selectedParameters = $setting
                    ? array_values(array_intersect($availableParameters, $setting['selected_parameters']))
                    : $availableParameters;
                $manualParameters = $setting['manual_parameters'] ?? [];
                $event['event_key'] = $eventKey;
                $event['source_name'] = $sourceName;
                $event['system_event_name'] = $sourceName;
                $event['recommended_name'] = $defaultName;
                $event['configured_name'] = $setting['approved_event_name'] ?? $defaultName;
                $eventGroup = $setting['event_group'] ?? $this->destinationRouter->defaultGroup($sourceName);
                $routes = $this->destinationRouter->routeOptions(
                    $eventDestinations[$eventKey] ?? [],
                    $item['tag_templates'] ?? [],
                    $event['configured_name'],
                    $eventGroup,
                    $availableParameters,
                    $selectedParameters,
                    $privacy,
                    $manualParameters
                );
                $eventTags = $this->destinationRouter->tagTemplates($routes, $sourceName, $eventGroup);
                $event['event_group'] = $eventGroup;
                $event['event_group_options'] = $this->destinationRouter->groups();
                $event['destinations'] = $routes;
                $event['tag_templates'] = $eventTags;
                $event['available_parameters'] = $availableParameters;
                $event['selected_parameters'] = $selectedParameters;
                $event['manual_parameters'] = $manualParameters;
                $event['enabled'] = $setting ? (bool)$setting['enabled'] : (bool)($item['default_enabled'] ?? true);
                $event['configured'] = $setting !== null;
                $event['configuration_updated_at'] = $setting['updated_at'] ?? null;
                $configuredEvents[] = $event;
                $configuredTags = array_merge($configuredTags, $eventTags);
            }
            $item['event_template']['events'] = $configuredEvents;
            $item['tag_templates'] = $configuredTags;
        }
        unset($item);
        return $items;
    }

    private function flattenEvents(array $baseEvents, array $mappings): array {
        $events = [];
        foreach ([['items' => $baseEvents, 'kind' => 'base'], ['items' => $mappings, 'kind' => 'mapping']] as $group) {
            foreach ($group['items'] as $item) {
                foreach ($item['event_template']['events'] ?? [] as $event) {
                    $events[] = [
                        'event_key' => $event['event_key'],
                        'kind' => $group['kind'],
                        'source_type' => $group['kind'] === 'base' ? ($item['source_type'] ?? 'scan_recommendation') : 'visual_mapping',
                        'source_label' => $group['kind'] === 'base' ? 'Recommended from scan' : 'Mapped by you',
                        'mapping_id' => $group['kind'] === 'mapping' ? (int)$item['id'] : null,
                        'element_name' => $item['element_name'] ?? 'Site page views',
                        'recommendation_when' => $item['recommendation_when'] ?? null,
                        'page_url' => $item['page_url'] ?? null,
                        'trigger_scope' => $item['trigger_scope'] ?? 'global',
                        'trigger_intent' => $item['tracking_intent'] ?? null,
                        'available_trigger_intents' => $group['kind'] === 'mapping' && in_array($item['tracking_intent'] ?? '', ['click', 'visibility'], true) && count($item['event_template']['events'] ?? []) === 1
                            ? ['click', 'visibility']
                            : [],
                        'source_name' => $event['source_name'],
                        'system_event_name' => $event['system_event_name'],
                        'recommended_name' => $event['recommended_name'],
                        'configured_name' => $event['configured_name'],
                        'available_parameters' => $event['available_parameters'],
                        'selected_parameters' => $event['selected_parameters'],
                        'manual_parameters' => $event['manual_parameters'],
                        'enabled' => $event['enabled'],
                        'configured' => $event['configured'],
                        'status' => $event['configured'] ? 'configured_not_live' : 'proposed_not_active',
                        'trigger_template' => $item['trigger_template'] ?? [],
                        'coverage_label' => $item['trigger_template']['coverage_label'] ?? null,
                        'event_group' => $event['event_group'],
                        'event_group_options' => $event['event_group_options'],
                        'destinations' => $event['destinations'],
                        'tag_templates' => $event['tag_templates'],
                    ];
                }
            }
        }
        return $events;
    }

    private function applyTriggerOverride(array $item, array $settings, array $destinations): array {
        $events = $item['event_template']['events'] ?? [];
        if (count($events) !== 1 || !in_array($item['tracking_intent'] ?? '', ['click', 'visibility'], true)) return $item;

        $mappingId = (int)($item['id'] ?? 0);
        $sourceName = (string)($events[0]['name'] ?? '');
        $stableKey = 'mapping:' . $mappingId . ':event:0';
        $legacyKey = 'mapping:' . $mappingId . ':' . $sourceName;
        $setting = $settings[$stableKey] ?? ($settings[$legacyKey] ?? null);
        $item['_primary_setting'] = $setting;
        $intent = (string)($setting['trigger_intent'] ?? $item['tracking_intent']);
        if (!in_array($intent, ['click', 'visibility'], true) || $intent === $item['tracking_intent']) return $item;

        $templates = (new ElementTemplateBuilder())->build([
            'element_name' => $item['element_name'],
            'tracking_intent' => $intent,
            'measurement_goal' => $item['measurement_goal'],
            'css_selector' => $item['css_selector'],
            'page_url' => $item['page_url'],
            'trigger_scope' => $item['trigger_scope'],
            'data_key' => $item['data_key'],
        ], $destinations);
        $item['tracking_intent'] = $intent;
        $item['event_template'] = $templates['event_template'];
        $item['trigger_template'] = $templates['trigger_template'];
        $item['tag_templates'] = $templates['tag_templates'];
        return $item;
    }

    private function defaultEventName(array $item, string $kind, string $sourceName, int $eventCount): string {
        $usesUserName = $kind === 'mapping'
            && $eventCount === 1
            && in_array($item['tracking_intent'] ?? '', ['click', 'visibility'], true);
        if (!$usesUserName) return $sourceName;

        $name = strtolower((string)($item['element_name'] ?? ''));
        $name = preg_replace('/[^a-z0-9]+/', '_', $name) ?: '';
        $name = trim($name, '_');
        if ($name === '') return $sourceName;
        if (!preg_match('/^[a-z]/', $name)) $name = 'event_' . $name;
        return substr($name, 0, 40);
    }

    private function loadPrivacy(\PDO $pdo, int $clientId): array {
        $stmt = $pdo->prepare(
            'SELECT hashed_pii_enabled, allowed_hashed_fields, updated_at
             FROM tracking_privacy_settings
             WHERE client_id = ?
             LIMIT 1'
        );
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'raw_pii_capture' => false,
            'hashed_pii_enabled' => $row ? (bool)$row['hashed_pii_enabled'] : false,
            'allowed_hashed_fields' => $row ? (json_decode($row['allowed_hashed_fields'], true) ?: []) : [],
            'consent_required' => true,
            'configured' => (bool)$row,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
