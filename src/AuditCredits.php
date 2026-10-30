<?php
namespace GTMAuditor;

// ─── Monthly audit credit counting ───────────────────────────────────────────
/**
 * Audit credits are per ACCOUNT per calendar month, across all of the account's clients.
 * Queued and running jobs are charged up front, so they count. Failed jobs are not charged.
 */
final class AuditCredits
{
    public static function usedThisMonthForAccount(\PDO $pdo, int $accountId): int
    {
        // Filter on created_at, not started_at: a queued job has no started_at yet.
        // The half-open created_at range avoids wrapping the column in MONTH()/YEAR().
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_jobs aj
             JOIN clients c ON c.id = aj.client_id
             WHERE c.account_id = ?
               AND aj.status IN ('queued','running','complete','partial')
               AND aj.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
               AND aj.created_at < DATE_FORMAT(NOW(), '%Y-%m-01') + INTERVAL 1 MONTH"
        );
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * True when the account can start another audit. A limit of -1 means unlimited.
     */
    public static function hasCreditsLeft(int $used, int $limit): bool
    {
        return $limit < 0 || $used < $limit;
    }
}
