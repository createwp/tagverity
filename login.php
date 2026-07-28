<?php
// ─── Login page ───────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate-limit.php';

use Google\Client as GoogleClient;

// Already logged in → go to dashboard
if (current_user()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

// ── CSRF token ────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Build Google OAuth URL ────────────────────────────────────────────────────
$google_auth_url = '';
if (GOOGLE_CLIENT_ID) {
    $client = new GoogleClient();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setRedirectUri(APP_URL . '/api/oauth-callback.php');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');
    $client->addScope('email');
    $client->addScope('profile');
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $client->setState($state);
    $google_auth_url = $client->createAuthUrl();
}

$error = '';
$email = '';

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit: 10 attempts per IP per minute
    rate_limit('login', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 10, 60);

    // CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } else {
            try {
                $stmt = db()->prepare(
                    "SELECT u.*, a.plan_id FROM users u
                     JOIN accounts a ON a.id = u.account_id
                     WHERE u.email = ? LIMIT 1"
                );
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    login_user($user);
                    // Redirect to originally requested page or dashboard
                    $redirect = $_SESSION['intended_url'] ?? (APP_URL . '/dashboard.php');
                    unset($_SESSION['intended_url']);
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    // Use a generic message to prevent user enumeration
                    $error = 'Invalid email or password. Please try again.';
                    // Throttle to prevent timing-based enumeration
                    usleep(random_int(100000, 300000));
                }
            } catch (\Throwable $e) {
                error_log('[GTMAuditor][Login] DB error: ' . $e->getMessage());
                $error = 'A server error occurred. Please try again shortly.';
            }
        }
    }
    // Regenerate CSRF after every POST attempt
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require __DIR__ . '/includes/tmpl-login.php';
