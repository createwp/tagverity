<?php
namespace GTMAuditor;

// ─── Resource discovery source contract ──────────────────────────────────────
/**
 * Lists the GA4 properties and GTM containers a Google login can see, so the user can
 * pick which ones to link. Every method THROWS on failure; callers classify it with
 * SourceStatus::fromThrowable().
 */
interface DiscoverySource
{
    /**
     * @return array[]  [['account_id' => string, 'account_name' => string,
     *                    'properties' => [['property_id' => string, 'display_name' => string]]]]
     * @throws \Throwable
     */
    public function listGa4AccountSummaries(): array;

    /**
     * Web data streams of one GA4 property.
     *
     * @return array[]  [['stream_id' => string, 'measurement_id' => string, 'stream_url' => ?string]]
     * @throws \Throwable
     */
    public function listGa4Streams(string $propertyId): array;

    /**
     * @return array[]  [['account_id' => string, 'account_name' => string,
     *                    'containers' => [['container_id' => string, 'public_id' => string, 'name' => string]]]]
     * @throws \Throwable
     */
    public function listGtmAccountsAndContainers(): array;
}
