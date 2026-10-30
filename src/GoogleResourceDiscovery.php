<?php
namespace GTMAuditor;

require_once __DIR__ . '/DiscoverySource.php';
require_once __DIR__ . '/SourceStatus.php';

// ─── Google resource discovery ───────────────────────────────────────────────
/**
 * Lists the GA4 properties and GTM containers visible to one Google login and marks the
 * ones already linked to a client. Nothing is written here.
 */
final class GoogleResourceDiscovery
{
    /**
     * One side failing records its SourceStatus in `errors` and the other side is still returned.
     *
     * @return array{ga4_accounts: array, gtm_accounts: array, errors: array{ga4: ?string, gtm: ?string}}
     */
    public function discover(\PDO $pdo, int $clientId, DiscoverySource $source): array
    {
        $errors = ['ga4' => null, 'gtm' => null];

        $linkedProperties = $this->linkedIds($pdo, 'ga4_properties', 'property_id', $clientId);
        $linkedContainers = $this->linkedIds($pdo, 'gtm_containers', 'container_id', $clientId);

        $ga4Accounts = [];
        try {
            $ga4Accounts = $source->listGa4AccountSummaries();
        } catch (\Throwable $e) {
            $errors['ga4'] = SourceStatus::fromThrowable($e);
        }
        foreach ($ga4Accounts as $i => $account) {
            foreach ($account['properties'] as $j => $property) {
                $ga4Accounts[$i]['properties'][$j]['linked'] = isset($linkedProperties[$property['property_id']]);
            }
        }

        $gtmAccounts = [];
        try {
            $gtmAccounts = $source->listGtmAccountsAndContainers();
        } catch (\Throwable $e) {
            $errors['gtm'] = SourceStatus::fromThrowable($e);
        }
        foreach ($gtmAccounts as $i => $account) {
            foreach ($account['containers'] as $j => $container) {
                $gtmAccounts[$i]['containers'][$j]['linked'] = isset($linkedContainers[$container['container_id']]);
            }
        }

        return [
            'ga4_accounts' => $ga4Accounts,
            'gtm_accounts' => $gtmAccounts,
            'errors'       => $errors,
        ];
    }

    /**
     * @return array<string, true>  Set of ids already linked to the client.
     */
    private function linkedIds(\PDO $pdo, string $table, string $column, int $clientId): array
    {
        $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE client_id = ?");
        $stmt->execute([$clientId]);
        $set = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $set[(string) $id] = true;
        }
        return $set;
    }
}
