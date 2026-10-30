<?php
// ─── Monitoring (not available yet) ───────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/plans.php';
$user        = require_auth();
$active_page = 'monitoring';

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// Plan names from the plans table, falling back to the names in plans.php.
$plan_names = [1 => 'Starter', 2 => 'Growth', 3 => 'Pro', 4 => 'Agency'];
foreach (db()->query("SELECT id, name FROM plans")->fetchAll() as $plan) {
    $plan_names[(int)$plan['id']] = $plan['name'];
}

// Monitoring is included on every plan except those where it is manual.
$included = [];
foreach (PLAN_FEATURES as $plan_id => $features) {
    if ($features['monitoring'] !== 'manual') {
        $included[] = $plan_names[$plan_id];
    }
}
$included_text = implode(', ', array_slice($included, 0, -1)) . ' and ' . end($included);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Monitoring — GTM Auditor</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">

  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Monitoring</h1>
        <p class="page-subtitle">Scheduled audits and alerts</p>
      </div>
    </div>

    <!-- Body -->
    <div class="page-body">

      <div class="card">
        <div class="coming-soon">
          <div class="empty-state-icon"><?= app_icon('activity') ?></div>
          <p class="coming-soon-body">Monitoring will run your audits on a schedule and tell you as soon as tracking breaks — for example when a tag stops firing or GA4 starts receiving (not set) values.</p>
          <p class="coming-soon-plans">Included in the <?= $e($included_text) ?> plans.</p>
          <p class="alert alert-info coming-soon-status"><span class="alert-icon"><?= app_icon('info') ?></span><span>Not available yet — we're building it. Your audits already cover GTM and GA4.</span></p>
          <a href="dashboard.php" class="btn btn-secondary">Back to dashboard</a>
        </div>
      </div>

    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->

<!-- Toast container -->
<div id="toast-container"></div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
</body>
</html>
