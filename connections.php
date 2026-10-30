<?php
// ─── connections.php — Google logins, linked GA4 properties and GTM containers per client ───
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/google-oauth.php';
$user        = require_auth();
$active_page = 'connections';

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$role      = $user['role'] ?? '';
$can_edit  = in_array($role, ['superadmin', 'owner', 'admin', 'member'], true);
$is_owner  = in_array($role, ['superadmin', 'owner'], true);

// Build Google OAuth URL for connecting a new account
$google_auth_url = google_oauth_url('/connections.php');

$pdo = db();

// Every Google login on this account
$stmt = $pdo->prepare(
    "SELECT id, google_email, connected_at
     FROM google_connections WHERE account_id = ? ORDER BY connected_at DESC, id DESC"
);
$stmt->execute([$user['account_id']]);
$connections = $stmt->fetchAll();

// Clients for the switcher. The requested client must belong to this account, else the first one.
$stmt = $pdo->prepare("SELECT id, name FROM clients WHERE account_id = ? ORDER BY id ASC");
$stmt->execute([$user['account_id']]);
$clients = $stmt->fetchAll();

$requested_client_id = (int)($_GET['client_id'] ?? 0);
$client = null;
foreach ($clients as $candidate) {
    if ((int)$candidate['id'] === $requested_client_id) {
        $client = $candidate;
        break;
    }
}
if ($client === null && $clients !== []) {
    $client = $clients[0];
}

// Linked resources for the chosen client, with the Google login each one came through
$ga4_rows = [];
$gtm_rows = [];
if ($client !== null) {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.property_id, p.display_name, p.ga4_account_name, p.audit_target,
                gc.google_email AS login_email
         FROM ga4_properties p
         LEFT JOIN google_connections gc ON gc.id = p.google_connection_id AND gc.account_id = ?
         WHERE p.client_id = ?
         ORDER BY p.audit_target DESC, p.id ASC"
    );
    $stmt->execute([$user['account_id'], $client['id']]);
    $ga4_rows = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT g.id, g.container_id, g.gtm_public_id, g.name, g.gtm_account_name, g.audit_target,
                gc.google_email AS login_email
         FROM gtm_containers g
         LEFT JOIN google_connections gc ON gc.id = g.google_connection_id AND gc.account_id = ?
         WHERE g.client_id = ?
         ORDER BY g.audit_target DESC, g.id ASC"
    );
    $stmt->execute([$user['account_id'], $client['id']]);
    $gtm_rows = $stmt->fetchAll();
}

$ga4_targeted = array_filter($ga4_rows, static fn($row) => (int)$row['audit_target'] === 1) !== [];
$gtm_targeted = array_filter($gtm_rows, static fn($row) => (int)$row['audit_target'] === 1) !== [];

// pick=1&connection_id=M opens that login's picker on load (editors only, login must be the account's)
$auto_pick_connection = 0;
if (($_GET['pick'] ?? '') === '1' && $can_edit) {
    $requested_connection = (int)($_GET['connection_id'] ?? 0);
    foreach ($connections as $connection) {
        if ((int)$connection['id'] === $requested_connection) {
            $auto_pick_connection = $requested_connection;
            break;
        }
    }
}

