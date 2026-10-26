<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../src/IdentifierProcessor.php';

use GTMAuditor\IdentifierProcessor;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
$hashedPiiEnabled = filter_var($input['hashed_pii_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
$requestedFields = is_array($input['allowed_hashed_fields'] ?? null) ? $input['allowed_hashed_fields'] : [];
$allowedFields = IdentifierProcessor::FIELDS;
assert_client_access($clientId);

$fields = [];
foreach ($requestedFields as $field) {
    if (is_string($field) && in_array($field, $allowedFields, true)) $fields[] = $field;
}
$fields = $hashedPiiEnabled ? array_values(array_unique($fields)) : [];
if ($hashedPiiEnabled && !$fields) {
    api_respond(['error' => 'hashed_field_required', 'message' => 'Choose at least one identifier to hash, or keep personal identifiers off.'], 422);
}
if (count($fields) !== count(array_unique(array_filter($requestedFields, 'is_string')))) {
    api_respond(['error' => 'invalid_hashed_field'], 422);
}

try {
    $stmt = db()->prepare(
        'INSERT INTO tracking_privacy_settings
         (client_id, hashed_pii_enabled, allowed_hashed_fields, updated_by_user_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE hashed_pii_enabled = VALUES(hashed_pii_enabled),
             allowed_hashed_fields = VALUES(allowed_hashed_fields), updated_by_user_id = VALUES(updated_by_user_id),
             updated_at = NOW()'
    );
    $stmt->execute([
        $clientId,
        $hashedPiiEnabled ? 1 : 0,
        json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        (int)$user['id'],
    ]);
    api_respond(['ok' => true, 'data' => [
        'raw_pii_capture' => false,
        'hashed_pii_enabled' => $hashedPiiEnabled,
        'allowed_hashed_fields' => $fields,
        'consent_required' => true,
        'configured' => true,
    ]]);
} catch (Throwable $e) {
    error_log('Tracking privacy save failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'privacy_save_failed', 'message' => 'The privacy policy could not be saved.'], 500);
}
