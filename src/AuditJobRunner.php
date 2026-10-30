<?php
namespace GTMAuditor;

require_once __DIR__ . '/AuditDataSource.php';
require_once __DIR__ . '/AuditDataCollector.php';
require_once __DIR__ . '/AuditEngine.php';
require_once __DIR__ . '/AuditOutcome.php';
require_once __DIR__ . '/AuditTargetResolver.php';
require_once __DIR__ . '/SourceStatus.php';
require_once __DIR__ . '/DomainMap.php';

// ─── Audit Job Runner — executed by the SiteGround cron every 5 minutes ──────
/**
 * The cron worker.
 *
 * Picks up the oldest queued audit_jobs row, resolves the client's audit targets,
 * collects live GTM and GA4 data through one data source per Google connection,
 * passes the data to AuditEngine, persists findings, and records the outcome
 * (complete, partial or failed) with its source status.
 *
 * Designed to be called from cron/run-audits.php with:
 *   $runner = new AuditJobRunner();
 *   $runner->processNext();
 */
class AuditJobRunner
{
    private \PDO $db;

    /** @var callable(array): AuditDataSource Builds a data source from a google_connections row. */
    private $sourceFactory;

    /**
     * @param callable|null $sourceFactory  fn(array $connectionRow): AuditDataSource.
     *                                      Defaults to GoogleApiClient. Tests inject a fake.
     */
    public function __construct(?callable $sourceFactory = null)
    {
        $this->db = \db();
        $this->sourceFactory = $sourceFactory
            ?? static fn(array $connectionRow): AuditDataSource => new GoogleApiClient($connectionRow);
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
        // process() records the outcome itself. Only an unexpected exception lands here.
        try {
            $this->process($job);
        } catch (\Throwable $e) {
            $this->db->prepare(
                "UPDATE audit_jobs SET status = 'failed', error_message = ?, completed_at = NOW() WHERE id = ?"
            )->execute([substr($e->getMessage(), 0, 512), $job['id']]);
            error_log('[GTMAuditor][Runner] Job ' . $job['id'] . ' failed: ' . $e->getMessage());
        }

        return (int)$job['id'];
    }

    /**
     * Verified hosts for check 3.4: the hosts of domains answered "Yes", plus legacy rows that
     * were verified before hosts were stored (verified = 1, empty host). Legacy hosts are derived
     * from the url.
     *
     * @return string[]  De-duplicated bare hosts
     */
    private function loadVerifiedDomainHosts(int $clientId): array
    {
        $byHost = $this->db->prepare(
            "SELECT host FROM domains WHERE client_id = ? AND confirmation = 'yours' AND host <> ''"
        );
        $byHost->execute([$clientId]);
        $hosts = $byHost->fetchAll(\PDO::FETCH_COLUMN);

        $legacy = $this->db->prepare(
            "SELECT url FROM domains WHERE client_id = ? AND verified = 1 AND (host IS NULL OR host = '')"
        );
        $legacy->execute([$clientId]);
        foreach ($legacy->fetchAll(\PDO::FETCH_COLUMN) as $url) {
            $host = DomainMap::normaliseHost((string)$url);
            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique(array_map('strval', $hosts)));
    }

