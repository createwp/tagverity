<?php
namespace GTMAuditor;

require_once __DIR__ . '/SourceStatus.php';

// ─── Audit data coverage copy ────────────────────────────────────────────────
/**
 * Turns the per-source status recorded on an audit (audit_jobs.source_status) into
 * one plain-language row per source for the Data coverage card. Copy is written for
 * non-technical marketers; callers must escape the output.
 */
final class AuditCoverageView
{
    private const SOURCE_LABELS = [
        'gtm'      => 'Google Tag Manager',
        'ga4'      => 'Google Analytics 4',
        'bigquery' => 'BigQuery',
        'crawl'    => 'Site crawl',
    ];

    /**
     * @param ?array $sourceStatus  audit_jobs.source_status, or null for jobs recorded before coverage tracking
     * @return list<array{source: string, status: string, tone: string, message: string}>
     */
    public function describe(?array $sourceStatus): array
    {
        if ($sourceStatus === null) {
            return [[
                'source'  => 'Data coverage',
                'status'  => 'not_recorded',
                'tone'    => 'muted',
                'message' => 'Recorded before coverage tracking was added.',
            ]];
        }

        $rows = [];
        foreach (self::SOURCE_LABELS as $key => $label) {
            $entry  = $sourceStatus[$key] ?? [];
            $status = (string) ($entry['status'] ?? SourceStatus::UNAVAILABLE);
            $detail = (string) ($entry['detail'] ?? '');

            $rows[] = [
                'source'  => $label,
                'status'  => $status,
                'tone'    => $this->toneFor($status),
                'message' => $this->messageFor($status, $detail),
            ];
        }

        return $rows;
    }

    private function toneFor(string $status): string
    {
        if ($status === SourceStatus::SUCCESS) {
            return 'ok';
        }
        if ($status === SourceStatus::NOT_APPLICABLE) {
            return 'muted';
        }
        return 'warn';
    }

    private function messageFor(string $status, string $detail): string
    {
        switch ($status) {
            case SourceStatus::SUCCESS:
                return 'Checked successfully.';
            case SourceStatus::NOT_APPLICABLE:
                return 'Not part of this audit.';
            case SourceStatus::PERMISSION_DENIED:
                return "We don't have permission. Ask the account owner to grant access, then reconnect Google.";
            case SourceStatus::AUTHENTICATION_FAILED:
                return 'Your Google connection has expired. Reconnect Google to continue.';
            case SourceStatus::QUOTA_LIMITED:
                return "Google asked us to slow down. We'll include this next time.";
            case SourceStatus::TIMED_OUT:
                return "Google didn't respond in time. Try the audit again.";
            case SourceStatus::UNAVAILABLE:
                if ($detail === 'not_connected') {
                    return 'Not connected yet. Connect Google to include this.';
                }
                return "We couldn't read this source. Try again later.";
            case SourceStatus::INSUFFICIENT_COVERAGE:
                if ($detail === 'target_not_selected') {
                    return 'Several found — choose which one to audit on the Connections page.';
                }
                return "We couldn't read this source. Try again later.";
            default:
                return "We couldn't read this source. Try again later.";
        }
    }
}
