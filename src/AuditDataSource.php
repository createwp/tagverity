<?php
namespace GTMAuditor;

// ─── Audit data source contract ──────────────────────────────────────────────
/**
 * Read-only data source the audit data collector pulls from.
 * Both methods THROW on failure; callers classify the failure with SourceStatus::fromThrowable().
 */
interface AuditDataSource
{
    /**
     * @return array{tags: array, triggers: array, variables: array, container: array{account_id: string, container_id: string}}
     * @throws \Throwable
     */
    public function liveContainerVersion(string $gtmAccountId, string $containerId): array;

    /**
     * @return array[]  Rows of ['dimension_name' => value, 'metric_name' => value]
     * @throws \Throwable
     */
    public function ga4Report(string $propertyId, array $dimensions, array $metrics, int $daysBack, int $limit): array;
}
