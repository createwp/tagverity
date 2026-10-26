<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../src/DestinationConnectionManager.php';

use GTMAuditor\DestinationConnectionManager;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
$platform = trim((string)($input['platform'] ?? ''));
$fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
assert_client_access($clientId);

try {
    $pdo = db();
    $manager = new DestinationConnectionManager();
    $definition = $manager->definition($platform);
    if (!$definition) api_respond(['error' => 'unsupported_platform', 'message' => 'Choose a supported destination.'], 422);

    $stmt = $pdo->prepare('SELECT encrypted_credentials FROM destination_connections WHERE client_id = ? AND platform = ? LIMIT 1');
    $stmt->execute([$clientId, $platform]);
    $existingEncrypted = (string)($stmt->fetchColumn() ?: '');
    $validated = $manager->validate($platform, $fields, $existingEncrypted !== '');

    $secrets = [];
    if ($existingEncrypted !== '') {
        $decoded = json_decode(decrypt_token($existingEncrypted), true);
        if (is_array($decoded)) $secrets = $decoded;
    }
    $secrets = array_replace($secrets, $validated['secrets']);
    $encrypted = $secrets ? encrypt_token(json_encode($secrets, JSON_UNESCAPED_SLASHES)) : null;

    $stmt = $pdo->prepare(
        'INSERT INTO destination_connections
         (client_id, platform, display_name, configuration, encrypted_credentials, status, updated_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), configuration = VALUES(configuration),
         encrypted_credentials = VALUES(encrypted_credentials), status = VALUES(status), last_error_code = NULL,
         updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()'
    );
    $stmt->execute([
        $clientId,
        $platform,
        $definition['label'],
        json_encode($validated['configuration'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $encrypted,
        'configured',
        (int)$user['id'],
    ]);

    foreach ($manager->load($pdo, $clientId) as $connection) {
        if ($connection['platform'] === $platform) api_respond(['ok' => true, 'data' => $connection]);
    }
    throw new RuntimeException('saved_connection_not_found');
} catch (InvalidArgumentException $e) {
    $messages = [
        'missing_measurement_id' => 'Choose a GA4 website stream.',
        'missing_api_secret' => 'Add the server connection secret from GA4.',
        'invalid_measurement_id' => 'The GA4 website stream ID should start with G-.',
        'missing_host' => 'Choose where your PostHog project is hosted.',
        'invalid_posthog_host' => 'Choose a secure PostHog region or HTTPS address.',
        'missing_project_api_key' => 'Add the project token from PostHog project settings.',
        'missing_pixel_id' => 'Add the Dataset or Pixel ID from Meta Events Manager.',
        'invalid_pixel_id' => 'The Meta Dataset or Pixel ID should contain numbers only.',
        'missing_access_token' => 'Add the server access token from Meta Events Manager.',
        'missing_customer_id' => 'Add the Google Ads account ID.',
        'invalid_customer_id' => 'The Google Ads account ID should contain ten numbers.',
        'missing_conversion_action_id' => 'Add the Google Ads conversion action ID.',
        'invalid_conversion_action_id' => 'The conversion action ID should contain numbers only.',
    ];
    api_respond(['error' => $e->getMessage(), 'message' => $messages[$e->getMessage()] ?? 'Check the highlighted connection details and try again.'], 422);
} catch (Throwable $e) {
    error_log('Destination connection save failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'connection_save_failed', 'message' => 'The destination could not be saved securely. Check the encryption key configuration.'], 500);
}
