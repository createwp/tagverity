<?php
namespace GTMAuditor;

use Google\Client as GoogleClient;
use Google\Service\TagManager;
use Google\Service\Analytics;
use Google\Service\AnalyticsAdmin;
use Google\Service\AnalyticsData;
use Google\Service\AnalyticsData\RunReportRequest;
use Google\Service\AnalyticsData\Dimension;
use Google\Service\AnalyticsData\Metric;
use Google\Service\AnalyticsData\DateRange;
use Google\Service\AnalyticsData\FilterExpression;
use Google\Service\CloudResourceManager;

// ─── Google API Client wrapper ────────────────────────────────────────────────
/**
 * Handles all outbound Google API calls for a given google_connections row.
 *
 * Responsibilities:
 *  - Exchange the stored (encrypted) refresh token for a fresh access token
 *  - Instantiate the correct Google service client
 *  - Expose clean, typed methods for each API call the audit engine needs
 *
 * AuditDataSource and DiscoverySource methods (liveContainerVersion, ga4Report,
 * listGa4AccountSummaries, listGa4Streams, listGtmAccountsAndContainers) THROW, so
 * callers can classify the failure with SourceStatus::fromThrowable(). The legacy
 * getContainerVersion, runGa4Report and getTotalSessions swallow failures and return
 * null, [] or 0 for existing callers.
 */
class GoogleApiClient implements AuditDataSource, DiscoverySource
{
    private GoogleClient $client;

