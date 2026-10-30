<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/google-oauth.php';

$scopes = google_oauth_scopes();
foreach (['openid', 'email', 'https://www.googleapis.com/auth/analytics', 'https://www.googleapis.com/auth/adwords'] as $scope) {
    if (!in_array($scope, $scopes, true)) {
        fwrite(STDERR, "Google OAuth is missing the required scope: {$scope}.\n");
        exit(1);
    }
}

$callback = file_get_contents(__DIR__ . '/../api/oauth-callback.php');
if (strpos($callback, 'listGa4PropertiesAndStreams') !== false) {
    fwrite(STDERR, "Google OAuth callback still imports GA4 properties on connect.\n");
    exit(1);
}

foreach (['oauth_return_to', 'pick=1', 'verifyIdToken', 'destination-connections'] as $expected) {
    if (strpos($callback, $expected) === false) {
        fwrite(STDERR, "Google OAuth callback is missing: {$expected}.\n");
        exit(1);
    }
}

echo "Google OAuth flow tests passed.\n";
