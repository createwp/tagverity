<?php
namespace GTMAuditor;

require_once __DIR__ . '/DiscoverySource.php';
require_once __DIR__ . '/GoogleResourceDiscovery.php';

// ─── Google resource linking ─────────────────────────────────────────────────
/**
 * Links and unlinks the GA4 properties and GTM containers a user picked for a client.
 * Nothing is linked automatically: every id must be visible to the login that is linking it.
 */
final class GoogleResourceLinker
{
    private const CONNECTION_OF_CLIENT_SQL = "SELECT gc.id FROM google_connections gc
        JOIN clients c ON c.account_id = gc.account_id
        WHERE c.id = ? AND gc.id = ?";

    /**
     * Links the given ids from one Google login. Re-runs discovery first, so every id must be
     * visible to that login.
     *
     * @param string[] $ga4PropertyIds
     * @param string[] $gtmContainerIds
     * @return array{ga4_linked: int, gtm_linked: int}
     * @throws \InvalidArgumentException('foreign_target'|'not_accessible')
     * @throws \RuntimeException  The source status when discovery of a requested side failed.
     */
    public function link(
        \PDO $pdo,
        int $clientId,
        int $connectionId,
        int $userId,
        DiscoverySource $source,
        array $ga4PropertyIds,
        array $gtmContainerIds
    ): array {
        $ga4PropertyIds  = $this->uniqueIds($ga4PropertyIds);
        $gtmContainerIds = $this->uniqueIds($gtmContainerIds);

        $stmt = $pdo->prepare(self::CONNECTION_OF_CLIENT_SQL);
        $stmt->execute([$clientId, $connectionId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('foreign_target');
        }

        $discovery = (new GoogleResourceDiscovery())->discover($pdo, $clientId, $source);
        if ($ga4PropertyIds !== [] && $discovery['errors']['ga4'] !== null) {
            throw new \RuntimeException($discovery['errors']['ga4']);
        }
        if ($gtmContainerIds !== [] && $discovery['errors']['gtm'] !== null) {
            throw new \RuntimeException($discovery['errors']['gtm']);
        }

        $visibleProperties = [];
        foreach ($discovery['ga4_accounts'] as $account) {
            foreach ($account['properties'] as $property) {
                $visibleProperties[$property['property_id']] = [
                    'account_id'   => $account['account_id'],
                    'account_name' => $account['account_name'],
                    'display_name' => $property['display_name'],
                ];
            }
        }
        $visibleContainers = [];
        foreach ($discovery['gtm_accounts'] as $account) {
            foreach ($account['containers'] as $container) {
                $visibleContainers[$container['container_id']] = [
                    'account_id'   => $account['account_id'],
                    'account_name' => $account['account_name'],
                    'public_id'    => $container['public_id'],
                    'name'         => $container['name'],
                ];
            }
        }

        foreach ($ga4PropertyIds as $id) {
            if (!isset($visibleProperties[$id])) {
                throw new \InvalidArgumentException('not_accessible');
            }
        }
        foreach ($gtmContainerIds as $id) {
            if (!isset($visibleContainers[$id])) {
                throw new \InvalidArgumentException('not_accessible');
            }
        }

        // Streams are fetched before the transaction so no Google call runs while rows are locked.
        $streamsByProperty = [];
        foreach ($ga4PropertyIds as $id) {
            $streamsByProperty[$id] = $source->listGa4Streams($id);
        }

        $pdo->beginTransaction();
        try {
            $ga4Before = $this->countLinked($pdo, 'ga4_properties', $clientId);
            foreach ($ga4PropertyIds as $id) {
                $info = $visibleProperties[$id];
                $this->upsertGa4Property($pdo, $clientId, $connectionId, $userId, $id, $info);
                $rowId = $this->linkedRowId($pdo, 'ga4_properties', 'property_id', $clientId, $id);
                foreach ($streamsByProperty[$id] as $stream) {
                    if ((string) ($stream['measurement_id'] ?? '') === '') {
                        continue;
                    }
                    $this->upsertGa4Stream($pdo, $rowId, $stream);
                }
            }
            $this->targetIfOnlyOne($pdo, 'ga4_properties', $clientId, $ga4Before);

            $gtmBefore = $this->countLinked($pdo, 'gtm_containers', $clientId);
            foreach ($gtmContainerIds as $id) {
                $this->upsertGtmContainer($pdo, $clientId, $connectionId, $userId, $id, $visibleContainers[$id]);
            }
            $this->targetIfOnlyOne($pdo, 'gtm_containers', $clientId, $gtmBefore);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['ga4_linked' => count($ga4PropertyIds), 'gtm_linked' => count($gtmContainerIds)];
    }

    /**
     * Removes one linked property or container and its dependants. Bigquery connections of a
     * property go with it, as do the domain links to it.
     *
     * @param string $type  'ga4' or 'gtm'
     * @throws \InvalidArgumentException('foreign_target'|'invalid_type')
     */
    public function unlink(\PDO $pdo, int $clientId, string $type, int $rowId): void
    {
        if ($type !== 'ga4' && $type !== 'gtm') {
            throw new \InvalidArgumentException('invalid_type');
        }

        $ownerSql = $type === 'ga4'
            ? "SELECT COUNT(*) FROM ga4_properties WHERE id = ? AND client_id = ?"
            : "SELECT COUNT(*) FROM gtm_containers WHERE id = ? AND client_id = ?";
        $stmt = $pdo->prepare($ownerSql);
        $stmt->execute([$rowId, $clientId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new \InvalidArgumentException('foreign_target');
        }

        $steps = $type === 'ga4'
            ? [
                "DELETE FROM ga4_streams WHERE ga4_property_id = ?" => [$rowId],
                "DELETE FROM domain_ga4_properties WHERE property_id = ?" => [$rowId],
                "DELETE FROM bigquery_connections WHERE ga4_property_id = ?" => [$rowId],
                "DELETE FROM ga4_properties WHERE id = ? AND client_id = ?" => [$rowId, $clientId],
            ]
            : [
                "DELETE FROM domain_containers WHERE container_id = ?" => [$rowId],
                "DELETE FROM gtm_containers WHERE id = ? AND client_id = ?" => [$rowId, $clientId],
            ];

        $this->runSteps($pdo, $steps);
    }

    /**
     * Removes every row linked through one Google connection, across the account's clients
     * (with dependants), then the connection itself.
     *
     * @throws \InvalidArgumentException('foreign_target') when the connection is not the account's
     */
    public function disconnect(\PDO $pdo, int $accountId, int $connectionId): void
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM google_connections WHERE id = ? AND account_id = ?");
        $stmt->execute([$connectionId, $accountId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new \InvalidArgumentException('foreign_target');
        }

        $ga4Ids = $this->idsOf(
            $pdo,
            "SELECT p.id FROM ga4_properties p JOIN clients c ON c.id = p.client_id
             WHERE p.google_connection_id = ? AND c.account_id = ?",
            [$connectionId, $accountId]
        );
        $gtmIds = $this->idsOf(
            $pdo,
            "SELECT g.id FROM gtm_containers g JOIN clients c ON c.id = g.client_id
             WHERE g.google_connection_id = ? AND c.account_id = ?",
            [$connectionId, $accountId]
        );

        $steps = [];
        if ($ga4Ids !== []) {
            $in = $this->inList($ga4Ids);
            $steps["DELETE FROM ga4_streams WHERE ga4_property_id IN ({$in})"] = $ga4Ids;
            $steps["DELETE FROM domain_ga4_properties WHERE property_id IN ({$in})"] = $ga4Ids;
            $steps["DELETE FROM bigquery_connections WHERE ga4_property_id IN ({$in})"] = $ga4Ids;
            $steps["DELETE FROM ga4_properties WHERE id IN ({$in})"] = $ga4Ids;
        }
        if ($gtmIds !== []) {
            $in = $this->inList($gtmIds);
            $steps["DELETE FROM domain_containers WHERE container_id IN ({$in})"] = $gtmIds;
            $steps["DELETE FROM gtm_containers WHERE id IN ({$in})"] = $gtmIds;
        }
        $steps["DELETE FROM google_connections WHERE id = ? AND account_id = ?"] = [$connectionId, $accountId];

        $this->runSteps($pdo, $steps);
    }

    private function upsertGa4Property(\PDO $pdo, int $clientId, int $connectionId, int $userId, string $propertyId, array $info): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO ga4_properties
               (client_id, google_connection_id, property_id, display_name, ga4_account_id, ga4_account_name,
                linked_by_user_id, linked_at, last_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
               google_connection_id = VALUES(google_connection_id),
               display_name        = VALUES(display_name),
               ga4_account_id      = VALUES(ga4_account_id),
               ga4_account_name    = VALUES(ga4_account_name),
               linked_by_user_id   = COALESCE(linked_by_user_id, VALUES(linked_by_user_id)),
               linked_at           = COALESCE(linked_at, VALUES(linked_at)),
               last_synced_at      = NOW()"
        );
        $stmt->execute([
            $clientId, $connectionId, $propertyId, (string) $info['display_name'],
            (string) $info['account_id'], (string) $info['account_name'], $userId,
        ]);
    }

    private function upsertGa4Stream(\PDO $pdo, int $propertyRowId, array $stream): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO ga4_streams (ga4_property_id, stream_id, measurement_id, stream_url)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               measurement_id = VALUES(measurement_id),
               stream_url     = VALUES(stream_url)"
        );
        $stmt->execute([
            $propertyRowId,
            (string) $stream['stream_id'],
            (string) $stream['measurement_id'],
            isset($stream['stream_url']) ? (string) $stream['stream_url'] : null,
        ]);
    }

