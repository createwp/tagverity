<?php
// api/oauth-callback.php — Handles Google OAuth 2.0 callback after consent
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../includes/google-oauth.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/GoogleApiClient.php';

use Google\Client as GoogleClient;
use GTMAuditor\GoogleApiClient;

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
$returnPath = (string)($_SESSION['oauth_return_to'] ?? '/connections.php');
unset($_SESSION['oauth_return_to']);
if (!preg_match('#^/(destination-connections|connections|domain-map|account-settings)\.php(?:\?[a-z0-9._=&%-]+)?$#i', $returnPath)) $returnPath = '/connections.php';
$returnSeparator = str_contains($returnPath, '?') ? '&' : '?';

if (!empty($_GET['error'])) {
    // User declined consent or an error occurred
    $error = htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8');
    header('Location: ' . APP_URL . $returnPath . $returnSeparator . 'error=oauth_' . urlencode($error));
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
    foreach (google_oauth_scopes() as $scope) $client->addScope($scope);

try {
    $token = $client->fetchAccessTokenWithAuthCode($code);
    if (isset($token['error'])) {
        throw new RuntimeException($token['error_description'] ?? $token['error']);
    }

    $refreshToken    = $token['refresh_token'] ?? '';
    $idToken = is_string($token['id_token'] ?? null) ? $token['id_token'] : '';
    $identity = $idToken !== '' ? ($client->verifyIdToken($idToken) ?: []) : [];
    $googleEmail = (string)($identity['email'] ?? '');
    $googleSub = (string)($identity['sub'] ?? '');

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
    $scopes = json_encode(google_oauth_scopes());
    $stmt->execute([$user['account_id'], $googleEmail, $googleSub, $encryptedToken, $scopes]);

    $stmt = db()->prepare('SELECT id, account_id, refresh_token FROM google_connections WHERE account_id = ? AND google_sub = ? LIMIT 1');
    $stmt->execute([(int)$user['account_id'], $googleSub]);
    $savedConnection = $stmt->fetch(PDO::FETCH_ASSOC);

    $query = parse_url($returnPath, PHP_URL_QUERY);
    $returnParameters = [];
    if (is_string($query)) parse_str($query, $returnParameters);
    $clientId = (int)($returnParameters['client_id'] ?? 0);
    if ($savedConnection && $clientId > 0) {
        $stmt = db()->prepare('SELECT id FROM clients WHERE id = ? AND account_id = ? LIMIT 1');
        $stmt->execute([$clientId, (int)$user['account_id']]);
        if ($stmt->fetchColumn()) {
            $properties = (new GoogleApiClient($savedConnection))->listGa4PropertiesAndStreams();
            if ($properties) {
                $pdo = db();
                $pdo->beginTransaction();
                $deleteStreams = $pdo->prepare('DELETE gs FROM ga4_streams gs JOIN ga4_properties gp ON gp.id = gs.ga4_property_id WHERE gp.client_id = ? AND gp.google_connection_id = ?');
                $deleteStreams->execute([$clientId, (int)$savedConnection['id']]);
                $deleteProperties = $pdo->prepare('DELETE FROM ga4_properties WHERE client_id = ? AND google_connection_id = ?');
                $deleteProperties->execute([$clientId, (int)$savedConnection['id']]);
                $insertProperty = $pdo->prepare('INSERT INTO ga4_properties (client_id, google_connection_id, property_id, display_name, last_synced_at) VALUES (?, ?, ?, ?, NOW())');
                $insertStream = $pdo->prepare('INSERT INTO ga4_streams (ga4_property_id, stream_id, measurement_id, stream_url) VALUES (?, ?, ?, ?)');
                foreach ($properties as $property) {
                    $insertProperty->execute([$clientId, (int)$savedConnection['id'], (string)$property['property_id'], (string)$property['display_name']]);
                    $propertyRowId = (int)$pdo->lastInsertId();
                    foreach ($property['streams'] ?? [] as $stream) {
                        if (empty($stream['measurement_id'])) continue;
                        $insertStream->execute([$propertyRowId, (string)$stream['stream_id'], (string)$stream['measurement_id'], (string)($stream['stream_url'] ?? '')]);
                    }
                }
                $pdo->commit();
            }
        }
    }

    header('Location: ' . APP_URL . $returnPath . $returnSeparator . 'connected=1');
    exit;

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[GTMAuditor][OAuth] Callback failed: ' . $e->getMessage());
    header('Location: ' . APP_URL . $returnPath . $returnSeparator . 'error=oauth_failed');
    exit;
}
