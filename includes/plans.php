<?php
// ─── Plan feature gating ──────────────────────────────────────────────────────
require_once __DIR__ . '/auth.php';

/**
 * Plan feature matrix.
 * Keys match the `plan_id` column in the `plans` table.
 * Values are the feature flags available on that tier.
 *
 * Starter  = 1 | Growth = 2 | Pro = 3 | Agency = 4
 */
const PLAN_FEATURES = [
    1 => [ // Starter — $49/mo
        'gtm_api'           => true,
        'ga4_reporting_api' => true,
        'headless_crawl'    => false,
        'bigquery'          => false,
        'developer_notes'   => false,
        'whitelabel'        => false,
        'client_workspaces' => false,
        'monitoring'        => 'manual',
        'max_websites'      => 1,
        'max_clients'       => 1,
        'audit_credits'     => 5,
        'alerts_email'      => false,
        'alerts_webhook'    => false,
        'alerts_slack'      => false,
    ],
    2 => [ // Growth — $99/mo
        'gtm_api'           => true,
        'ga4_reporting_api' => true,
        'headless_crawl'    => true,
        'bigquery'          => false,
        'developer_notes'   => false,
        'whitelabel'        => false,
        'client_workspaces' => false,
        'monitoring'        => 'weekly',
        'max_websites'      => 3,
        'max_clients'       => 1,
        'audit_credits'     => 20,
        'alerts_email'      => true,
        'alerts_webhook'    => false,
        'alerts_slack'      => false,
    ],
    3 => [ // Pro — $249/mo
        'gtm_api'           => true,
        'ga4_reporting_api' => true,
        'headless_crawl'    => true,
        'bigquery'          => true,
        'developer_notes'   => true,
        'whitelabel'        => false,
        'client_workspaces' => false,
        'monitoring'        => 'daily',
        'max_websites'      => 10,
        'max_clients'       => 1,
        'audit_credits'     => 100,
        'alerts_email'      => true,
        'alerts_webhook'    => true,
        'alerts_slack'      => false,
    ],
    4 => [ // Agency — $599/mo
        'gtm_api'           => true,
        'ga4_reporting_api' => true,
        'headless_crawl'    => true,
        'bigquery'          => true,
        'developer_notes'   => true,
        'whitelabel'        => true,
        'client_workspaces' => true,
        'monitoring'        => 'hourly',
        'max_websites'      => PHP_INT_MAX,
        'max_clients'       => PHP_INT_MAX,
        'audit_credits'     => PHP_INT_MAX,
        'alerts_email'      => true,
        'alerts_webhook'    => true,
        'alerts_slack'      => true,
    ],
];

/**
 * Check if the current account's plan includes a given feature.
 *
 * Usage:  if (!has_feature('bigquery')) { ... show upgrade prompt ... }
 *
 * @param string $feature  Key from PLAN_FEATURES matrix
 * @return bool
 */
function has_feature(string $feature): bool {
    $user    = current_user();
    $plan_id = (int)($user['plan_id'] ?? 1);
    $matrix  = PLAN_FEATURES[$plan_id] ?? PLAN_FEATURES[1];
    return (bool)($matrix[$feature] ?? false);
}

/**
 * Return a plan limit value (integer or string) for the current user's plan.
 *
 * @param string $key  e.g. 'max_websites', 'audit_credits', 'monitoring'
 * @return mixed
 */
function plan_limit(string $key): mixed {
    $user    = current_user();
    $plan_id = (int)($user['plan_id'] ?? 1);
    $matrix  = PLAN_FEATURES[$plan_id] ?? PLAN_FEATURES[1];
    return $matrix[$key] ?? null;
}

/**
 * Respond with 403 JSON if the current plan does not include a feature.
 * For use in API endpoints.
 *
 * @param string $feature
 * @param string $upgrade_plan  Human label for upgrade CTA
 */
function require_feature(string $feature, string $upgrade_plan = 'a higher plan'): void {
    if (!has_feature($feature)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'error'   => 'upgrade_required',
            'message' => "This feature requires $upgrade_plan. Please upgrade your subscription.",
        ]);
        exit;
    }
}
