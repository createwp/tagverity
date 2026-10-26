<?php
namespace GTMAuditor;

final class DestinationConnectionManager {
    private const DEFINITIONS = [
        'ga4' => [
            'label' => 'Google Analytics 4',
            'summary' => 'Send website, form and product analytics events to Google Analytics.',
            'docs_url' => 'https://developers.google.com/analytics/devguides/collection/protocol/ga4/sending-events#prerequisites',
            'docs_label' => 'How to find your GA4 details',
            'account_url' => 'https://analytics.google.com/analytics/web/#/a',
            'account_label' => 'Open Google Analytics',
            'auth_provider' => 'google',
            'setup_steps' => ['Connect the Google account that owns the GA4 property.', 'Choose the website data stream you want to use.', 'Create a Measurement Protocol secret and paste it below.'],
            'fields' => [
                ['key' => 'measurement_id', 'label' => 'Website stream ID', 'placeholder' => 'Starts with G-', 'required' => true, 'secret' => false, 'help' => 'Shown in GA4 under Admin > Data streams.'],
                ['key' => 'api_secret', 'label' => 'Server connection secret', 'placeholder' => 'Paste the secret from GA4', 'required' => true, 'secret' => true, 'help' => 'Stored encrypted and never placed on your website.'],
            ],
        ],
        'posthog' => [
            'label' => 'PostHog',
            'summary' => 'Send product analytics events and privacy-approved identity signals to PostHog.',
            'docs_url' => 'https://posthog.com/docs/api',
            'docs_label' => 'Where to find your PostHog project token',
            'account_url' => 'https://app.posthog.com/settings/project',
            'account_label' => 'Open PostHog project settings',
            'auth_provider' => 'guided_manual',
            'setup_steps' => ['Open the PostHog project you want to connect.', 'Copy the project token from Project settings.', 'Confirm whether the project is hosted in the US, EU, or on your own domain.'],
            'fields' => [
                ['key' => 'host', 'label' => 'Where is your PostHog project?', 'placeholder' => 'Choose a region', 'required' => true, 'secret' => false, 'help' => 'Contact support to connect a self-hosted PostHog project.', 'options' => ['https://us.i.posthog.com' => 'United States', 'https://eu.i.posthog.com' => 'European Union']],
                ['key' => 'project_api_key', 'label' => 'Project token', 'placeholder' => 'Usually starts with phc_', 'required' => true, 'secret' => true, 'help' => 'This identifies the PostHog project that will receive events.'],
            ],
        ],
        'meta' => [
            'label' => 'Meta',
            'summary' => 'Connect browser Pixel and server-side conversion tracking for Facebook and Instagram.',
            'docs_url' => 'https://developers.facebook.com/docs/marketing-api/conversions-api/get-started',
            'docs_label' => 'Meta Conversions API setup guide',
            'account_url' => 'https://business.facebook.com/events_manager2/list/pixel/',
            'account_label' => 'Open Meta Events Manager',
            'auth_provider' => 'guided_manual',
            'setup_steps' => ['Open the correct dataset or Pixel in Events Manager.', 'Copy its Pixel ID.', 'Generate an access token under Settings > Conversions API.'],
            'fields' => [
                ['key' => 'pixel_id', 'label' => 'Dataset or Pixel ID', 'placeholder' => 'For example 123456789012345', 'required' => true, 'secret' => false, 'help' => 'Shown at the top of the dataset in Meta Events Manager.'],
                ['key' => 'access_token', 'label' => 'Server access token', 'placeholder' => 'Paste the token from Events Manager', 'required' => true, 'secret' => true, 'help' => 'Stored encrypted and used only for server delivery.'],
                ['key' => 'test_event_code', 'label' => 'Test event code', 'placeholder' => 'Optional', 'required' => false, 'secret' => false],
            ],
        ],
        'google_ads' => [
            'label' => 'Google Ads',
            'summary' => 'Send leads, purchases and other valuable actions to Google Ads.',
            'docs_url' => 'https://support.google.com/google-ads/answer/1704344',
            'docs_label' => 'How to find your Google Ads customer ID',
            'account_url' => 'https://ads.google.com/aw/conversions',
            'account_label' => 'Open Google Ads conversions',
            'auth_provider' => 'google',
            'setup_steps' => ['Connect a Google account with access to the Ads account.', 'Choose the Google Ads customer.', 'Choose the conversion action that should receive events.'],
            'fields' => [
                ['key' => 'customer_id', 'label' => 'Google Ads account ID', 'placeholder' => '123-456-7890', 'required' => true, 'secret' => false, 'help' => 'Shown in the top-right corner of Google Ads.'],
                ['key' => 'conversion_action_id', 'label' => 'Conversion action ID', 'placeholder' => 'Choose or enter the conversion action', 'required' => true, 'secret' => false, 'help' => 'The conversion action that should receive TagVerity events.'],
            ],
        ],
    ];

