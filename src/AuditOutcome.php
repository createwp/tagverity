<?php
namespace GTMAuditor;

require_once __DIR__ . '/SourceStatus.php';

// ─── Audit outcome ───────────────────────────────────────────────────────────
/**
 * Decides whether an audit is complete, partial or failed from the per-source
 * status, and whether its health score may be shown.
 *
 * Only REQUIRED_SOURCES decide the outcome. A health score is returned only
 * for a complete audit. A finding whose evidence carries score_gate === true
 * caps the score at 1 (used for (not set) landing pages).
 */
final class AuditOutcome
{
    public const RULE_SET_VERSION = '2026.10.1';
    public const SCORING_VERSION  = '2026.10.1';
    public const REQUIRED_SOURCES = ['gtm', 'ga4'];

    /**
     * @param array $sourceStatus  ['gtm' => ['status' => ..., 'detail' => ...], ...]
     * @param array $findings      finding rows from AuditEngine::run()
     * @param int   $rawScore      score computed from the findings (0-100)
     * @return array{status: string, health_score: ?int}
     */
    public function decide(array $sourceStatus, array $findings, int $rawScore): array
    {
        $successCount = 0;
        $okCount      = 0;

        foreach (self::REQUIRED_SOURCES as $source) {
            $status = $sourceStatus[$source]['status'] ?? null;
            if ($status === SourceStatus::SUCCESS) {
                $successCount++;
                $okCount++;
            } elseif ($status === SourceStatus::NOT_APPLICABLE) {
                $okCount++;
            }
        }

        $required = count(self::REQUIRED_SOURCES);

        if ($okCount === $required) {
            $status = 'complete';
        } elseif ($successCount >= 1) {
            $status = 'partial';
        } else {
            $status = 'failed';
        }

        if ($status !== 'complete') {
            return ['status' => $status, 'health_score' => null];
        }

        $score = $rawScore;
        if ($this->hasScoreGate($findings)) {
            $score = min($rawScore, 1);
        }

        return ['status' => 'complete', 'health_score' => $score];
    }

    private function hasScoreGate(array $findings): bool
    {
        foreach ($findings as $finding) {
            $evidence = $finding['evidence'] ?? null;
            if (!is_string($evidence)) {
                continue;
            }
            $decoded = json_decode($evidence, true);
            if (is_array($decoded) && ($decoded['score_gate'] ?? null) === true) {
                return true;
            }
        }
        return false;
    }
}
