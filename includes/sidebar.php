<?php
// ─── Shared sidebar navigation ────────────────────────────────────────────────
// Requires $user to be set by the calling page (via require_auth()).
// Requires $active_page string to highlight the current nav item.

$active = $active_page ?? '';
$initial = strtoupper(substr($user['name'] ?? 'U', 0, 1));
$plan_names = [1 => 'Starter', 2 => 'Growth', 3 => 'Pro', 4 => 'Agency'];
$plan_name  = $plan_names[$user['plan_id'] ?? 1] ?? 'Starter';
?>
<nav class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">

  <!-- Logo -->
  <a href="<?= APP_URL ?>/dashboard.php" class="sidebar-logo">
    <div class="sidebar-logo-icon">🔍</div>
    <span class="sidebar-logo-text">GTM Auditor</span>
  </a>

  <!-- Main nav -->
  <div class="sidebar-section">
    <ul class="sidebar-nav" role="list">

      <li>
        <a href="<?= APP_URL ?>/dashboard.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">
          <span class="nav-icon">📊</span> Dashboard
        </a>
      </li>

      <li>
        <a href="<?= APP_URL ?>/audits.php" class="<?= $active === 'audits' ? 'active' : '' ?>">
          <span class="nav-icon">🔎</span> Audits
        </a>
      </li>

      <li>
        <a href="<?= APP_URL ?>/domain-map.php" class="<?= $active === 'domain-map' ? 'active' : '' ?>">
          <span class="nav-icon">🌐</span> Domain Map
        </a>
      </li>

      <?php if ($user['account_type'] === 'agency' || ($user['plan_id'] ?? 1) >= 4): ?>
      <li>
        <a href="<?= APP_URL ?>/clients.php" class="<?= $active === 'clients' ? 'active' : '' ?>">
          <span class="nav-icon">👥</span> Clients
        </a>
      </li>
      <?php endif; ?>

      <li class="sidebar-label">Analytics</li>

      <li>
        <a href="<?= APP_URL ?>/monitoring.php" class="<?= $active === 'monitoring' ? 'active' : '' ?>">
          <span class="nav-icon">📡</span> Monitoring
          <?php if (($user['plan_id'] ?? 1) < 2): ?>
          <span class="badge-upgrade">🚀 Upgrade</span>
          <?php endif; ?>
        </a>
      </li>

      <li>
        <a href="<?= APP_URL ?>/bigquery.php" class="<?= $active === 'bigquery' ? 'active' : '' ?>">
          <span class="nav-icon">🗄️</span> BigQuery
          <?php if (($user['plan_id'] ?? 1) < 3): ?>
          <span class="badge-upgrade">🚀 Upgrade</span>
          <?php endif; ?>
        </a>
      </li>

      <li class="sidebar-label">Account</li>

      <li>
        <a href="<?= APP_URL ?>/connections.php" class="<?= $active === 'connections' ? 'active' : '' ?>">
          <span class="nav-icon">🔗</span> Google Connections
        </a>
      </li>

      <li>
        <a href="<?= APP_URL ?>/account-settings.php" class="<?= $active === 'settings' ? 'active' : '' ?>">
          <span class="nav-icon">⚙️</span> Settings
        </a>
      </li>

      <li>
        <a href="<?= APP_URL ?>/billing.php" class="<?= $active === 'billing' ? 'active' : '' ?>">
          <span class="nav-icon">💳</span> Billing
          <span class="badge-upgrade" style="background:rgba(99,102,241,0.1);color:var(--brand-primary);border-color:rgba(99,102,241,0.3)">
            <?= htmlspecialchars($plan_name, ENT_QUOTES, 'UTF-8') ?>
          </span>
        </a>
      </li>

    </ul>
  </div>

  <!-- Footer: user info + logout -->
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-user-avatar" aria-hidden="true"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="sidebar-user-info">
        <div class="sidebar-user-name" title="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="sidebar-user-role"><?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <a href="<?= APP_URL ?>/logout.php" class="btn btn-ghost btn-sm" style="margin-left:auto;padding:4px 8px;" title="Sign out">↪</a>
    </div>
  </div>

</nav>
