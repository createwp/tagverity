<?php
// ─── audit-report.php — Full audit findings view ─────────────────────────────
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/plans.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/src/AuditCoverageView.php';
$user        = require_auth();
$active_page = 'audits';

$audit_job_id = (int)($_GET['id'] ?? 0);
if (!$audit_job_id) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}
$job = assert_audit_access($audit_job_id);
$pdo = db();

// Fetch findings
$findings_stmt = $pdo->prepare(
    "SELECT * FROM audit_findings WHERE audit_job_id = ? ORDER BY
     FIELD(severity,'critical','warning','info'), category, id"
);
$findings_stmt->execute([$audit_job_id]);
$findings = $findings_stmt->fetchAll();

// Fetch client name
$client_stmt = $pdo->prepare("SELECT name FROM clients WHERE id = ? LIMIT 1");
$client_stmt->execute([$job['client_id']]);
$client_name = $client_stmt->fetchColumn() ?: 'Unknown Client';

// Group findings by category + severity counts
$grouped  = [];
$counts   = ['critical' => 0, 'warning' => 0, 'info' => 0];
$by_owner = [];
foreach ($findings as $f) {
    $grouped[$f['category']][] = $f;
    $counts[$f['severity']]    = ($counts[$f['severity']] ?? 0) + 1;
    $by_owner[$f['fix_owner']] = ($by_owner[$f['fix_owner']] ?? 0) + 1;
}

// A health score exists only for complete audits. NULL must never be shown as 0.
$status    = (string)$job['status'];
$has_score = $job['health_score'] !== null;
$score     = $has_score ? (int)$job['health_score'] : 0;
$is_unscored_final = in_array($status, ['partial', 'failed'], true);

$source_status = null;
if ($job['source_status'] !== null && $job['source_status'] !== '') {
    $decoded_status = json_decode((string)$job['source_status'], true);
    $source_status  = is_array($decoded_status) ? $decoded_status : null;
}
$coverage_rows = (new GTMAuditor\AuditCoverageView())->describe($source_status);
$coverage_badges = [
    'ok'    => ['badge-success', 'Checked'],
    'warn'  => ['badge-warning', 'Not checked'],
    'muted' => ['badge-neutral', 'Not included'],
];

function scoreColor(int $s): string {
    return $s >= 80 ? '#10b981' : ($s >= 50 ? '#f59e0b' : '#ef4444');
}
function scoreLabel(int $s): string {
    return $s >= 80 ? 'Healthy' : ($s >= 50 ? 'Needs Attention' : 'Critical Issues');
}

