<?php
// ─── BigQuery checks (not available yet) ───────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/plans.php';
$user        = require_auth();
$active_page = 'bigquery';

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// Plan names from the plans table, falling back to the names in plans.php.
$plan_names = [1 => 'Starter', 2 => 'Growth', 3 => 'Pro', 4 => 'Agency'];
foreach (db()->query("SELECT id, name FROM plans")->fetchAll() as $plan) {
    $plan_names[(int)$plan['id']] = $plan['name'];
}

// BigQuery checks are included on plans where the BigQuery export is available.
$included = [];
foreach (PLAN_FEATURES as $plan_id => $features) {
    if ($features['bigquery'] === true) {
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
  <title>BigQuery checks — GTM Auditor</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">

  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">BigQuery checks</h1>
        <p class="page-subtitle">Deeper checks on your GA4 BigQuery export</p>
      </div>
    </div>

    <!-- Body -->
    <div class="page-body">

      <div class="card">
        <div class="empty-state" style="padding:var(--space-8)">
          <div class="empty-state-icon"><?= app_icon('database') ?></div>
          <p>BigQuery checks will use your GA4 BigQuery export to look at every event, not just reporting summaries, so we can find gaps that sampled reports hide.</p>
          <p class="text-muted">Included in the <?= $e($included_text) ?> plans.</p>
          <p><span class="badge badge-neutral">Not available yet — we're building it. Your audits already cover GTM and GA4.</span></p>
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
