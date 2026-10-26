<?php
require_once __DIR__ . '/icons.php';
// ─── Shared sidebar navigation ────────────────────────────────────────────────
// Requires $user to be set by the calling page (via require_auth()).
// Requires $active_page string to highlight the current nav item.

$active = $active_page ?? '';
$initial = strtoupper(substr($user['name'] ?? 'U', 0, 1));
$plan_names = [1 => 'Starter', 2 => 'Growth', 3 => 'Pro', 4 => 'Agency'];
$plan_name  = $plan_names[$user['plan_id'] ?? 1] ?? 'Starter';
$settings_pages = ['settings', 'onboarding', 'domain-map', 'connections', 'destination-connections'];
$settings_open = in_array($active, $settings_pages, true);
?>
<header class="mobile-app-bar">
  <button class="mobile-menu-toggle" id="mobile-sidebar-toggle" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
    <?= app_icon('menu') ?>
  </button>
  <a href="<?= APP_URL ?>/dashboard.php" class="mobile-app-brand" aria-label="TagVerity dashboard">
    <span class="mobile-app-brand-icon"><?= app_icon('brand') ?></span>
    <span>TagVerity</span>
  </a>
  <span class="mobile-app-avatar" aria-label="Signed in as <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>
</header>

<div class="mobile-sidebar-backdrop" id="mobile-sidebar-backdrop" aria-hidden="true"></div>

<nav class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation" tabindex="-1">

  <button class="mobile-sidebar-close" id="mobile-sidebar-close" type="button" aria-label="Close navigation"><?= app_icon('x') ?></button>

  <!-- Logo -->
  <a href="<?= APP_URL ?>/dashboard.php" class="sidebar-logo">
    <div class="sidebar-logo-icon"><?= app_icon('brand', 'app-icon app-icon-brand') ?></div>
    <span class="sidebar-logo-text">TagVerity</span>
  </a>

  <!-- Main nav -->
  <div class="sidebar-section">
    <ul class="sidebar-nav" role="list">

      <li>
        <a href="<?= APP_URL ?>/dashboard.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>" <?= $active === 'dashboard' ? 'aria-current="page"' : '' ?>>
          <span class="nav-icon"><?= app_icon('dashboard') ?></span><span class="nav-label">Dashboard</span>
        </a>
      </li>

      <li>
        <a href="<?= APP_URL ?>/tracking-workspace.php" class="<?= in_array($active, ['tracking-workspace', 'element-mapper', 'publish-tags'], true) ? 'active' : '' ?>" <?= in_array($active, ['tracking-workspace', 'element-mapper', 'publish-tags'], true) ? 'aria-current="page"' : '' ?>>
          <span class="nav-icon"><?= app_icon('tags') ?></span><span class="nav-label">Tags &amp; triggers</span>
        </a>
      </li>

      <li>
        <a href="<?= APP_URL ?>/audits.php" class="<?= $active === 'audits' ? 'active' : '' ?>" <?= $active === 'audits' ? 'aria-current="page"' : '' ?>>
          <span class="nav-icon"><?= app_icon('search') ?></span><span class="nav-label">Audits</span>
        </a>
      </li>

      <?php if (is_superadmin($user) || $user['account_type'] === 'agency' || ($user['plan_id'] ?? 1) >= 4): ?>
      <li class="sidebar-label">Workspace</li>
      <li>
        <a href="<?= APP_URL ?>/clients.php" class="<?= $active === 'clients' ? 'active' : '' ?>" <?= $active === 'clients' ? 'aria-current="page"' : '' ?>>
          <span class="nav-icon"><?= app_icon('users') ?></span><span class="nav-label">Clients</span>
        </a>
      </li>
      <?php endif; ?>

      <li class="sidebar-label">Analytics</li>

      <li>
        <a href="<?= APP_URL ?>/monitoring.php" class="<?= $active === 'monitoring' ? 'active' : '' ?>" <?= $active === 'monitoring' ? 'aria-current="page"' : '' ?>>
          <span class="nav-icon"><?= app_icon('activity') ?></span><span class="nav-label">Monitoring</span>
          <?php if (($user['plan_id'] ?? 1) < 2): ?>
          <span class="badge-upgrade">Upgrade</span>
          <?php endif; ?>
        </a>
      </li>

      <li>
        <a href="<?= APP_URL ?>/bigquery.php" class="<?= $active === 'bigquery' ? 'active' : '' ?>" <?= $active === 'bigquery' ? 'aria-current="page"' : '' ?>>
          <span class="nav-icon"><?= app_icon('database') ?></span><span class="nav-label">BigQuery</span>
          <?php if (($user['plan_id'] ?? 1) < 3): ?>
          <span class="badge-upgrade">Upgrade</span>
          <?php endif; ?>
        </a>
      </li>

      <li class="sidebar-label">Account</li>

      <li class="sidebar-nav-group">
        <details class="sidebar-nav-details" <?= $settings_open ? 'open' : '' ?>>
          <summary class="sidebar-nav-summary <?= $settings_open ? 'is-current' : '' ?>">
            <span class="nav-icon"><?= app_icon('settings') ?></span>
            <span class="nav-label">Settings</span>
            <span class="sidebar-nav-chevron" aria-hidden="true"><?= app_icon('chevron-right') ?></span>
          </summary>
          <ul class="sidebar-subnav" role="list">
            <li><a href="<?= APP_URL ?>/account-settings.php?tab=profile" class="<?= $active === 'settings' ? 'active' : '' ?>" <?= $active === 'settings' ? 'aria-current="page"' : '' ?>>Profile &amp; account</a></li>
            <li><a href="<?= APP_URL ?>/onboarding.php" class="<?= $active === 'onboarding' ? 'active' : '' ?>" <?= $active === 'onboarding' ? 'aria-current="page"' : '' ?>>Tracking setup</a></li>
            <li><a href="<?= APP_URL ?>/domain-map.php" class="<?= $active === 'domain-map' ? 'active' : '' ?>" <?= $active === 'domain-map' ? 'aria-current="page"' : '' ?>>Domain map</a></li>
            <li><a href="<?= APP_URL ?>/destination-connections.php" class="<?= $active === 'destination-connections' ? 'active' : '' ?>" <?= $active === 'destination-connections' ? 'aria-current="page"' : '' ?>>Event destinations</a></li>
            <li><a href="<?= APP_URL ?>/connections.php" class="<?= $active === 'connections' ? 'active' : '' ?>" <?= $active === 'connections' ? 'aria-current="page"' : '' ?>>Google connections</a></li>
          </ul>
        </details>
      </li>

      <li>
        <a href="<?= APP_URL ?>/billing.php" class="<?= $active === 'billing' ? 'active' : '' ?>" <?= $active === 'billing' ? 'aria-current="page"' : '' ?>>
          <span class="nav-icon"><?= app_icon('card') ?></span><span class="nav-label">Billing</span>
          <span class="badge-upgrade plan-badge">
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
      <a href="<?= APP_URL ?>/logout.php" class="sidebar-signout" title="Sign out" aria-label="Sign out"><?= app_icon('logout') ?></a>
    </div>
  </div>

</nav>