$category_labels = [
    'container_health' => ['tags', 'Container Health'],
    'trigger_quality'  => ['activity', 'Trigger & Variable Quality'],
    'data_quality'     => ['dashboard', 'GA4 Data Quality'],
    'implementation'   => ['globe', 'Implementation'],
];
$fix_owner_labels = [
    'gtm'       => ['tags', 'GTM'],
    'developer' => ['code', 'Developer'],
    'ga4_admin' => ['dashboard', 'GA4 Admin'],
    'hosting'   => ['server', 'Hosting / DNS'],
    'multiple'  => ['handshake', 'Multiple Teams'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Audit Report #<?= $audit_job_id ?> — GTM Auditor</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
  <style>
    .finding-card {
      background: var(--bg-surface);
      border: 1px solid var(--border);
      border-left: 3px solid;
      border-radius: var(--radius-md);
      padding: var(--space-4) var(--space-5);
      margin-bottom: var(--space-3);
      transition: all var(--duration) var(--ease);
    }
    .finding-card:hover { border-color: var(--border-strong); border-left-color: inherit; }
    .finding-card.critical { border-left-color: var(--critical); }
    .finding-card.warning  { border-left-color: var(--warning); }
    .finding-card.info     { border-left-color: var(--info); }

    .finding-title { font-size: 0.9rem; font-weight: 600; margin-bottom: var(--space-2); }
    .finding-description { font-size: 0.83rem; color: var(--text-secondary); line-height: 1.6; margin-bottom: var(--space-3); }
    .finding-rec { font-size: 0.82rem; background: var(--bg-elevated); border-radius: var(--radius-sm); padding: var(--space-3); margin-bottom: var(--space-3); }
    .finding-rec strong { color: var(--success); }
    .finding-meta { display: flex; gap: var(--space-2); flex-wrap: wrap; align-items: center; }
    .finding-affected { font-size: 0.75rem; color: var(--text-muted); font-family: 'JetBrains Mono', monospace; background: var(--bg-elevated); padding: 2px 8px; border-radius: var(--radius-full); }

    .dev-notes {
      margin-top: var(--space-3);
      padding: var(--space-3);
      background: rgba(99,102,241,0.05);
      border: 1px solid rgba(99,102,241,0.15);
      border-radius: var(--radius-sm);
      font-size: 0.8rem;
      color: var(--text-secondary);
    }
    .dev-notes strong { color: var(--brand-primary); display: block; margin-bottom: 4px; }

    .filter-bar { display:flex; gap:var(--space-2); flex-wrap:wrap; margin-bottom:var(--space-6); }
    .filter-btn { font-size:0.8rem; }
    .filter-btn.active { background: var(--brand-gradient); color:white; border-color:transparent; }
  </style>
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content">

    <!-- Header -->
    <div class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Audit report</h1>
        <p class="page-subtitle">
          <?= htmlspecialchars($client_name, ENT_QUOTES, 'UTF-8') ?> ·
          <?= $job['completed_at'] ? date('d M Y, H:i', strtotime($job['completed_at'])) : 'In progress' ?> ·
          #<?= $audit_job_id ?>
        </p>
      </div>
      <div class="page-header-actions">
        <a href="dashboard.php" class="btn btn-ghost btn-sm"><?= app_icon('arrow-left') ?> Dashboard</a>
        <?php if (has_feature('developer_notes')): ?>
        <a href="#" class="btn btn-secondary btn-sm"><?= app_icon('download') ?> Export PDF</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="page-body">

      <!-- ── Summary Row ─────────────────────────────────────────────────────── -->
      <div class="grid grid-4 mb-6">

        <!-- Health Score -->
        <div class="card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center">
          <?php if ($has_score): ?>
          <?php
            $r = 40; $circ = 2 * M_PI * $r;
            $offset = $circ - ($score / 100) * $circ;
            $colour = scoreColor($score);
          ?>
          <div class="health-score-ring">
            <svg width="100" height="100" viewBox="0 0 100 100">
              <circle cx="50" cy="50" r="<?= $r ?>" stroke="var(--bg-overlay)" stroke-width="9" fill="none"/>
              <circle cx="50" cy="50" r="<?= $r ?>"
                stroke="<?= $colour ?>" stroke-width="9" fill="none"
                stroke-dasharray="<?= round($circ,2) ?>"
                stroke-dashoffset="<?= round($offset,2) ?>"
                stroke-linecap="round"/>
            </svg>
            <span class="health-score-value" style="color:<?= $colour ?>;font-size:1.6rem"><?= $score ?></span>
          </div>
          <div style="font-size:0.8rem;font-weight:600;color:<?= $colour ?>;margin-top:4px"><?= scoreLabel($score) ?></div>
          <div class="health-score-label">Overall Health Score</div>
          <?php elseif ($is_unscored_final): ?>
          <div class="stat-value" style="color:var(--text-muted);font-size:1.6rem">—</div>
          <span class="badge badge-<?= $status === 'partial' ? 'warning' : 'critical' ?>" style="margin-top:var(--space-2)"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
          <div style="font-size:0.8rem;font-weight:600;margin-top:var(--space-2)">Score unavailable</div>
          <p class="text-xs text-muted" style="margin-top:var(--space-1);max-width:240px">Some data sources couldn't be checked, so we can't give a trustworthy score yet.</p>
          <div class="health-score-label">Overall Health Score</div>
          <?php else: ?>
          <div class="stat-value" style="color:var(--text-muted);font-size:1.6rem">—</div>
          <div style="font-size:0.8rem;font-weight:600;margin-top:var(--space-2)"><?= in_array($status, ['queued', 'running'], true) ? 'Audit still running' : 'Score unavailable' ?></div>
          <div class="health-score-label">Overall Health Score</div>
          <?php endif; ?>
        </div>

        <!-- Critical -->
        <div class="stat-card">
          <div class="stat-icon critical"><?= app_icon('alert') ?></div>
          <div>
            <div class="stat-value" style="color:var(--critical)"><?= $status === 'failed' ? '—' : $counts['critical'] ?></div>
            <div class="stat-label">Critical findings</div>
          </div>
        </div>

        <!-- Warning -->
        <div class="stat-card">
          <div class="stat-icon warning"><?= app_icon('alert') ?></div>
          <div>
            <div class="stat-value" style="color:var(--warning)"><?= $status === 'failed' ? '—' : $counts['warning'] ?></div>
            <div class="stat-label">Warnings</div>
          </div>
        </div>

        <!-- Info -->
        <div class="stat-card">
          <div class="stat-icon info"><?= app_icon('info') ?></div>
          <div>
            <div class="stat-value" style="color:var(--info)"><?= $status === 'failed' ? '—' : $counts['info'] ?></div>
            <div class="stat-label">Info / Best practices</div>
          </div>
        </div>
      </div>

      <!-- ── Data Coverage ───────────────────────────────────────────────────── -->
      <div class="card mb-6">
        <div class="card-header">
          <div>
            <div class="card-title">Data coverage</div>
            <div class="card-subtitle">Which data sources we could check for this audit</div>
          </div>
        </div>
        <?php foreach ($coverage_rows as $row):
          [$cov_badge, $cov_label] = $coverage_badges[$row['tone']] ?? $coverage_badges['warn'];
          if ($row['status'] === 'not_recorded') {
              $cov_label = 'Not recorded';
          }
        ?>
        <div style="display:flex;align-items:center;gap:var(--space-3);flex-wrap:wrap;padding:var(--space-3) 0;border-top:1px solid var(--border)">
          <span class="badge <?= $cov_badge ?>"><?= htmlspecialchars($cov_label, ENT_QUOTES, 'UTF-8') ?></span>
          <div style="font-weight:600;font-size:0.85rem;min-width:140px"><?= htmlspecialchars($row['source'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="text-sm text-muted" style="flex:1;min-width:200px"><?= htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <?php endforeach; ?>
        <?php
          $footnote = [];
          if ($source_status !== null && !empty($source_status['rule_set_version'])) {
              $footnote[] = 'Rule set ' . (string)$source_status['rule_set_version'];
          }
          if ($source_status !== null && isset($source_status['ga4_window_days'])) {
              $footnote[] = 'GA4 window ' . (int)$source_status['ga4_window_days'] . ' days';
          }
        ?>
        <?php if (!empty($footnote)): ?>
        <p class="text-xs text-muted" style="margin-top:var(--space-3);margin-bottom:0"><?= htmlspecialchars(implode(' · ', $footnote), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
      </div>

      <!-- ── Fix Owner Breakdown ─────────────────────────────────────────────── -->
      <?php if (!empty($by_owner)): ?>
      <div class="card mb-6">
        <div class="card-header"><div class="card-title">Who needs to act?</div></div>
        <div style="display:flex;gap:var(--space-3);flex-wrap:wrap">
          <?php foreach ($by_owner as $owner => $cnt):
            [$icon, $label] = $fix_owner_labels[$owner] ?? ['wrench', ucfirst($owner)];
            $badgeClass = 'badge-' . str_replace('_', '-', $owner);
          ?>
          <div style="display:flex;align-items:center;gap:var(--space-3);background:var(--bg-elevated);padding:var(--space-3) var(--space-4);border-radius:var(--radius-md);flex:1;min-width:140px">
            <span class="feature-list-icon"><?= app_icon($icon) ?></span>
            <div>
              <div style="font-weight:700;font-size:1.1rem"><?= $cnt ?></div>
              <div class="text-xs text-muted"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($is_unscored_final && !empty($findings)): ?>
      <div class="alert alert-warning mb-4">
        <span class="alert-icon"><?= app_icon('alert') ?></span>
        <span>These findings come from the data we could check. Sources marked "Not checked" above may hide other issues.</span>
      </div>
      <?php endif; ?>

      <!-- ── Filter bar ─────────────────────────────────────────────────────── -->
      <?php if (!empty($findings)): ?>
      <div class="filter-bar">
        <button class="btn btn-secondary filter-btn active" data-filter="all">All (<?= count($findings) ?>)</button>
        <?php if ($counts['critical']): ?><button class="btn btn-secondary filter-btn" data-filter="critical" style="border-color:var(--critical-border)"><span class="severity-dot critical"></span>Critical (<?= $counts['critical'] ?>)</button><?php endif; ?>
        <?php if ($counts['warning']): ?><button class="btn btn-secondary filter-btn" data-filter="warning" style="border-color:var(--warning-border)"><span class="severity-dot warning"></span>Warning (<?= $counts['warning'] ?>)</button><?php endif; ?>
        <?php if ($counts['info']): ?><button class="btn btn-secondary filter-btn" data-filter="info" style="border-color:var(--info-border)"><span class="severity-dot info"></span>Info (<?= $counts['info'] ?>)</button><?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- ── Findings by Category ───────────────────────────────────────────── -->
      <?php if (empty($findings) && $status === 'complete'): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><?= app_icon('check-circle') ?></div>
        <h3>No findings — looking great!</h3>
        <p>This audit found no issues with your GTM or GA4 implementation.</p>
      </div>
      <?php elseif (empty($findings) && $status === 'partial'): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><?= app_icon('alert') ?></div>
        <h3>No findings in the data we could check</h3>
        <p>Some data sources couldn't be checked, so this isn't a clean result. See Data coverage above.</p>
      </div>
      <?php elseif (empty($findings) && $status === 'failed'): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><?= app_icon('alert') ?></div>
        <h3>No findings to report</h3>
        <p>We couldn't check the data needed for this audit, so it can't tell you whether anything is wrong.</p>
      </div>
      <?php elseif (empty($findings)): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><?= app_icon('dashboard') ?></div>
        <h3>Audit still running</h3>
        <p>Results appear here when the audit finishes.</p>
      </div>
      <?php else: ?>

      <?php foreach ($category_labels as $cat_key => [$cat_icon, $cat_label]): ?>
      <?php if (empty($grouped[$cat_key])) continue; ?>

      <div class="mb-6">
        <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:var(--space-3);display:flex;align-items:center;gap:var(--space-2);color:var(--text-secondary)">
          <span class="section-heading-icon"><?= app_icon($cat_icon) ?></span><?= htmlspecialchars($cat_label, ENT_QUOTES, 'UTF-8') ?>
          <span class="badge badge-neutral"><?= count($grouped[$cat_key]) ?></span>
        </h2>

        <?php foreach ($grouped[$cat_key] as $f):
          $sev = htmlspecialchars($f['severity'], ENT_QUOTES, 'UTF-8');
          $owner = htmlspecialchars($f['fix_owner'], ENT_QUOTES, 'UTF-8');
          [$oIcon, $oLabel] = $fix_owner_labels[$f['fix_owner']] ?? ['wrench', ucfirst($f['fix_owner'])];
        ?>
        <div class="finding-card <?= $sev ?>" data-severity="<?= $sev ?>">
          <div class="flex items-center gap-2 mb-2" style="flex-wrap:wrap">
            <span class="badge badge-<?= $sev ?>"><?= strtoupper($sev) ?></span>
            <span class="badge badge-<?= str_replace('_', '-', $owner) ?>"><?= app_icon($oIcon) ?> <?= htmlspecialchars($oLabel, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="finding-title"><?= htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="finding-description"><?= htmlspecialchars($f['description'], ENT_QUOTES, 'UTF-8') ?></div>

          <div class="finding-rec">
            <strong><?= app_icon('check') ?> Recommendation:</strong>
            <?= htmlspecialchars($f['recommendation'], ENT_QUOTES, 'UTF-8') ?>
          </div>

          <?php if (!empty($f['affected_item'])): ?>
          <div class="finding-meta">
            <span class="text-muted text-xs">Affected:</span>
            <span class="finding-affected"><?= htmlspecialchars($f['affected_item'], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <?php endif; ?>

          <?php if (!empty($f['developer_notes']) && has_feature('developer_notes')): ?>
          <div class="dev-notes">
            <strong><?= app_icon('code') ?> Developer Notes</strong>
            <?= htmlspecialchars($f['developer_notes'], ENT_QUOTES, 'UTF-8') ?>
          </div>
          <?php elseif (!empty($f['developer_notes']) && !has_feature('developer_notes')): ?>
          <div class="dev-notes" style="opacity:0.6">
            <strong><?= app_icon('code') ?> Developer Notes</strong>
            <a href="billing.php" style="color:var(--brand-primary)">Upgrade to Pro to see technical fix instructions</a>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

      <?php endif; ?>
    </div>
  </div>
</div>

<div id="toast-container"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
// ── Severity filter ──────────────────────────────────────────────────────────
document.querySelectorAll('.filter-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const filter = btn.dataset.filter;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.finding-card').forEach(card => {
      const show = filter === 'all' || card.dataset.severity === filter;
      card.style.display = show ? '' : 'none';
    });
  });
});
</script>
</body>
</html>
