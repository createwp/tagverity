<?php
namespace GTMAuditor;

// ─── Audit Job Runner — executed by the SiteGround cron every 5 minutes ──────
/**
 * The cron worker.
 *
 * Picks up the oldest queued audit_jobs row, fetches data from all active
 * Google APIs, passes data to AuditEngine, persists findings, and marks
 * the job complete.
 *
 * Designed to be called from cron/run-audits.php with:
 *   $runner = new AuditJobRunner();
 *   $runner->processNext();
 */
class AuditJobRunner
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = \db();
    }

    /**
     * Pick up and process the oldest queued audit job.
     * Returns the job ID if one was processed, or null if the queue was empty.
     *
     * @return int|null
     */
    public function processNext(): ?int
    {
        // Atomically claim the next queued job to prevent double-processing
        // if multiple cron workers run concurrently.
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM audit_jobs
                 WHERE status = 'queued'
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute();
            $job = $stmt->fetch();

            if (!$job) {
                $this->db->rollBack();
                return null;
            }

            // Mark as running immediately so other cron instances skip it
            $this->db->prepare(
                "UPDATE audit_jobs SET status = 'running', started_at = NOW() WHERE id = ?"
            )->execute([$job['id']]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('[GTMAuditor][Runner] Failed to claim job: ' . $e->getMessage());
            return null;
        }

        // ── Process the job ────────────────────────────────────────────────────
        try {
            $this->process($job);
            $this->db->prepare(
                "UPDATE audit_jobs SET status = 'complete', completed_at = NOW() WHERE id = ?"
            )->execute([$job['id']]);
        } catch (\Throwable $e) {
            $this->db->prepare(
                "UPDATE audit_jobs SET status = 'failed', error_message = ?, completed_at = NOW() WHERE id = ?"
            )->execute([substr($e->getMessage(), 0, 512), $job['id']]);
            error_log('[GTMAuditor][Runner] Job ' . $job['id'] . ' failed: ' . $e->getMessage());
        }

        return (int)$job['id'];
    }

    /**
     * Core processing logic for a single audit job.
     *
     * @param array $job  audit_jobs row
     */
    private function process(array $job): void
    {
        // 1. Load client + google connection
        $client = $this->db->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
        $client->execute([$job['client_id']]);
        $clientRow = $client->fetch();

        $conn = $this->db->prepare(
            "SELECT gc.* FROM google_connections gc
             JOIN gtm_containers gtm ON gtm.google_connection_id = gc.id
             WHERE gtm.client_id = ? LIMIT 1"
        );
        $conn->execute([$job['client_id']]);
        $connection = $conn->fetch();

        if (!$connection) {
            throw new \RuntimeException("No Google connection found for client #{$job['client_id']}");
        }

        // 2. Fetch data from Google APIs
        $apiClient = new GoogleApiClient($connection);
        $gtmData   = []; // TODO: call $apiClient->getContainerVersion(...)
        $ga4Data   = []; // TODO: call $apiClient->runGa4Report(...)
        $bqData    = []; // TODO: Phase 3 — BigQuery queries

        // 3. Run the audit engine
        $engine   = new AuditEngine($gtmData, $ga4Data, $bqData, $clientRow, (int)$job['id']);
        $findings = $engine->run();
        $score    = $engine->healthScore();

        // 4. Persist findings
        $insert = $this->db->prepare(
            "INSERT INTO audit_findings
             (audit_job_id, category, severity, title, description, recommendation,
              developer_notes, fix_owner, affected_item, evidence, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        foreach ($findings as $f) {
            $insert->execute([
                $f['audit_job_id'], $f['category'], $f['severity'],
                $f['title'], $f['description'], $f['recommendation'],
                $f['developer_notes'], $f['fix_owner'],
                $f['affected_item'], $f['evidence'],
            ]);
        }

        // 5. Update health score on the job
        $this->db->prepare(
            "UPDATE audit_jobs SET health_score = ?, credits_used = 1 WHERE id = ?"
        )->execute([$score, $job['id']]);
    }
}