    private function upsertGtmContainer(\PDO $pdo, int $clientId, int $connectionId, int $userId, string $containerId, array $info): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO gtm_containers
               (client_id, google_connection_id, gtm_account_id, gtm_account_name, container_id, gtm_public_id,
                name, linked_by_user_id, linked_at, last_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
               google_connection_id = VALUES(google_connection_id),
               gtm_account_id       = VALUES(gtm_account_id),
               gtm_account_name     = VALUES(gtm_account_name),
               gtm_public_id        = VALUES(gtm_public_id),
               name                 = VALUES(name),
               linked_by_user_id    = COALESCE(linked_by_user_id, VALUES(linked_by_user_id)),
               linked_at            = COALESCE(linked_at, VALUES(linked_at)),
               last_synced_at       = NOW()"
        );
        $stmt->execute([
            $clientId, $connectionId, (string) $info['account_id'], (string) $info['account_name'],
            $containerId, (string) $info['public_id'], (string) $info['name'], $userId,
        ]);
    }

    /**
     * Targets the client's only linked row when the linked count of this type has just become 1.
     */
    private function targetIfOnlyOne(\PDO $pdo, string $table, int $clientId, int $countBefore): void
    {
        if ($countBefore === 1 || $this->countLinked($pdo, $table, $clientId) !== 1) {
            return;
        }
        $stmt = $pdo->prepare("UPDATE {$table} SET audit_target = 1 WHERE client_id = ?");
        $stmt->execute([$clientId]);
    }

    private function countLinked(\PDO $pdo, string $table, int $clientId): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE client_id = ?");
        $stmt->execute([$clientId]);
        return (int) $stmt->fetchColumn();
    }

    private function linkedRowId(\PDO $pdo, string $table, string $column, int $clientId, string $id): int
    {
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE client_id = ? AND {$column} = ?");
        $stmt->execute([$clientId, $id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param string[] $ids
     * @return string[]  Distinct non-empty ids, in the order given.
     */
    private function uniqueIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $clean[$id] = $id;
            }
        }
        return array_values($clean);
    }

    /**
     * @return int[]
     */
    private function idsOf(\PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function inList(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /**
     * Runs the statements in one transaction. Keys are SQL, values are their parameters.
     *
     * @param array<string, array> $steps
     */
    private function runSteps(\PDO $pdo, array $steps): void
    {
        $pdo->beginTransaction();
        try {
            foreach ($steps as $sql => $params) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