    public function definitions(): array {
        return self::DEFINITIONS;
    }

    public function load(\PDO $pdo, int $clientId): array {
        $stmt = $pdo->prepare('SELECT platform, display_name, configuration, encrypted_credentials, status, last_verified_at, last_error_code, updated_at FROM destination_connections WHERE client_id = ?');
        $stmt->execute([$clientId]);
        $saved = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) $saved[(string)$row['platform']] = $row;

        $connections = [];
        foreach (self::DEFINITIONS as $platform => $definition) {
            $row = $saved[$platform] ?? [];
            $configuration = json_decode((string)($row['configuration'] ?? '{}'), true);
            if (!is_array($configuration)) $configuration = [];
            $fields = [];
            foreach ($definition['fields'] as $field) {
                $key = $field['key'];
                $fields[] = $field + [
                    'value' => $field['secret'] ? '' : (string)($configuration[$key] ?? ''),
                    'has_saved_value' => $field['secret'] && !empty($row['encrypted_credentials']),
                ];
            }
            $connections[] = [
                'platform' => $platform,
                'label' => $definition['label'],
                'summary' => $definition['summary'],
                'docs_url' => $definition['docs_url'],
                'docs_label' => $definition['docs_label'],
                'account_url' => $definition['account_url'],
                'account_label' => $definition['account_label'],
                'auth_provider' => $definition['auth_provider'],
                'setup_steps' => $definition['setup_steps'],
                'status' => (string)($row['status'] ?? 'not_configured'),
                'fields' => $fields,
                'configuration' => $configuration,
                'last_verified_at' => $row['last_verified_at'] ?? null,
                'last_error_code' => $row['last_error_code'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }
        return $connections;
    }

    public function validate(string $platform, array $input, bool $hasStoredSecret): array {
        $definition = self::DEFINITIONS[$platform] ?? null;
        if (!$definition) throw new \InvalidArgumentException('unsupported_platform');
        $configuration = [];
        $secrets = [];
        foreach ($definition['fields'] as $field) {
            $key = $field['key'];
            $value = trim((string)($input[$key] ?? ''));
            if ($field['required'] && $value === '' && !($field['secret'] && $hasStoredSecret)) {
                throw new \InvalidArgumentException('missing_' . $key);
            }
            if ($field['secret']) {
                if ($value !== '') $secrets[$key] = $value;
            } else {
                if ($value !== '') $configuration[$key] = $this->normaliseField($platform, $key, $value);
            }
        }
        return ['configuration' => $configuration, 'secrets' => $secrets];
    }

    public function definition(string $platform): ?array {
        return self::DEFINITIONS[$platform] ?? null;
    }

    private function normaliseField(string $platform, string $key, string $value): string {
        if ($platform === 'ga4' && $key === 'measurement_id') {
            $value = strtoupper($value);
            if (preg_match('/^G-[A-Z0-9]{5,20}$/', $value) !== 1) throw new \InvalidArgumentException('invalid_measurement_id');
        }
        if ($platform === 'posthog' && $key === 'host') {
            $value = rtrim($value, '/');
            $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
            if (!filter_var($value, FILTER_VALIDATE_URL) || $scheme !== 'https') throw new \InvalidArgumentException('invalid_posthog_host');
        }
        if ($platform === 'meta' && $key === 'pixel_id' && preg_match('/^[0-9]{5,30}$/', $value) !== 1) throw new \InvalidArgumentException('invalid_pixel_id');
        if ($platform === 'google_ads' && $key === 'customer_id') {
            $value = str_replace('-', '', $value);
            if (preg_match('/^[0-9]{10}$/', $value) !== 1) throw new \InvalidArgumentException('invalid_customer_id');
        }
        if ($platform === 'google_ads' && $key === 'conversion_action_id' && preg_match('/^[0-9]{1,30}$/', $value) !== 1) throw new \InvalidArgumentException('invalid_conversion_action_id');
        if (mb_strlen($value) > 500) throw new \InvalidArgumentException('field_too_long');
        return $value;
    }
}
