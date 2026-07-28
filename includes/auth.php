<?php
// ─── Authentication helpers ───────────────────────────────────────────────────
require_once __DIR__ . '/db.php';

/**
 * Returns the currently logged-in user row, or null if not authenticated.
 * Cached in a static variable to avoid repeated DB hits per request.
 *
 * @return array|null  User row with account and client context, or null
 */
function current_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;

    if (empty($_SESSION['user_id'])) {
        $user = null;
        return null;
    }

    $stmt = db()->prepare(
        "SELECT u.*, a.plan_id, a.type AS account_type
         FROM users u
         JOIN accounts a ON a.id = u.account_id
         WHERE u.id = ?
         LIMIT 1"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

/**
 * Require the user to be logged in; redirect to login page otherwise.
 * Call this at the top of every authenticated page.
 */
function require_auth(): array {
    $user = current_user();
    if (!$user) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    return $user;
}

/**
 * Require the user to hold at least the given role.
 *
 * Role hierarchy: owner > admin > member > viewer
 *
 * @param string $min_role  Minimum required role slug
 */
function require_role(string $min_role = 'member'): void {
    $user  = require_auth();
    $roles = ['viewer' => 0, 'member' => 1, 'admin' => 2, 'owner' => 3];
    $has   = $roles[$user['role']] ?? 0;
    $need  = $roles[$min_role]     ?? 0;
    if ($has < $need) {
        http_response_code(403);
        exit('Access denied.');
    }
}

/**
 * Log the user in: write session variables.
 *
 * @param array $user  User row from the database
 */
function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['account_id'] = $user['account_id'];
}

/**
 * Destroy the current session.
 */
function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
