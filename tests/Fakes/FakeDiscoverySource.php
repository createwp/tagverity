<?php
namespace GTMAuditor\Tests\Fakes;

require_once __DIR__ . '/../../src/DiscoverySource.php';

use GTMAuditor\DiscoverySource;

/**
 * In-memory DiscoverySource for tests. Streams are keyed by GA4 property id.
 * An error passed to the constructor is thrown by the matching method.
 */
final class FakeDiscoverySource implements DiscoverySource
{
    private array $ga4Accounts;
    private array $streamsByProperty;
    private array $gtmAccounts;
    private ?\Throwable $ga4Error;
    private ?\Throwable $gtmError;

    public function __construct(
        array $ga4Accounts = [],
        array $streamsByProperty = [],
        array $gtmAccounts = [],
        ?\Throwable $ga4Error = null,
        ?\Throwable $gtmError = null
    ) {
        $this->ga4Accounts       = $ga4Accounts;
        $this->streamsByProperty = $streamsByProperty;
        $this->gtmAccounts       = $gtmAccounts;
        $this->ga4Error          = $ga4Error;
        $this->gtmError          = $gtmError;
    }

    public function listGa4AccountSummaries(): array
    {
        if ($this->ga4Error !== null) {
            throw $this->ga4Error;
        }

        return $this->ga4Accounts;
    }

    public function listGa4Streams(string $propertyId): array
    {
        if ($this->ga4Error !== null) {
            throw $this->ga4Error;
        }

        return $this->streamsByProperty[$propertyId] ?? [];
    }

    public function listGtmAccountsAndContainers(): array
    {
        if ($this->gtmError !== null) {
            throw $this->gtmError;
        }

        return $this->gtmAccounts;
    }
}
