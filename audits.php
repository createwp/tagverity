<?php
// ─── Audit history (account-wide) ─────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/src/AuditHistory.php';
$user        = require_auth();
$active_page = 'audits';

$pdo = db();
$account_id = (int)$user['account_id'];
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// ── Client picker options (only this account's clients) ───────────────────────
$s = $pdo->prepare("SELECT id, name FROM clients WHERE account_id = ? ORDER BY name ASC");
$s->execute([$account_id]);
$clients = $s->fetchAll();
$client_ids = array_map(static fn(array $c): int => (int)$c['id'], $clients);

// ── Filters: invalid GET values are ignored, not errors ───────────────────────
$client_id = null;
if (isset($_GET['client_id']) && ctype_digit((string)$_GET['client_id']) && in_array((int)$_GET['client_id'], $client_ids, true)) {
    $client_id = (int)$_GET['client_id'];
}
$status = null;
if (isset($_GET['status']) && in_array($_GET['status'], \GTMAuditor\AuditHistory::STATUSES, true)) {
    $status = (string)$_GET['status'];
}
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$history     = \GTMAuditor\AuditHistory::page($pdo, $account_id, $client_id, $status, $page);
$rows        = $history['rows'];
$has_filters = $client_id !== null || $status !== null;

// Builds a page URL that keeps the current filters. Empty values are left out.
function audits_url(array $params): string {
    $query = array_filter($params, static fn($v) => $v !== null && $v !== '');
    return 'audits.php' . ($query ? '?' . http_build_query($query) : '');
}
$filter_params = ['client_id' => $client_id, 'status' => $status];

// Health score colour, as on the dashboard.
function audit_score_colour(int $score): string {
    if ($score >= 80) return '#10b981';
    if ($score >= 50) return '#f59e0b';
    return '#ef4444';
}

$status_labels = [
    'queued'   => 'Queued',
    'running'  => 'Running',
    'complete' => 'Complete',
    'partial'  => 'Partial',
    'failed'   => 'Failed',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Audit history — GTM Auditor</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">

  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Audit history</h1>
        <p class="page-subtitle">Every audit run across your clients</p>
      </div>
    </div>

    <!-- Body -->
    <div class="page-body">

      <div class="card">
        <form method="get" action="audits.php" class="audits-filters">
          <div class="audits-filter">
            <label for="audits-client">Client</label>
            <select class="form-select" id="audits-client" name="client_id">
              <option value="">All clients</option>
              <?php foreach ($clients as $option): ?>
              <option value="<?= (int)$option['id'] ?>"<?= $client_id === (int)$option['id'] ? ' selected' : '' ?>><?= $e($option['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="audits-filter">
            <label for="audits-status">Status</label>
            <select class="form-select" id="audits-status" name="status">
              <option value="">All statuses</option>
              <?php foreach ($status_labels as $value => $label): ?>
              <option value="<?= $e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-secondary audits-apply">Apply</button>
        </form>

        <?php if (empty($rows)): ?>
        <?php if (!$has_filters): ?>
        <div class="empty-state" style="padding:var(--space-8)">
          <div class="empty-state-icon"><?= app_icon('search') ?></div>
          <h3>No audits yet.</h3>
          <p>Run your first audit from the <a href="dashboard.php">dashboard</a>.</p>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:var(--space-8)">
          <div class="empty-state-icon"><?= app_icon('search') ?></div>
          <h3>No audits match these filters</h3>
          <p>Try a different client or status, or <a href="audits.php">show all audits</a>.</p>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="table-wrap" style="border:none;border-radius:0">
          <table>
            <thead>
              <tr>
                <th>Status</th>
                <th>Client</th>
                <th>Health Score</th>
                <th>Triggered by</th>
                <th>Started</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $job): ?>
              <tr>
                <td>
                  <span class="flex items-center gap-2">
                    <span class="status-dot <?= $e($job['status']) ?>"></span>
                    <?= $e($status_labels[$job['status']] ?? ucfirst($job['status'])) ?>
                  </span>
                </td>
                <td><?= $e($job['client_name']) ?></td>
                <td>
                  <?php if ($job['health_score'] !== null): ?>
                  <span style="font-weight:700;color:<?= audit_score_colour($job['health_score']) ?>"><?= $job['health_score'] ?></span>
                  <?php else: ?>
                  <span class="text-muted">—</span>
                  <?php if ($job['status'] === 'partial'): ?>
                  <span class="badge badge-warning" style="margin-left:var(--space-1)">Partial</span>
                  <?php elseif ($job['status'] === 'failed'): ?>
                  <span class="badge badge-critical" style="margin-left:var(--space-1)">Failed</span>
                  <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td><span class="badge badge-neutral"><?= $e($job['triggered_by']) ?></span></td>
                <td class="td-mono"><?= $job['created_at'] ? $e(date('d M H:i', strtotime($job['created_at']))) : '—' ?></td>
                <td>
                  <?php if ($job['status'] === 'complete' || $job['status'] === 'partial'): ?>
                  <a href="audit-report.php?id=<?= (int)$job['id'] ?>" class="btn btn-sm btn-secondary">View Report</a>
                  <?php elseif ($job['status'] === 'failed'): ?>
                  <a href="audit-report.php?id=<?= (int)$job['id'] ?>" class="btn btn-sm btn-secondary">View details</a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <nav class="audits-pagination" aria-label="Audit history pages">
          <?php
            $prev_page = $history['page'] - 1;
            $next_page = $history['page'] + 1;
            $prev_url  = audits_url($filter_params + ['page' => $prev_page > 1 ? $prev_page : null]);
            $next_url  = audits_url($filter_params + ['page' => $next_page]);
          ?>
          <?php if ($history['page'] > 1): ?>
          <a href="<?= $e($prev_url) ?>" class="btn btn-secondary btn-sm"><?= app_icon('arrow-left') ?> Previous</a>
          <?php else: ?>
          <span class="btn btn-secondary btn-sm" aria-disabled="true"><?= app_icon('arrow-left') ?> Previous</span>
          <?php endif; ?>
          <span class="audits-page-count">Page <?= (int)$history['page'] ?> of <?= (int)$history['pages'] ?></span>
          <?php if ($history['page'] < $history['pages']): ?>
          <a href="<?= $e($next_url) ?>" class="btn btn-secondary btn-sm">Next <?= app_icon('arrow-right') ?></a>
          <?php else: ?>
          <span class="btn btn-secondary btn-sm" aria-disabled="true">Next <?= app_icon('arrow-right') ?></span>
          <?php endif; ?>
        </nav>
        <?php endif; ?>
      </div>

    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->

<!-- Toast container -->
<div id="toast-container"></div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
</body>
</html>
