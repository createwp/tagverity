<?php
// api/oauth-callback.php — Handles Google OAuth 2.0 callback after consent
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Google\Client as GoogleClient;

header('Content-Type: text/html; charset=utf-8');

$user = require_auth();

// Rate limit: max 10 OAuth callbacks per IP per 5 minutes
rate_limit('oauth_callback', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 10, 300);

// Validate state parameter (CSRF protection for OAuth flow)
$state = $_GET['state'] ?? '';
if (!hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
    die('Invalid OAuth state. Please try connecting again.');
}
unset($_SESSION['oauth_state']);

if (!empty($_GET['error'])) {
    // User declined consent or an error occurred
    $error = htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8');
    header('Location: ' . APP_URL . '/account-settings.php?error=oauth_' . urlencode($error));
    exit;
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    die('Missing authorization code.');
}

// Exchange code for tokens
$client = new GoogleClient();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(APP_URL . '/api/oauth-callback.php');
$client->setAccessType('offline');
$client->setPrompt('consent'); // Force refresh_token on every consent

try {
    $token = $client->fetchAccessTokenWithAuthCode($code);
    if (isset($token['error'])) {
        throw new RuntimeException($token['error_description'] ?? $token['error']);
    }

    $refreshToken    = $token['refresh_token'] ?? '';
    $googleEmail     = ''; // TODO: fetch from Google People API or id_token claims
    $googleSub       = ''; // TODO: extract from id_token

    if (empty($refreshToken)) {
        throw new RuntimeException('No refresh_token returned. User may need to revoke access and reconnect.');
    }

    // Encrypt refresh token before storage — key is environment-only, never in DB
    $encryptedToken = encrypt_token($refreshToken);

    // Upsert google_connections row
    $stmt = db()->prepare(
        "INSERT INTO google_connections
           (account_id, google_email, google_sub, refresh_token, scopes, connected_at, last_used_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           refresh_token = VALUES(refresh_token),
           scopes        = VALUES(scopes),
           last_used_at  = NOW()"
    );
    $scopes = json_encode($client->getScopes() ?? []);
    $stmt->execute([$user['account_id'], $googleEmail, $googleSub, $encryptedToken, $scopes]);

    // TODO: Trigger auto-discovery (GTM containers, GA4 properties, BigQuery datasets)

    header('Location: ' . APP_URL . '/domain-map.php?connected=1');
    exit;

} catch (\Throwable $e) {
    error_log('[GTMAuditor][OAuth] Callback failed: ' . $e->getMessage());
    header('Location: ' . APP_URL . '/account-settings.php?error=oauth_failed');
    exit;
}