// Flash messages from OAuth callback and linking redirects
$flash_error   = $_GET['error']     ?? '';
$flash_success = $_GET['connected'] ?? '';
$flash_linked  = (int)($_GET['linked'] ?? 0);
if ($flash_linked < 0 || $flash_linked > 1000) {
    $flash_linked = 0;
}
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
      <div class="page-header-copy">
        <h1 class="page-title">Google connections</h1>
        <p class="page-subtitle"><?php if ($client !== null): ?><?= $e($client['name']) ?> &middot; <?php endif; ?>Connect Google, then choose which GA4 properties and GTM containers each client uses.</p>
      </div>
      <?php if (count($clients) > 1): ?>
      <div class="page-header-actions">
        <form method="get" action="connections.php" class="connections-client-switch">
          <label class="connections-switch-label" for="connections-client">Client</label>
          <select class="form-select" id="connections-client" name="client_id">
            <?php foreach ($clients as $option): ?>
            <option value="<?= (int)$option['id'] ?>"<?= $client !== null && (int)$option['id'] === (int)$client['id'] ? ' selected' : '' ?>><?= $e($option['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-secondary" type="submit">Show client</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <div class="page-body connections-page" id="connections-app" data-client-id="<?= $client !== null ? (int)$client['id'] : 0 ?>" data-api-base="<?= $e(APP_URL) ?>" data-auto-pick="<?= $auto_pick_connection ?>">
      <input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_token']) ?>">

      <?php if ($flash_error): ?>
      <div class="alert alert-error" data-auto-dismiss>
        <span class="alert-icon"><?= app_icon('alert') ?></span><span>OAuth error: <?= $e($flash_error) ?>. Please try connecting again.</span>
      </div>
      <?php endif; ?>

      <?php if ($flash_success): ?>
      <div class="alert alert-success" data-auto-dismiss>
        <span class="alert-icon"><?= app_icon('check-circle') ?></span><span>Google account connected successfully! Choose the GA4 properties and GTM containers to link below.</span>
      </div>
      <?php endif; ?>

      <?php if ($flash_linked > 0): ?>
      <div class="alert alert-success" data-auto-dismiss>
        <span class="alert-icon"><?= app_icon('check-circle') ?></span><span>Linked <?= $flash_linked ?> Google resource<?= $flash_linked === 1 ? '' : 's' ?> to this client.</span>
      </div>
      <?php endif; ?>

      <!-- ─── 1. Google accounts ─── -->
      <?php if ($connections === []): ?>
      <div class="card mb-6 connections-connect-cta">
        <div>
          <div class="feature-icon"><?= app_icon('link') ?></div>
          <h2 class="card-title">Connect a Google account</h2>
          <p class="text-sm text-muted">
            One OAuth consent screen grants access to GTM Management API, GA4 Data API, and BigQuery.
            Refresh tokens are stored AES-256 encrypted, so your credentials are never stored in plain text.
          </p>
        </div>
        <?php if ($google_auth_url): ?>
        <a href="<?= $e($google_auth_url) ?>" class="btn btn-google connections-cta-button" id="connect-google-btn">
          <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path fill="#4285F4" d="M16.51 8H8.98v3h4.3c-.18 1-.74 1.48-1.6 2.04v2.01h2.6a7.8 7.8 0 0 0 2.38-5.88c0-.57-.05-.66-.15-1.18z"/><path fill="#34A853" d="M8.98 17c2.16 0 3.97-.72 5.3-1.94l-2.6-2a4.8 4.8 0 0 1-7.18-2.54H1.83v2.07A8 8 0 0 0 8.98 17z"/><path fill="#FBBC05" d="M4.5 10.52a4.8 4.8 0 0 1 0-3.04V5.41H1.83a8 8 0 0 0 0 7.18l2.67-2.07z"/><path fill="#EA4335" d="M8.98 4.18c1.17 0 2.23.4 3.06 1.2l2.3-2.3A8 8 0 0 0 1.83 5.4L4.5 7.49a4.77 4.77 0 0 1 4.48-3.3z"/></svg>
          Connect Google account
        </a>
        <?php else: ?>
        <div class="alert alert-warning" style="margin:0">
          <span class="alert-icon"><?= app_icon('alert') ?></span><span>Google OAuth is not configured. Set <code>GOOGLE_CLIENT_ID</code> and <code>GOOGLE_CLIENT_SECRET</code> in your <code>.env</code> file.</span>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <section class="card mb-6" aria-labelledby="google-accounts-heading">
        <div class="card-header connections-card-header">
          <div>
            <h2 class="card-title" id="google-accounts-heading">Google accounts</h2>
            <p class="card-subtitle">Each Google login can see different GA4 properties and GTM containers.</p>
          </div>
          <?php if ($connections !== [] && $google_auth_url): ?>
          <a href="<?= $e($google_auth_url) ?>" class="btn btn-secondary connections-add-button" id="connect-google-btn">
            <?= app_icon('plus') ?> Connect another Google account
          </a>
          <?php elseif ($connections !== []): ?>
          <div class="alert alert-warning" style="margin:0">
            <span class="alert-icon"><?= app_icon('alert') ?></span><span>Google OAuth is not configured. Set <code>GOOGLE_CLIENT_ID</code> and <code>GOOGLE_CLIENT_SECRET</code> in your <code>.env</code> file.</span>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($connections === []): ?>
        <p class="text-sm text-muted">No Google account is connected yet.</p>
        <?php else: ?>
        <ul class="connection-list" role="list">
          <?php foreach ($connections as $connection): ?>
          <?php $login = $connection['google_email'] !== '' ? $connection['google_email'] : 'Unknown'; ?>
          <li class="connection-row">
            <div class="connection-identity">
              <span class="feature-list-icon"><?= app_icon('user') ?></span>
              <div class="connection-copy">
                <strong class="connection-email"><?= $e($login) ?></strong>
                <span class="text-xs text-muted">Connected <?= $e(date('d M Y', strtotime($connection['connected_at']))) ?></span>
              </div>
            </div>
            <?php if ($can_edit || $is_owner): ?>
            <div class="connection-actions">
              <?php if ($can_edit): ?>
              <button type="button" class="btn btn-secondary" data-link-connection="<?= (int)$connection['id'] ?>" data-connection-email="<?= $e($login) ?>" aria-haspopup="dialog" aria-label="Link properties from <?= $e($login) ?>">
                <?= app_icon('link') ?> Link properties
              </button>
              <?php endif; ?>
              <?php if ($is_owner): ?>
              <button type="button" class="btn btn-danger-outline" data-disconnect-connection="<?= (int)$connection['id'] ?>" data-connection-email="<?= $e($login) ?>" aria-label="Disconnect <?= $e($login) ?>">Disconnect</button>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>

      <!-- ─── 2. Linked to this client ─── -->
      <section class="card mb-6" aria-labelledby="linked-heading">
        <div class="card-header">
          <div>
            <h2 class="card-title" id="linked-heading"><?= $client !== null ? 'Linked to ' . $e($client['name']) : 'Linked to this client' ?></h2>
            <p class="card-subtitle"><?= $can_edit ? 'Audits read from the property and container marked “Use for audits”.' : 'Audits read from the property and container marked “Used for audits”. Ask an editor to change them.' ?></p>
          </div>
        </div>

        <?php if ($client === null): ?>
        <div class="empty-state">
          <div class="empty-state-icon"><?= app_icon('globe') ?></div>
          <h3>Add a client website first</h3>
          <p>Linked Google properties and containers belong to a client. Add one, then come back here.</p>
        </div>
        <?php else: ?>
        <div id="linked-resources" class="linked-resources">

          <fieldset class="linked-group">
            <legend class="linked-group-title">Google Analytics 4 properties</legend>
            <?php if ($ga4_rows === []): ?>
            <p class="linked-empty">No GA4 property is linked to this client yet.</p>
            <?php else: ?>
            <ul class="linked-list" role="list">
              <?php foreach ($ga4_rows as $row): ?>
              <?php $name = $row['display_name'] !== '' ? $row['display_name'] : 'Untitled property'; ?>
              <li class="linked-row">
                <div class="linked-copy">
                  <strong><?= $e($name) ?></strong>
                  <span class="linked-meta">Property ID <span class="font-mono"><?= $e($row['property_id']) ?></span></span>
                  <span class="linked-meta"><?= $e($row['ga4_account_name'] !== '' ? $row['ga4_account_name'] : 'Unknown account') ?> &middot; <?= $e($row['login_email'] ?: 'Unknown login') ?></span>
                </div>
                <div class="linked-controls">
                  <?php if ($can_edit): ?>
                  <label class="audit-target-option">
                    <input type="radio" name="ga4_audit_target" value="<?= (int)$row['id'] ?>" aria-label="Use <?= $e($name) ?> for audits"<?= (int)$row['audit_target'] === 1 ? ' checked' : '' ?>>
                    <span>Use for audits</span>
                  </label>
                  <button type="button" class="btn btn-ghost btn-sm" data-unlink-type="ga4" data-unlink-id="<?= (int)$row['id'] ?>" data-unlink-name="<?= $e($name) ?>" aria-label="Unlink <?= $e($name) ?>">Unlink</button>
                  <?php elseif ((int)$row['audit_target'] === 1): ?>
                  <span class="badge badge-success">Used for audits</span>
                  <?php endif; ?>
                </div>
              </li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <p class="form-hint linked-target-hint" data-target-hint="ga4"<?= count($ga4_rows) > 1 && !$ga4_targeted ? '' : ' hidden' ?>>Choose which one audits should use.</p>
          </fieldset>

          <fieldset class="linked-group">
            <legend class="linked-group-title">Google Tag Manager containers</legend>
            <?php if ($gtm_rows === []): ?>
            <p class="linked-empty">No GTM container is linked to this client yet.</p>
            <?php else: ?>
            <ul class="linked-list" role="list">
              <?php foreach ($gtm_rows as $row): ?>
              <?php $name = $row['name'] !== '' ? $row['name'] : 'Untitled container'; ?>
              <li class="linked-row">
                <div class="linked-copy">
                  <strong><?= $e($name) ?></strong>
                  <span class="linked-meta">Container ID <span class="font-mono"><?= $e($row['gtm_public_id']) ?></span></span>
                  <span class="linked-meta"><?= $e($row['gtm_account_name'] !== '' ? $row['gtm_account_name'] : 'Unknown account') ?> &middot; <?= $e($row['login_email'] ?: 'Unknown login') ?></span>
                </div>
                <div class="linked-controls">
                  <?php if ($can_edit): ?>
                  <label class="audit-target-option">
                    <input type="radio" name="gtm_audit_target" value="<?= (int)$row['id'] ?>" aria-label="Use <?= $e($name) ?> for audits"<?= (int)$row['audit_target'] === 1 ? ' checked' : '' ?>>
                    <span>Use for audits</span>
                  </label>
                  <button type="button" class="btn btn-ghost btn-sm" data-unlink-type="gtm" data-unlink-id="<?= (int)$row['id'] ?>" data-unlink-name="<?= $e($name) ?>" aria-label="Unlink <?= $e($name) ?>">Unlink</button>
                  <?php elseif ((int)$row['audit_target'] === 1): ?>
                  <span class="badge badge-success">Used for audits</span>
                  <?php endif; ?>
                </div>
              </li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <p class="form-hint linked-target-hint" data-target-hint="gtm"<?= count($gtm_rows) > 1 && !$gtm_targeted ? '' : ' hidden' ?>>Choose which one audits should use.</p>
          </fieldset>

        </div>
        <?php endif; ?>
      </section>

      <!-- ─── Permissions ─── -->
      <div class="card mb-6">
        <div class="card-header">
          <div class="card-title">Permissions requested</div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(280px,100%),1fr));gap:var(--space-3)">
          <?php
          $scopes = [
            ['tags', 'GTM Management API', 'Read, edit, and publish access so approved TagVerity releases can be deployed'],
            ['dashboard', 'GA4 Analytics API',   'Read access to GA4 reporting data — sessions, events, dimensions'],
            ['database', 'BigQuery API',         'Read-only access to GA4 BigQuery exports (Pro plan only)'],
            ['server', 'Cloud Platform',       'Discover GCP projects linked to your Google account'],
          ];
          foreach ($scopes as [$icon, $name, $desc]): ?>
          <div style="display:flex;gap:var(--space-3);align-items:flex-start;padding:var(--space-3);background:var(--bg-elevated);border-radius:var(--radius-sm)">
            <span class="feature-list-icon"><?= app_icon($icon) ?></span>
            <div>
              <div style="font-size:0.82rem;font-weight:600"><?= $name ?></div>
              <div class="text-xs text-muted"><?= $desc ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($can_edit): ?>
      <!-- ─── Resource picker (opened per Google login) ─── -->
      <dialog id="resource-picker" class="resource-picker" aria-labelledby="resource-picker-title" aria-describedby="resource-picker-login">
        <div class="resource-picker-inner">
          <header class="resource-picker-header">
            <div class="resource-picker-heading">
              <h2 id="resource-picker-title">Link Google resources</h2>
              <p class="page-subtitle" id="resource-picker-login">Choose what this client should use.</p>
            </div>
            <button type="button" class="btn btn-ghost resource-picker-close" id="resource-picker-close" aria-label="Close resource picker"><?= app_icon('x') ?></button>
          </header>

          <div class="resource-picker-search">
            <label class="picker-search-field" for="resource-picker-search">
              <?= app_icon('search') ?>
              <span class="sr-only">Search by name or ID</span>
              <input id="resource-picker-search" type="search" placeholder="Search by name or ID" autocomplete="off">
            </label>
          </div>

          <div class="resource-picker-body" id="resource-picker-body" aria-live="polite" tabindex="-1">
            <p class="picker-state">Finding your Google properties…</p>
          </div>

          <footer class="resource-picker-footer">
            <p class="picker-count" id="resource-picker-count" aria-live="polite">No items selected</p>
            <p class="picker-error" id="resource-picker-error" role="alert" hidden></p>
            <div class="resource-picker-actions">
              <button type="button" class="btn btn-secondary" id="resource-picker-cancel">Cancel</button>
              <button type="button" class="btn btn-primary" id="resource-picker-link" disabled>Link selected</button>
            </div>
          </footer>
        </div>
      </dialog>
      <?php endif; ?>

    </div>
  </div>
</div>
<div id="toast-container" aria-live="polite"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php if ($client !== null): ?>
<script src="<?= asset_url('assets/js/connections.js') ?>"></script>
<?php endif; ?>
</body>
</html>