    /**
     * Core processing logic for a single audit job.
     *
     * @param array $job  audit_jobs row
     */
    private function process(array $job): void
    {
        $clientId = (int)$job['client_id'];

        // 1. Load client and resolve the audit targets
        $client = $this->db->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
        $client->execute([$clientId]);
        $clientRow = $client->fetch();

        $target = (new AuditTargetResolver())->resolve($this->db, $clientId);

        // 2. Collect live GTM and GA4 data
        $collected = $this->collectData($target);

        // 3. Run the audit engine
        $verifiedDomains = $this->loadVerifiedDomainHosts($clientId);

        $flagged = $this->db->prepare(
            "SELECT host, confirmation FROM domains
             WHERE client_id = ? AND confirmation IN ('not_yours', 'investigate') AND host <> ''
             ORDER BY id"
        );
        $flagged->execute([$clientId]);
        $flaggedDomains = $flagged->fetchAll(\PDO::FETCH_ASSOC);

        $engine   = new AuditEngine(
            $collected['gtm'],
            $collected['ga4'],
            [],
            $clientRow,
            (int)$job['id'],
            (string)($target['ga4_property_id'] ?? ''),
            $verifiedDomains,
            $flaggedDomains
        );
        $findings = $engine->run();

        // 4. Decide the outcome from source status and gate
        $outcome = (new AuditOutcome())->decide($collected['source_status'], $findings, $engine->healthScore());

        // 5. Persist findings
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

        // 6. Record the outcome, health score (complete only) and source status on the job
        // Store the status block and audit metadata only. Raw GTM and GA4 data stays out of the row.
        $sourceJson = $collected['source_status'] + [
            'rule_set_version' => AuditOutcome::RULE_SET_VERSION,
            'scoring_version'  => AuditOutcome::SCORING_VERSION,
            'ga4_window_days'  => AuditDataCollector::GA4_WINDOW_DAYS,
            'gtm_public_id'    => $target['gtm']['public_id'] ?? null,
            'ga4_property_id'  => $target['ga4_property_id'] ?? null,
        ];

        $this->db->prepare(
            "UPDATE audit_jobs
             SET status = ?, health_score = ?, credits_used = 1, source_status = ?, completed_at = NOW()
             WHERE id = ?"
        )->execute([
            $outcome['status'],
            $outcome['health_score'],
            json_encode($sourceJson),
            $job['id'],
        ]);
    }

    /**
     * Collects GTM and GA4 data. When GA4 sits on a different Google login (the resolver
     * sets ga4_connection), GA4 is collected through its own source. Each part is collected
     * with a target whose other half is blanked, so only the wanted source is queried.
     *
     * @return array{gtm: array, ga4: array, source_status: array}
     */
    private function collectData(array $target): array
    {
        $collector     = new AuditDataCollector();
        $gtmConnection = $target['connection'] ?? null;

        if (!array_key_exists('ga4_connection', $target)) {
            return $collector->collect($this->sourceFor($gtmConnection, $target), $target);
        }

        $gtmTarget = $target;
        $gtmTarget['ga4_property_id'] = null;
        $gtmTarget['ga4_reason'] = '';

        $ga4Target = $target;
        $ga4Target['gtm'] = null;
        $ga4Target['gtm_reason'] = '';

        $gtmPart = $collector->collect($this->sourceFor($gtmConnection, $gtmTarget), $gtmTarget);
        $ga4Part = $collector->collect($this->sourceFor($target['ga4_connection'], $ga4Target), $ga4Target);

        return [
            'gtm' => $gtmPart['gtm'],
            'ga4' => $ga4Part['ga4'],
            'source_status' => [
                'gtm'      => $gtmPart['source_status']['gtm'],
                'ga4'      => $ga4Part['source_status']['ga4'],
                'bigquery' => $gtmPart['source_status']['bigquery'],
                'crawl'    => $gtmPart['source_status']['crawl'],
            ],
        ];
    }

    /**
     * Returns the source for one connection. The factory runs only when the target needs
     * the connection. A missing connection row or a factory failure becomes a source that
     * throws that error, so the collector records it against the sources it serves.
     */
    private function sourceFor(?array $connection, array $target): AuditDataSource
    {
        if ($target['gtm'] === null && $target['ga4_property_id'] === null) {
            // Nothing to query. The collector does not call the source without a target.
            return $this->failingSource(new \LogicException('no_target'));
        }

        if ($connection === null) {
            return $this->failingSource(new \RuntimeException('connection_missing'));
        }

        try {
            return ($this->sourceFactory)($connection);
        } catch (\Throwable $e) {
            return $this->failingSource($e);
        }
    }

    private function failingSource(\Throwable $error): AuditDataSource
    {
        return new class($error) implements AuditDataSource {
            private \Throwable $error;

            public function __construct(\Throwable $error)
            {
                $this->error = $error;
            }

            public function liveContainerVersion(string $gtmAccountId, string $containerId): array
            {
                throw $this->error;
            }

            public function ga4Report(string $propertyId, array $dimensions, array $metrics, int $daysBack, int $limit): array
            {
                throw $this->error;
            }
        };
    }
}
