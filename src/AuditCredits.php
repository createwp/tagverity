<?php
namespace GTMAuditor;

// ─── Monthly audit credit counting ───────────────────────────────────────────
/**
 * Counts the audit credits a client has spent in the current calendar month.
 * Queued and running jobs are charged up front, so they count. Failed jobs are not charged.
 */
final class AuditCredits
{
    public static function usedThisMonth(\PDO $pdo, int $clientId): int
    {
        // Filter on created_at, not started_at: a queued job has no started_at yet.
        // The half-open created_at range avoids wrapping the column in MONTH()/YEAR().
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_jobs
             WHERE client_id = ?
               AND status IN ('queued','running','complete','partial')
               AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
               AND created_at < DATE_FORMAT(NOW(), '%Y-%m-01') + INTERVAL 1 MONTH"
        );
        $stmt->execute([$clientId]);
        return (int) $stmt->fetchColumn();
    }
}
