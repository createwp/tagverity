<?php
namespace GTMAuditor\Tests\Fakes;

// ─── Shared DB fixtures for DB-backed tests ──────────────────────────────────
/**
 * Creates and removes rows for DB-backed tests. Every test that uses these must
 * call cleanupAccount() for its account in a finally block.
 */
final class TestFixtures
{
    public static function createAccount(\PDO $pdo): int
    {
        $stmt = $pdo->prepare("INSERT INTO accounts (name, type, plan_id) VALUES (?, 'standalone', 1)");
        $stmt->execute(['TV test ' . self::suffix()]);
        return (int) $pdo->lastInsertId();
    }

    public static function createClient(\PDO $pdo, int $accountId): int
    {
        $stmt = $pdo->prepare("INSERT INTO clients (account_id, name) VALUES (?, ?)");
        $stmt->execute([$accountId, 'TV test client ' . self::suffix()]);
        return (int) $pdo->lastInsertId();
    }

    public static function createConnection(\PDO $pdo, int $accountId, string $email = ''): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO google_connections (account_id, google_email, google_sub, refresh_token)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$accountId, $email, 'test-sub-' . self::suffix(), 'test-not-a-real-token']);
        return (int) $pdo->lastInsertId();
    }

    public static function createGtmContainer(
        \PDO $pdo,
        int $clientId,
        int $connectionId,
        string $containerId,
        int $auditTarget = 0
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO gtm_containers
                (client_id, google_connection_id, gtm_account_id, container_id, gtm_public_id, name, audit_target)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $clientId,
            $connectionId,
            (string) random_int(100000, 999999),
            $containerId,
            'GTM-' . strtoupper(self::suffix()),
            'TV test container',
            $auditTarget,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function createGa4Property(
        \PDO $pdo,
        int $clientId,
        int $connectionId,
        string $propertyId,
        int $auditTarget = 0
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO ga4_properties
                (client_id, google_connection_id, property_id, display_name, audit_target)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$clientId, $connectionId, $propertyId, 'TV test property', $auditTarget]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Deletes everything under the account, children first. A table that does not
     * exist in this database is skipped so cleanup never aborts half way.
     */
    public static function cleanupAccount(\PDO $pdo, int $accountId): void
    {
        $clients = "SELECT id FROM clients WHERE account_id = ?";
        $ga4 = "SELECT p.id FROM ga4_properties p JOIN clients c ON c.id = p.client_id WHERE c.account_id = ?";
        $gtm = "SELECT g.id FROM gtm_containers g JOIN clients c ON c.id = g.client_id WHERE c.account_id = ?";
        $domains = "SELECT d.id FROM domains d JOIN clients c ON c.id = d.client_id WHERE c.account_id = ?";
        $jobs = "SELECT j.id FROM audit_jobs j JOIN clients c ON c.id = j.client_id WHERE c.account_id = ?";

        $steps = [
            "DELETE FROM ga4_streams WHERE ga4_property_id IN ({$ga4})" => [$accountId],
            "DELETE FROM domain_ga4_properties WHERE property_id IN ({$ga4}) OR domain_id IN ({$domains})"
                => [$accountId, $accountId],
            "DELETE FROM bigquery_connections WHERE ga4_property_id IN ({$ga4})" => [$accountId],
            "DELETE FROM ga4_properties WHERE client_id IN ({$clients})" => [$accountId],
            "DELETE FROM domain_containers WHERE container_id IN ({$gtm}) OR domain_id IN ({$domains})"
                => [$accountId, $accountId],
            "DELETE FROM gtm_containers WHERE client_id IN ({$clients})" => [$accountId],
            "DELETE FROM crawl_results WHERE audit_job_id IN ({$jobs})" => [$accountId],
            "DELETE FROM monitoring_alerts WHERE client_id IN ({$clients})" => [$accountId],
            "DELETE FROM audit_findings WHERE audit_job_id IN ({$jobs})" => [$accountId],
            "DELETE FROM audit_jobs WHERE client_id IN ({$clients})" => [$accountId],
            "DELETE FROM monitoring_schedules WHERE client_id IN ({$clients})" => [$accountId],
            "DELETE FROM cross_domain_configs WHERE client_id IN ({$clients})" => [$accountId],
            "DELETE FROM domains WHERE client_id IN ({$clients})" => [$accountId],
            "DELETE FROM google_connections WHERE account_id = ?" => [$accountId],
            "DELETE FROM clients WHERE account_id = ?" => [$accountId],
        ];

        foreach ($steps as $sql => $params) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } catch (\PDOException $e) {
                // Missing table (or similar) must not stop the rest of the cleanup.
                continue;
            }
        }

        $stmt = $pdo->prepare("DELETE FROM accounts WHERE id = ?");
        $stmt->execute([$accountId]);
    }

    private static function suffix(): string
    {
        return bin2hex(random_bytes(4));
    }
}
