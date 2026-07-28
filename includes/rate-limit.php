<?php
// ─── Rate limiting — per IP + per user ────────────────────────────────────────
require_once __DIR__ . '/db.php';

/**
 * Enforce a simple rate limit using a DB table (rate_limits).
 *
 * Records attempts keyed on (action, identifier). If the count exceeds
 * $max within $window_seconds, throws a 429 response and exits.
 *
 * @param string $action      e.g. 'login', 'oauth_callback', 'audit_trigger'
 * @param string $identifier  IP address or user ID as string
 * @param int    $max         Max allowed attempts in the window
 * @param int    $window_seconds  Rolling window in seconds
 */
function rate_limit(string $action, string $identifier, int $max = 10, int $window_seconds = 60): void {
    $pdo = db();
    $since = date('Y-m-d H:i:s', time() - $window_seconds);

    // Count recent attempts
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_limits
         WHERE action = ? AND identifier = ? AND attempted_at > ?"
    );
    $stmt->execute([$action, $identifier, $since]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= $max) {
        http_response_code(429);
        header('Retry-After: ' . $window_seconds);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.']);
        exit;
    }

    // Log this attempt
    $pdo->prepare("INSERT INTO rate_limits (action, identifier, attempted_at) VALUES (?, ?, NOW())")
        ->execute([$action, $identifier]);
}

/**
 * Prune rate_limits rows older than 24 hours.
 * Call from a cron job — not on every request.
 */
function prune_rate_limits(): int {
    $stmt = db()->prepare("DELETE FROM rate_limits WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute();
    return $stmt->rowCount();
}