    /**
     * @param array $connection  Row from google_connections table
     *                           Must include `refresh_token` (AES-256-encrypted)
     * @throws \RuntimeException  'authentication_failed' (401) when the refresh token is rejected
     */
    public function __construct(array $connection)
    {
        // Bootstrap config if not already loaded (for when called from cron)
        if (!defined('GOOGLE_CLIENT_ID')) {
            require_once dirname(__DIR__) . '/includes/config.php';
        }
        require_once dirname(__DIR__) . '/includes/crypto.php';

        $this->client = new GoogleClient();
        $this->client->setClientId(GOOGLE_CLIENT_ID);
        $this->client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $this->client->setAccessType('offline');

        // Decrypt the stored refresh token and exchange for a fresh access token.
        // Why: access tokens expire after 1 hour; we always refresh before API calls.
        $refreshToken = decrypt_token($connection['refresh_token']);
        $token = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($token['error'])) {
            throw new \RuntimeException('authentication_failed', 401);
        }
    }

    // ── GTM Management API ────────────────────────────────────────────────────

    /**
     * List the GTM accounts visible to this login, each with its containers. Throws on failure.
     *
     * @return array[]  [['account_id', 'account_name', 'containers' => [['container_id', 'public_id', 'name']]]]
     * @throws \Throwable
     */
    public function listGtmAccountsAndContainers(): array
    {
        $gtm      = new TagManager($this->client);
        $accounts = $gtm->accounts->listAccounts()->getAccount() ?? [];
        $results  = [];

        foreach ($accounts as $account) {
            $containers = $gtm->accounts_containers
                ->listAccountsContainers('accounts/' . $account->getAccountId())
                ->getContainer() ?? [];

            $list = [];
            foreach ($containers as $container) {
                $list[] = [
                    'container_id' => (string) $container->getContainerId(),
                    'public_id'    => (string) $container->getPublicId(), // GTM-XXXXX
                    'name'         => (string) $container->getName(),
                ];
            }
            $results[] = [
                'account_id'   => (string) $account->getAccountId(),
                'account_name' => (string) $account->getName(),
                'containers'   => $list,
            ];
        }

        return $results;
    }

    /**
     * Fetch the full live container version (tags, triggers, variables).
     * Swallows failures and returns null (kept for existing callers).
     *
     * @param string $account_id
     * @param string $container_id
     * @return array|null
     */
    public function getContainerVersion(string $account_id, string $container_id): ?array
    {
        try {
            return $this->liveContainerVersion($account_id, $container_id);
        } catch (\Throwable $e) {
            error_log('[GTMAuditor][GTM API] getContainerVersion: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch the full live container version. Throws on failure so the collector can classify it.
     *
     * @return array{tags: array, triggers: array, variables: array, container: array{account_id: string, container_id: string}}
     * @throws \Throwable
     */
    public function liveContainerVersion(string $gtmAccountId, string $containerId): array
    {
        $gtm     = new TagManager($this->client);
        $version = $gtm->accounts_containers_versions->live(
            "accounts/{$gtmAccountId}/containers/{$containerId}"
        );

        // Serialize objects to plain arrays for easy inspection
        $serialize = fn($items) => array_map(fn($item) => json_decode(json_encode($item), true), $items ?? []);

        return [
            'tags'      => $serialize($version->getTag()),
            'triggers'  => $serialize($version->getTrigger()),
            'variables' => $serialize($version->getVariable()),
            'container' => [
                'account_id'   => $gtmAccountId,
                'container_id' => $containerId,
            ],
        ];
    }

    // ── GA4 Admin API — for discovery ────────────────────────────────────────

    /**
     * List the GA4 accounts and their properties visible to this login. Throws on failure.
     *
     * @return array[]  [['account_id', 'account_name', 'properties' => [['property_id', 'display_name']]]]
     * @throws \Throwable
     */
    public function listGa4AccountSummaries(): array
    {
        $admin     = new AnalyticsAdmin($this->client);
        $accounts  = [];
        $pageToken = null;

        do {
            $params = ['pageSize' => 200];
            if ($pageToken) $params['pageToken'] = $pageToken;
            $response = $admin->accountSummaries->listAccountSummaries($params);

            foreach ($response->getAccountSummaries() ?? [] as $summary) {
                $properties = [];
                foreach ($summary->getPropertySummaries() ?? [] as $property) {
                    $properties[] = [
                        'property_id'  => str_replace('properties/', '', (string) $property->getProperty()),
                        'display_name' => (string) $property->getDisplayName(),
                    ];
                }
                $accounts[] = [
                    'account_id'   => str_replace('accounts/', '', (string) $summary->getAccount()),
                    'account_name' => (string) $summary->getDisplayName(),
                    'properties'   => $properties,
                ];
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $accounts;
    }

    /**
     * Web data streams of one GA4 property. App streams are skipped. Throws on failure.
     *
     * @return array[]  [['stream_id', 'measurement_id', 'stream_url']]
     * @throws \Throwable
     */
    public function listGa4Streams(string $propertyId): array
    {
        $admin     = new AnalyticsAdmin($this->client);
        $parent    = "properties/{$propertyId}";
        $streams   = [];
        $pageToken = null;

        do {
            $params = ['pageSize' => 200];
            if ($pageToken) $params['pageToken'] = $pageToken;
            $response = $admin->properties_dataStreams->listPropertiesDataStreams($parent, $params);

            foreach ($response->getDataStreams() ?? [] as $stream) {
                $webData = $stream->getWebStreamData();
                if ($webData === null) continue;
                $streams[] = [
                    'stream_id'      => str_replace($parent . '/dataStreams/', '', (string) $stream->getName()),
                    'measurement_id' => (string) $webData->getMeasurementId(),
                    'stream_url'     => $webData->getDefaultUri(),
                ];
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $streams;
    }

    // ── GA4 Data API — for audit checks ──────────────────────────────────────

    /**
     * Run a GA4 reporting query and return structured rows.
     * Swallows failures and returns [] (kept for existing callers).
     *
     * @param string  $property_id   GA4 property ID (numeric only)
     * @param array   $dimensions    e.g. ['landingPage', 'sessionDefaultChannelGrouping']
     * @param array   $metrics       e.g. ['sessions', 'eventCount']
     * @param int     $days_back     Number of days back to query
     * @param int     $limit         Max rows to return
     * @return array[]               Array of ['dimension_name' => value, 'metric_name' => value]
     */
    public function runGa4Report(
        string $property_id,
        array  $dimensions,
        array  $metrics,
        int    $days_back = 30,
        int    $limit     = 50
    ): array {
        try {
            return $this->ga4Report($property_id, $dimensions, $metrics, $days_back, $limit);
        } catch (\Throwable $e) {
            error_log("[GTMAuditor][GA4 Data] runGa4Report(property={$property_id}): " . $e->getMessage());
            return [];
        }
    }

    /**
     * Run a GA4 reporting query. Throws on failure so the collector can classify it.
     *
     * @return array[]  Array of ['dimension_name' => value, 'metric_name' => value]
     * @throws \Throwable
     */
    public function ga4Report(string $propertyId, array $dimensions, array $metrics, int $daysBack, int $limit): array
    {
        $analyticsData = new AnalyticsData($this->client);

        $dimObjects    = array_map(fn($d) => new Dimension(['name' => $d]), $dimensions);
        $metricObjects = array_map(fn($m) => new Metric(['name' => $m]), $metrics);

        $request = new RunReportRequest([
            'dimensions' => $dimObjects,
            'metrics'    => $metricObjects,
            'dateRanges' => [new DateRange([
                'startDate' => "{$daysBack}daysAgo",
                'endDate'   => 'today',
            ])],
            'limit' => $limit,
        ]);

        $response = $analyticsData->properties->runReport(
            "properties/{$propertyId}",
            $request
        );

        $rows      = [];
        $dimNames  = array_map(fn($d) => $d->getName(), $response->getDimensionHeaders() ?? []);
        $metNames  = array_map(fn($m) => $m->getName(), $response->getMetricHeaders() ?? []);

        foreach ($response->getRows() ?? [] as $row) {
            $item = [];
            foreach ($row->getDimensionValues() ?? [] as $i => $dv) {
                $item[$dimNames[$i] ?? "dim_{$i}"] = $dv->getValue();
            }
            foreach ($row->getMetricValues() ?? [] as $i => $mv) {
                $item[$metNames[$i] ?? "met_{$i}"] = $mv->getValue();
            }
            $rows[] = $item;
        }

        return $rows;
    }

    /**
     * Get total session count for a property over $days_back days.
     * Used to calculate % ratios for data quality checks.
     *
     * @param string $property_id
     * @param int    $days_back
     * @return int
     */
    public function getTotalSessions(string $property_id, int $days_back = 30): int
    {
        $rows = $this->runGa4Report($property_id, [], ['sessions'], $days_back, 1);
        return (int)($rows[0]['sessions'] ?? 0);
    }
}
