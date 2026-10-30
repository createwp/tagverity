<?php
namespace GTMAuditor;

// ─── Account-wide audit history ──────────────────────────────────────────────
/**
 * Lists audit runs across every client of one account, newest first, with optional
 * client and status filters and simple pagination.
 */
final class AuditHistory
{
    public const STATUSES = ['queued', 'running', 'complete', 'partial', 'failed'];

    /**
     * @return array{rows: array<int, array>, total: int, page: int, pages: int}
     * @throws \InvalidArgumentException when $status is not a known audit status
     */
    public static function page(\PDO $pdo, int $accountId, ?int $clientId, ?string $status, int $page, int $perPage = 25): array
    {
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('invalid_status');
        }
        $perPage = max(1, $perPage);

        $where  = ['c.account_id = ?'];
        $params = [$accountId];
        if ($clientId !== null) {
            $where[]  = 'aj.client_id = ?';
            $params[] = $clientId;
        }
        if ($status !== null) {
            $where[]  = 'aj.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_jobs aj
             JOIN clients c ON c.id = aj.client_id
             WHERE {$whereSql}"
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        // LIMIT and OFFSET are bound as integers: emulated prepares are off, so strings would fail.
        $stmt = $pdo->prepare(
            "SELECT aj.id, aj.client_id, c.name AS client_name, aj.status, aj.health_score,
                    aj.triggered_by, aj.created_at, aj.started_at, aj.completed_at
             FROM audit_jobs aj
             JOIN clients c ON c.id = aj.client_id
             WHERE {$whereSql}
             ORDER BY aj.created_at DESC, aj.id DESC
             LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($params as $value) {
            $stmt->bindValue($i++, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue($i++, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $row): array {
            $row['id']           = (int) $row['id'];
            $row['client_id']    = (int) $row['client_id'];
            $row['health_score'] = $row['health_score'] !== null ? (int) $row['health_score'] : null;
            return $row;
        }, $stmt->fetchAll());

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
