<?php
namespace GTMAuditor;

// ─── Audit target resolution ─────────────────────────────────────────────────
/**
 * Decides which GTM container and GA4 property an audit of a client uses, and
 * which Google connection the audit runs under. A client can link several of each;
 * the user picks one per resource with audit_target = 1.
 */
final class AuditTargetResolver
{
    public const REASON_FOUND        = '';
    public const REASON_NOT_CONNECTED = 'not_connected';
    public const REASON_NOT_SELECTED  = 'target_not_selected';

    private const GTM_ROWS_SQL = "SELECT id, gtm_account_id, container_id, gtm_public_id,
            google_connection_id, audit_target
        FROM gtm_containers WHERE client_id = ? ORDER BY audit_target DESC, id ASC";

    private const GA4_ROWS_SQL = "SELECT id, property_id, google_connection_id, audit_target
        FROM ga4_properties WHERE client_id = ? ORDER BY audit_target DESC, id ASC";

    private const GTM_OWNER_SQL = "SELECT COUNT(*) FROM gtm_containers WHERE id = ? AND client_id = ?";
    private const GA4_OWNER_SQL = "SELECT COUNT(*) FROM ga4_properties WHERE id = ? AND client_id = ?";

    private const CONNECTION_SQL = "SELECT gc.* FROM google_connections gc
        JOIN clients c ON c.account_id = gc.account_id
        WHERE c.id = ? AND gc.id = ?";

    /**
     * Resolves the audit targets for one client.
     *
     * `gtm` is ['id' => int, 'account_id' => string (gtm_account_id), 'container_id' => string,
     * 'public_id' => string] or null. `ga4_property_id` is the GA4 property id string, and
     * `ga4_row_id` is the ga4_properties row id, or null.
     *
     * `connection` is the google_connections row used for the audit: the one behind the chosen
     * GTM container, else the one behind the chosen GA4 property. It is always limited to the
     * client's account, so a row linked to another account resolves to null.
     *
     * When the GTM container and the GA4 property sit on DIFFERENT connections, the GTM
     * connection stays primary and `ga4_connection` is also set to the GA4 connection row (or
     * null if that connection is not usable). The runner must use `ga4_connection` for GA4 calls.
     * When both are on the same connection, or only one resource is chosen, the key is absent.
     *
     * @return array{connection: ?array, gtm: ?array, gtm_reason: string, ga4_property_id: ?string, ga4_row_id: ?int, ga4_reason: string, ga4_connection?: ?array}
     */
    public function resolve(\PDO $pdo, int $clientId): array
    {
        $gtmRows = $this->fetchRows($pdo, self::GTM_ROWS_SQL, $clientId);
        $ga4Rows = $this->fetchRows($pdo, self::GA4_ROWS_SQL, $clientId);

        [$gtmRow, $gtmReason] = $this->choose($gtmRows);
        [$ga4Row, $ga4Reason] = $this->choose($ga4Rows);

        $result = [
            'connection'      => null,
            'gtm'             => $gtmRow === null ? null : [
                'id'           => (int) $gtmRow['id'],
                'account_id'   => (string) $gtmRow['gtm_account_id'],
                'container_id' => (string) $gtmRow['container_id'],
                'public_id'    => (string) $gtmRow['gtm_public_id'],
            ],
            'gtm_reason'      => $gtmReason,
            'ga4_property_id' => $ga4Row === null ? null : (string) $ga4Row['property_id'],
            'ga4_row_id'      => $ga4Row === null ? null : (int) $ga4Row['id'],
            'ga4_reason'      => $ga4Reason,
        ];

        if ($gtmRow !== null) {
            $result['connection'] = $this->fetchConnection($pdo, $clientId, (int) $gtmRow['google_connection_id']);
        } elseif ($ga4Row !== null) {
            $result['connection'] = $this->fetchConnection($pdo, $clientId, (int) $ga4Row['google_connection_id']);
        }

        if ($gtmRow !== null && $ga4Row !== null
            && (int) $gtmRow['google_connection_id'] !== (int) $ga4Row['google_connection_id']) {
            $result['ga4_connection'] = $this->fetchConnection($pdo, $clientId, (int) $ga4Row['google_connection_id']);
        }

        return $result;
    }

    /**
     * Sets the audit targets for one client. Clears every current target for the client's
     * GTM containers and GA4 properties, then marks the given rows. Null clears that resource.
     *
     * @throws \InvalidArgumentException('foreign_target') when a row id does not belong to the client
     */
    public function setTargets(\PDO $pdo, int $clientId, ?int $gtmContainerRowId, ?int $ga4PropertyRowId): void
    {
        if ($gtmContainerRowId !== null && !$this->ownsRow($pdo, self::GTM_OWNER_SQL, $gtmContainerRowId, $clientId)) {
            throw new \InvalidArgumentException('foreign_target');
        }
        if ($ga4PropertyRowId !== null && !$this->ownsRow($pdo, self::GA4_OWNER_SQL, $ga4PropertyRowId, $clientId)) {
            throw new \InvalidArgumentException('foreign_target');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE gtm_containers SET audit_target = 0 WHERE client_id = ?");
            $stmt->execute([$clientId]);
            if ($gtmContainerRowId !== null) {
                $stmt = $pdo->prepare("UPDATE gtm_containers SET audit_target = 1 WHERE id = ? AND client_id = ?");
                $stmt->execute([$gtmContainerRowId, $clientId]);
            }

            $stmt = $pdo->prepare("UPDATE ga4_properties SET audit_target = 0 WHERE client_id = ?");
            $stmt->execute([$clientId]);
            if ($ga4PropertyRowId !== null) {
                $stmt = $pdo->prepare("UPDATE ga4_properties SET audit_target = 1 WHERE id = ? AND client_id = ?");
                $stmt->execute([$ga4PropertyRowId, $clientId]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Picks the chosen row: the one with audit_target = 1, else the only row, else none.
     *
     * @return array{0: ?array, 1: string}
     */
    private function choose(array $rows): array
    {
        if ($rows === []) {
            return [null, self::REASON_NOT_CONNECTED];
        }
        foreach ($rows as $row) {
            if ((int) $row['audit_target'] === 1) {
                return [$row, self::REASON_FOUND];
            }
        }
        if (count($rows) === 1) {
            return [$rows[0], self::REASON_FOUND];
        }
        return [null, self::REASON_NOT_SELECTED];
    }

    private function fetchRows(\PDO $pdo, string $sql, int $clientId): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function fetchConnection(\PDO $pdo, int $clientId, int $connectionId): ?array
    {
        $stmt = $pdo->prepare(self::CONNECTION_SQL);
        $stmt->execute([$clientId, $connectionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function ownsRow(\PDO $pdo, string $sql, int $rowId, int $clientId): bool
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rowId, $clientId]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
