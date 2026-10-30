<?php
namespace GTMAuditor\Tests\Fakes;

require_once __DIR__ . '/../../src/AuditDataSource.php';

use GTMAuditor\AuditDataSource;

/**
 * In-memory AuditDataSource for tests. GA4 rows are keyed by the first dimension
 * name; a query with no dimensions looks up the '_total' key.
 */
final class FakeAuditDataSource implements AuditDataSource
{
    private array $containerVersion;
    private array $reportsByFirstDimension;
    private ?\Throwable $gtmError;
    private ?\Throwable $ga4Error;

    public function __construct(
        array $containerVersion = [],
        array $reportsByFirstDimension = [],
        ?\Throwable $gtmError = null,
        ?\Throwable $ga4Error = null
    ) {
        $this->containerVersion        = $containerVersion;
        $this->reportsByFirstDimension = $reportsByFirstDimension;
        $this->gtmError                = $gtmError;
        $this->ga4Error                = $ga4Error;
    }

    public function liveContainerVersion(string $gtmAccountId, string $containerId): array
    {
        if ($this->gtmError !== null) {
            throw $this->gtmError;
        }

        return $this->containerVersion;
    }

    public function ga4Report(string $propertyId, array $dimensions, array $metrics, int $daysBack, int $limit): array
    {
        if ($this->ga4Error !== null) {
            throw $this->ga4Error;
        }

        $key = $dimensions === [] ? '_total' : $dimensions[0];

        return $this->reportsByFirstDimension[$key] ?? [];
    }
}
