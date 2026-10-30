<?php
namespace GTMAuditor;

use GuzzleHttp\Exception\ConnectException;

// ─── Source status classification ────────────────────────────────────────────
/**
 * Canonical status values recorded per audit source (audit_jobs.source_status).
 * fromThrowable() maps an exception raised by an outbound data call to one of them.
 */
final class SourceStatus
{
    public const SUCCESS               = 'success';
    public const NOT_APPLICABLE        = 'not_applicable';
    public const PERMISSION_DENIED     = 'permission_denied';
    public const AUTHENTICATION_FAILED = 'authentication_failed';
    public const QUOTA_LIMITED         = 'quota_limited';
    public const TIMED_OUT             = 'timed_out';
    public const UNAVAILABLE           = 'unavailable';
    public const INSUFFICIENT_COVERAGE = 'insufficient_coverage';

    private function __construct()
    {
    }

    public static function fromThrowable(\Throwable $e): string
    {
        if ($e instanceof ConnectException) {
            return self::TIMED_OUT;
        }

        switch ((int) $e->getCode()) {
            case 401:
                return self::AUTHENTICATION_FAILED;
            case 403:
                return self::PERMISSION_DENIED;
            case 429:
                return self::QUOTA_LIMITED;
            default:
                return self::UNAVAILABLE;
        }
    }
}
