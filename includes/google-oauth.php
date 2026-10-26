<?php

function google_oauth_scopes(): array {
    return [
        'openid',
        'email',
        'https://www.googleapis.com/auth/tagmanager.readonly',
        'https://www.googleapis.com/auth/tagmanager.edit.containers',
        'https://www.googleapis.com/auth/tagmanager.edit.containerversions',
        'https://www.googleapis.com/auth/tagmanager.publish',
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/analytics',
        'https://www.googleapis.com/auth/adwords',
        'https://www.googleapis.com/auth/bigquery.readonly',
        'https://www.googleapis.com/auth/cloud-platform.read-only',
    ];
}

function google_oauth_url(string $returnPath): string {
    if (!GOOGLE_CLIENT_ID || !GOOGLE_CLIENT_SECRET) return '';
    $encryptionKey = base64_decode(TOKEN_ENCRYPTION_KEY, true);
    if (!is_string($encryptionKey) || strlen($encryptionKey) !== 32) return '';
    if (!preg_match('#^/[a-z0-9._/?=&%-]+$#i', $returnPath)) $returnPath = '/connections.php';

    $client = new Google\Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setRedirectUri(APP_URL . '/api/oauth-callback.php');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');
    foreach (google_oauth_scopes() as $scope) $client->addScope($scope);
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_return_to'] = $returnPath;
    $client->setState($state);
    return $client->createAuthUrl();
}
