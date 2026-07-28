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
 * Every method wraps its API call in try/catch — failures return null or []
 * rather than crashing the audit job.
 */
class GoogleApiClient
{
    private GoogleClient $client;

    /**
     * @param array $connection  Row from google_connections table
     *                           Must include `refresh_token` (AES-256-encrypted)
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
        $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
    }

    // ── GTM Management API ────────────────────────────────────────────────────

    /**
     * List all GTM accounts + containers accessible via this OAuth token.
     *
     * @return array[]  Array of container summaries from the GTM API
     */
    public function listGtmContainers(): array
    {
        try {
            $gtm      = new TagManager($this->client);
            $response = $gtm->accounts->listAccounts();
            $accounts = $response->getAccount() ?? [];
            $results  = [];

            foreach ($accounts as $account) {
                $accountPath  = 'accounts/' . $account->getAccountId();
                $containers   = $gtm->accounts_containers->listAccountsContainers($accountPath)->getContainer() ?? [];

                foreach ($containers as $container) {
                    $results[] = [
                        'account_id'   => $account->getAccountId(),
                        'container_id' => $container->getContainerId(),
                        'public_id'    => $container->getPublicId(), // GTM-XXXXX
                        'name'         => $container->getName(),
                        'domain_name'  => $container->getDomainName() ?? [],
                    ];
                }
            }
            return $results;
        } catch (\Throwable $e) {
            error_log('[GTMAuditor][GTM API] listGtmContainers: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch the full live container version (tags, triggers, variables).
     *
     * @param string $account_id
     * @param string $container_id
     * @return array|null
     */
    public function getContainerVersion(string $account_id, string $container_id): ?array
    {
        try {
            $gtm     = new TagManager($this->client);
            $version = $gtm->accounts_containers_versions->live(
                "accounts/{$account_id}/containers/{$container_id}"
            );

            // Serialize objects to plain arrays for easy inspection
            $serialize = fn($items) => array_map(fn($item) => json_decode(json_encode($item), true), $items ?? []);

            return [
                'tags'      => $serialize($version->getTag()),
                'triggers'  => $serialize($version->getTrigger()),
                'variables' => $serialize($version->getVariable()),
                'container' => [
                    'account_id'   => $account_id,
                    'container_id' => $container_id,
                ],
            ];
        } catch (\Throwable $e) {
            error_log('[GTMAuditor][GTM API] getContainerVersion: ' . $e->getMessage());
            return null;
        }
    }

    // ── GA4 Admin API — for auto-discovery ───────────────────────────────────

    /**
     * List all GA4 properties + streams accessible via this OAuth token.
     * Used during auto-discovery after OAuth connect.
     *
     * @return array[]  Each item: {property_id, display_name, streams: [{stream_id, measurement_id, stream_url}]}
     */
    public function listGa4PropertiesAndStreams(): array
    {
        try {
            $admin      = new AnalyticsAdmin($this->client);
            $properties = [];
            $pageToken  = null;

            // Paginate through all properties
            do {
                $params   = ['pageSize' => 200];
                if ($pageToken) $params['pageToken'] = $pageToken;
                $response  = $admin->properties->listProperties($params);
                $propList  = $response->getProperties() ?? [];

                foreach ($propList as $prop) {
                    $propertyId = str_replace('properties/', '', $prop->getName());
                    $streams    = [];

                    try {
                        $streamsResp = $admin->properties_dataStreams->listPropertiesDataStreams("properties/{$propertyId}");
                        foreach ($streamsResp->getDataStreams() ?? [] as $stream) {
                            $webData = $stream->getWebStreamData();
                            $streams[] = [
                                'stream_id'      => str_replace("properties/{$propertyId}/dataStreams/", '', $stream->getName()),
                                'measurement_id' => $webData ? $webData->getMeasurementId() : null,
                                'stream_url'     => $webData ? $webData->getDefaultUri() : null,
                            ];
                        }
                    } catch (\Throwable $e) {
                        error_log('[GTMAuditor][GA4 Admin] streams for ' . $propertyId . ': ' . $e->getMessage());
                    }

                    $properties[] = [
                        'property_id'  => $propertyId,
                        'display_name' => $prop->getDisplayName(),
                        'streams'      => $streams,
                    ];
                }

                $pageToken = $response->getNextPageToken();
            } while ($pageToken);

            return $properties;
        } catch (\Throwable $e) {
            error_log('[GTMAuditor][GA4 Admin] listProperties: ' . $e->getMessage());
            return [];
        }
    }

    // ── GA4 Data API — for audit checks ──────────────────────────────────────

    /**
     * Run a GA4 reporting query and return structured rows.
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
            $analyticsData = new AnalyticsData($this->client);

            $dimObjects    = array_map(fn($d) => new Dimension(['name' => $d]), $dimensions);
            $metricObjects = array_map(fn($m) => new Metric(['name' => $m]), $metrics);

            $request = new RunReportRequest([
                'dimensions' => $dimObjects,
                'metrics'    => $metricObjects,
                'dateRanges' => [new DateRange([
                    'startDate' => "{$days_back}daysAgo",
                    'endDate'   => 'today',
                ])],
                'limit' => $limit,
            ]);

            $response = $analyticsData->properties->runReport(
                "properties/{$property_id}",
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
        } catch (\Throwable $e) {
            error_log("[GTMAuditor][GA4 Data] runGa4Report(property={$property_id}): " . $e->getMessage());
            return [];
        }
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
