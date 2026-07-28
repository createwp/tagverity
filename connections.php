<?php
// ─── connections.php — Manage Google Account connections + trigger OAuth ──────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
$user        = require_auth();
$active_page = 'connections';

use Google\Client as GoogleClient;

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Build Google OAuth URL for connecting a new account
$google_auth_url = '';
if (GOOGLE_CLIENT_ID) {
    $client = new GoogleClient();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setRedirectUri(APP_URL . '/api/oauth-callback.php');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');
    $client->addScope('https://www.googleapis.com/auth/tagmanager.readonly');
    $client->addScope('https://www.googleapis.com/auth/analytics.readonly');
    $client->addScope('https://www.googleapis.com/auth/analytics');
    $client->addScope('https://www.googleapis.com/auth/bigquery.readonly');
    $client->addScope('https://www.googleapis.com/auth/cloud-platform.read-only');
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $client->setState($state);
    $google_auth_url = $client->createAuthUrl();
}

// Fetch existing connections for this account
$stmt = db()->prepare(
    "SELECT id, google_email, scopes, connected_at, last_used_at
     FROM google_connections WHERE account_id = ? ORDER BY connected_at DESC"
);
$stmt->execute([$user['account_id']]);
$connections = $stmt->fetchAll();

// Flash message from OAuth callback redirect
$flash_error   = $_GET['error']     ?? '';
$flash_success = $_GET['connected'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Google Connections — GTM Auditor</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="page-header">
      <div>
        <h1 class="page-title">Google Connections</h1>
        <p class="page-subtitle">Connect Google accounts to enable GTM & GA4 auditing</p>
      </div>
    </div>

    <div class="page-body">

      <?php if ($flash_error): ?>
      <div class="alert alert-error" data-auto-dismiss>
        ⚠️ OAuth error: <?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?>. Please try connecting again.
      </div>
      <?php endif; ?>

      <?php if ($flash_success): ?>
      <div class="alert alert-success" data-auto-dismiss>
        ✅ Google account connected successfully! Running auto-discovery of your GTM containers, GA4 properties, and domains…
      </div>
      <?php endif; ?>

      <!-- Connect CTA -->
      <div class="card mb-6" style="border-style:dashed;display:flex;align-items:center;gap:var(--space-6);flex-wrap:wrap">
        <div style="flex:1;min-width:240px">
          <div style="font-size:2rem;margin-bottom:var(--space-2)">🔗</div>
          <h2 style="font-size:1rem;font-weight:700;margin-bottom:var(--space-1)">Connect a Google Account</h2>
          <p class="text-sm text-muted">
            One OAuth consent screen grants access to GTM Management API, GA4 Data API, and BigQuery.
            Refresh tokens are stored AES-256 encrypted — your credentials are never stored in plain text.
          </p>
        </div>
        <?php if ($google_auth_url): ?>
        <a href="<?= htmlspecialchars($google_auth_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-google" style="width:auto;flex-shrink:0" id="connect-google-btn">
          <svg width="18" height="18" viewBox="0 0 18 18"><path fill="#4285F4" d="M16.51 8H8.98v3h4.3c-.18 1-.74 1.48-1.6 2.04v2.01h2.6a7.8 7.8 0 0 0 2.38-5.88c0-.57-.05-.66-.15-1.18z"/><path fill="#34A853" d="M8.98 17c2.16 0 3.97-.72 5.3-1.94l-2.6-2a4.8 4.8 0 0 1-7.18-2.54H1.83v2.07A8 8 0 0 0 8.98 17z"/><path fill="#FBBC05" d="M4.5 10.52a4.8 4.8 0 0 1 0-3.04V5.41H1.83a8 8 0 0 0 0 7.18l2.67-2.07z"/><path fill="#EA4335" d="M8.98 4.18c1.17 0 2.23.4 3.06 1.2l2.3-2.3A8 8 0 0 0 1.83 5.4L4.5 7.49a4.77 4.77 0 0 1 4.48-3.3z"/></svg>
          Connect Google Account
        </a>
        <?php else: ?>
        <div class="alert alert-warning" style="margin:0">
          ⚠️ Google OAuth is not configured. Set <code>GOOGLE_CLIENT_ID</code> and <code>GOOGLE_CLIENT_SECRET</code> in your <code>.env</code> file.
        </div>
        <?php endif; ?>
      </div>

      <!-- OAuth Scopes info -->
      <div class="card mb-6">
        <div class="card-header">
          <div class="card-title">Permissions requested</div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:var(--space-3)">
          <?php
          $scopes = [
            ['🏷️', 'GTM Management API', 'Read-only access to your GTM containers, tags, triggers, and variables'],
            ['📊', 'GA4 Analytics API',   'Read access to GA4 reporting data — sessions, events, dimensions'],
            ['🗄️', 'BigQuery API',         'Read-only access to GA4 BigQuery exports (Pro plan only)'],
            ['☁️', 'Cloud Platform',       'Discover GCP projects linked to your Google account'],
          ];
          foreach ($scopes as [$icon, $name, $desc]): ?>
          <div style="display:flex;gap:var(--space-3);align-items:flex-start;padding:var(--space-3);background:var(--bg-elevated);border-radius:var(--radius-sm)">
            <span style="font-size:1.3rem;flex-shrink:0"><?= $icon ?></span>
            <div>
              <div style="font-size:0.82rem;font-weight:600"><?= $name ?></div>
              <div class="text-xs text-muted"><?= $desc ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Existing connections -->
      <?php if (!empty($connections)): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Connected accounts</div>
        </div>
        <div class="table-wrap" style="border:none;border-radius:0">
          <table>
            <thead>
              <tr>
                <th>Google Account</th>
                <th>Connected</th>
                <th>Last used</th>
                <th>Scopes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($connections as $conn): ?>
              <tr>
                <td>
                  <span style="font-weight:500"><?= htmlspecialchars($conn['google_email'] ?: 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                </td>
                <td class="td-mono"><?= date('d M Y', strtotime($conn['connected_at'])) ?></td>
                <td class="td-mono"><?= $conn['last_used_at'] ? date('d M Y H:i', strtotime($conn['last_used_at'])) : '—' ?></td>
                <td>
                  <?php
                  $scopes_arr = json_decode($conn['scopes'] ?? '[]', true) ?: [];
                  $scope_count = count($scopes_arr);
                  ?>
                  <span class="badge badge-success"><?= $scope_count ?> scope<?= $scope_count !== 1 ? 's' : '' ?></span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<div id="toast-container"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
</body>
</html>
