<?php
namespace GTMAuditor;

use PDO;

final class PublishReadiness {
    public function loadConnectionState(PDO $pdo, int $clientId): array {
        $stmt = $pdo->prepare(
            "SELECT gc.id, gc.scopes
             FROM google_connections gc
             JOIN clients c ON c.account_id = gc.account_id
             WHERE c.id = ?"
        );
        $stmt->execute([$clientId]);
        $connections = $stmt->fetchAll();
        $hasWriteScope = false;
        foreach ($connections as $connection) {
            $scopes = json_decode($connection['scopes'] ?: '[]', true) ?: [];
            if (in_array('https://www.googleapis.com/auth/tagmanager.edit.containers', $scopes, true)
                && in_array('https://www.googleapis.com/auth/tagmanager.edit.containerversions', $scopes, true)
                && in_array('https://www.googleapis.com/auth/tagmanager.publish', $scopes, true)) {
                $hasWriteScope = true;
            }
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM gtm_containers WHERE client_id = ?");
        $stmt->execute([$clientId]);
        $gtmContainers = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM ga4_streams gs
             JOIN ga4_properties gp ON gp.id = gs.ga4_property_id
             WHERE gp.client_id = ?"
        );
        $stmt->execute([$clientId]);
        $ga4Streams = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT platform, status FROM destination_connections WHERE client_id = ?");
        $stmt->execute([$clientId]);
        $destinationConnections = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $connection) {
            $destinationConnections[(string)$connection['platform']] = (string)$connection['status'];
        }
        $isConfigured = static fn(string $platform): bool => in_array($destinationConnections[$platform] ?? '', ['configured', 'verified'], true);

        return [
            'google_connections' => count($connections),
            'gtm_containers' => $gtmContainers,
            'gtm_write_scope' => $hasWriteScope,
            'ga4_streams' => $ga4Streams,
            'ga4_configured' => $isConfigured('ga4'),
            'posthog_configured' => $isConfigured('posthog'),
            'google_ads_configured' => $isConfigured('google_ads'),
            'meta_configured' => $isConfigured('meta'),
            'consent_policy_configured' => false,
            'deployment_adapter_ready' => false,
        ];
    }

    public function evaluate(array $mappings, array $connections): array {
        $platforms = [];
        foreach ($mappings as $mapping) {
            foreach ($mapping['tag_templates'] ?? [] as $tag) {
                if (!empty($tag['platform'])) $platforms[] = $tag['platform'];
            }
        }
        $platforms = array_values(array_unique($platforms));
        $checks = [];
        $eventCount = 0;
        foreach ($mappings as $mapping) $eventCount += count($mapping['event_template']['events'] ?? []);
        $this->check($checks, 'drafts', 'Draft events selected', $eventCount > 0,
            $eventCount > 0 ? $eventCount . ' event(s) selected.' : 'Select at least one draft event.', 'publish-tags.php');
        $hasUnassignedDestination = (bool)array_intersect(['unassigned', 'generic'], $platforms);
        if ($hasUnassignedDestination) {
            $this->check($checks, 'destination_assignment', 'Destination selected', false,
                'Choose where each selected event should be delivered. A click trigger is not a data-layer destination.', 'onboarding.php');
        }
        $this->check($checks, 'browser_verification', 'Browser verification passed', false,
            'Every selector and trigger still requires a live browser test.', 'element-mapper.php');
        $globalMappings = array_values(array_filter($mappings, fn(array $mapping): bool => ($mapping['trigger_scope'] ?? 'page') === 'global'));
        if ($globalMappings) {
            $this->check($checks, 'global_selector_verification', 'Global selector tested across the site', false,
                count($globalMappings) . ' global mapping(s) must be checked on several sampled pages for missing or unintended matches.', 'element-mapper.php');
        }

        $needsGoogle = in_array('google_ads', $platforms, true);
        if ($needsGoogle) {
            $this->check($checks, 'google_connection', 'Google account connected', $connections['google_connections'] > 0,
                $connections['google_connections'] > 0 ? 'Google connection found.' : 'Connect the Google account that owns the Google Ads customer.', 'connections.php');
        }
        if (in_array('ga4', $platforms, true)) {
            $this->check($checks, 'ga4_connection', 'GA4 destination configured', !empty($connections['ga4_configured']),
                !empty($connections['ga4_configured']) ? 'GA4 destination settings are saved.' : 'Add the Measurement ID and Measurement Protocol API secret.', 'destination-connections.php');
        }
        if (in_array('posthog', $platforms, true)) {
            $this->check($checks, 'posthog_connection', 'PostHog project connected', $connections['posthog_configured'],
                'Add the PostHog project key and host before publishing.', 'destination-connections.php');
        }
        if (in_array('google_ads', $platforms, true)) {
            $this->check($checks, 'google_ads_connection', 'Google Ads conversion configured', $connections['google_ads_configured'],
                'Add the Google Ads customer and conversion action IDs before publishing.', 'destination-connections.php');
        }
        if (in_array('meta', $platforms, true)) {
            $this->check($checks, 'meta_connection', 'Meta destination configured', $connections['meta_configured'],
                'Add the Pixel ID and Conversions API token before publishing.', 'destination-connections.php');
        }

        $needsConsent = (bool)array_intersect(['ga4', 'posthog', 'google_ads', 'meta'], $platforms);
        if ($needsConsent) {
            $this->check($checks, 'consent_policy', 'Consent policy assigned', $connections['consent_policy_configured'],
                'Assign analytics and advertising consent requirements to the release.', 'connections.php');
        }
        $this->check($checks, 'deployment_adapter', 'Deployment adapter ready', $connections['deployment_adapter_ready'],
            'The external deployment adapter is not implemented yet. Approval is available; publishing remains locked.', 'publish-tags.php');

        $blockers = array_values(array_filter($checks, fn(array $check): bool => $check['status'] === 'blocked'));
        return [
            'ready_to_publish' => count($blockers) === 0,
            'platforms' => $platforms,
            'checks' => $checks,
            'blockers' => $blockers,
        ];
    }

    private function check(array &$checks, string $id, string $label, bool $passes, string $detail, string $actionUrl): void {
        $checks[] = [
            'id' => $id,
            'label' => $label,
            'status' => $passes ? 'passed' : 'blocked',
            'detail' => $detail,
            'action_url' => $actionUrl,
        ];
    }
}
