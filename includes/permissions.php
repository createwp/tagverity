<?php
// ─── Client access guard ──────────────────────────────────────────────────────
require_once __DIR__ . '/auth.php';

/**
 * Assert that the logged-in user's account owns the given client_id.
 *
 * Call this before accessing ANY client-scoped data (audits, findings,
 * domains, GTM containers, etc.) to prevent cross-tenant data access.
 *
 * Exits with HTTP 403 if the assertion fails.
 *
 * @param int $client_id  The client row ID to verify ownership of
 * @return array          The client row (for convenience)
 */
function assert_client_access(int $client_id): array {
    $user = require_auth();
    $stmt = db()->prepare(
        "SELECT * FROM clients WHERE id = ? AND account_id = ? LIMIT 1"
    );
    $stmt->execute([$client_id, $user['account_id']]);
    $client = $stmt->fetch();
    if (!$client) {
        http_response_code(403);
        exit('Access denied: client not found or not owned by your account.');
    }
    return $client;
}

/**
 * Assert that the logged-in user's account owns the given audit_job_id.
 * Resolves client ownership via the audit_jobs.client_id foreign key.
 *
 * @param int $audit_job_id
 * @return array  The audit_jobs row
 */
function assert_audit_access(int $audit_job_id): array {
    $user = require_auth();
    $stmt = db()->prepare(
        "SELECT aj.* FROM audit_jobs aj
         JOIN clients c ON c.id = aj.client_id
         WHERE aj.id = ? AND c.account_id = ? LIMIT 1"
    );
    $stmt->execute([$audit_job_id, $user['account_id']]);
    $job = $stmt->fetch();
    if (!$job) {
        http_response_code(403);
        exit('Access denied: audit job not found or not owned by your account.');
    }
    return $job;
}
