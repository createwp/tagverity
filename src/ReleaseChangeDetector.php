<?php
namespace GTMAuditor;

final class ReleaseChangeDetector {
    public function detect(array $baseEvents, array $mappings, array $baselineSnapshot = []): array {
        $baseline = $this->indexSnapshot($baselineSnapshot);
        $newCount = 0;
        $modifiedCount = 0;

        $changedBaseEvents = $this->changedItems($baseEvents, 'base', $baseline, $newCount, $modifiedCount);
        $changedMappings = $this->changedItems($mappings, 'mapping', $baseline, $newCount, $modifiedCount);

        return [
            'base_events' => $changedBaseEvents,
            'mappings' => $changedMappings,
            'summary' => [
                'new' => $newCount,
                'modified' => $modifiedCount,
                'total' => $newCount + $modifiedCount,
            ],
        ];
    }

    private function changedItems(array $items, string $kind, array $baseline, int &$newCount, int &$modifiedCount): array {
        $changed = [];
        foreach ($items as $item) {
            $events = [];
            foreach ($item['event_template']['events'] ?? [] as $index => $event) {
                if (($event['enabled'] ?? true) === false) continue;
                $key = $this->eventKey($item, $event, $kind, $index);
                $fingerprint = $this->fingerprint($item, $event, false);
                $changeType = !isset($baseline[$key]) ? 'new' : ($baseline[$key] !== $fingerprint ? 'modified' : '');
                if ($changeType === '') continue;
                $event['change_type'] = $changeType;
                $events[] = $event;
                if ($changeType === 'new') $newCount++;
                else $modifiedCount++;
            }
            if (!$events) continue;
            $item['event_template']['events'] = $events;
            $item['change_type'] = count(array_unique(array_column($events, 'change_type'))) === 1
                ? $events[0]['change_type']
                : 'mixed';
            $changed[] = $item;
        }
        return $changed;
    }

    private function indexSnapshot(array $snapshot): array {
        $index = [];
        foreach ([
            ['items' => $snapshot['base_events'] ?? [], 'kind' => 'base'],
            ['items' => $snapshot['mappings'] ?? [], 'kind' => 'mapping'],
        ] as $group) {
            foreach ($group['items'] as $item) {
                foreach ($item['event_template']['events'] ?? [] as $eventIndex => $event) {
                    $key = $this->eventKey($item, $event, $group['kind'], $eventIndex);
                    $index[$key] = $this->fingerprint($item, $event, true);
                }
            }
        }
        return $index;
    }

    private function eventKey(array $item, array $event, string $kind, int $index): string {
        if (!empty($event['event_key'])) return (string)$event['event_key'];
        if ($kind === 'base') return (string)($item['key'] ?? 'base') . ':event:' . $index;
        return 'mapping:' . (int)($item['id'] ?? 0) . ':event:' . $index;
    }

    private function fingerprint(array $item, array $event, bool $baseline): string {
        $parameters = $baseline
            ? ($event['parameters'] ?? [])
            : (array_key_exists('selected_parameters', $event) ? $event['selected_parameters'] : ($event['parameters'] ?? []));
        $parameters = array_values(array_unique(array_filter($parameters, 'is_string')));
        sort($parameters);

        $tags = [];
        foreach ($item['tag_templates'] ?? [] as $tag) {
            unset($tag['events']);
            $tags[] = $tag;
        }

        $value = [
            'event_name' => $baseline ? (string)($event['name'] ?? '') : (string)($event['configured_name'] ?? $event['name'] ?? ''),
            'parameters' => $parameters,
            'element_name' => (string)($item['element_name'] ?? ''),
            'page_url' => (string)($item['page_url'] ?? ''),
            'trigger_scope' => (string)($item['trigger_scope'] ?? 'global'),
            'trigger_template' => $item['trigger_template'] ?? [],
            'tag_templates' => $tags,
        ];

        return hash('sha256', json_encode($this->normalise($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalise(mixed $value): mixed {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->normalise($item);
        return $value;
    }
}
