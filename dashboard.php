<?php
// ─── Dashboard ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/plans.php';
$user        = require_auth();
$active_page = 'dashboard';

$pdo = db();

// ── Fetch the first client for this account ────────────────────────────────────
// (Agencies will have a client picker in a future iteration)
$client = $pdo->prepare(
    "SELECT * FROM clients WHERE account_id = ? ORDER BY id ASC LIMIT 1"
);
$client->execute([$user['account_id']]);
$client = $client->fetch();

$client_id = $client ? (int)$client['id'] : 0;

// ── Stats ──────────────────────────────────────────────────────────────────────
$total_audits = 0;
$last_score   = null;
$critical_count = $warning_count = $info_count = 0;
$recent_audits  = [];

if ($client_id) {
    // Total audits run for this client
    $s = $pdo->prepare("SELECT COUNT(*) FROM audit_jobs WHERE client_id = ? AND status = 'complete'");
    $s->execute([$client_id]);
    $total_audits = (int)$s->fetchColumn();

    // Last health score
    $s = $pdo->prepare("SELECT health_score, completed_at FROM audit_jobs WHERE client_id = ? AND status = 'complete' ORDER BY completed_at DESC LIMIT 1");
    $s->execute([$client_id]);
    $last_job = $s->fetch();
    $last_score = $last_job ? (int)$last_job['health_score'] : null;

    // Finding counts from the most recent complete job
    if ($last_job) {
        $s = $pdo->prepare(
            "SELECT severity, COUNT(*) as cnt
             FROM audit_findings af
             JOIN audit_jobs aj ON aj.id = af.audit_job_id
             WHERE aj.client_id = ? AND aj.status = 'complete'
             AND aj.completed_at = ?
             GROUP BY severity"
        );
        $s->execute([$client_id, $last_job['completed_at']]);
        foreach ($s->fetchAll() as $row) {
            if ($row['severity'] === 'critical') $critical_count = (int)$row['cnt'];
            if ($row['severity'] === 'warning')  $warning_count  = (int)$row['cnt'];
            if ($row['severity'] === 'info')     $info_count     = (int)$row['cnt'];
        }
    }

    // Recent audit history (last 10)
    $s = $pdo->prepare(
        "SELECT id, status, triggered_by, health_score, credits_used, started_at, completed_at
         FROM audit_jobs WHERE client_id = ? ORDER BY created_at DESC LIMIT 10"
    );
    $s->execute([$client_id]);
    $recent_audits = $s->fetchAll();
}

// ── Plan info ──────────────────────────────────────────────────────────────────
$credits_used  = 0;
$credits_limit = plan_limit('audit_credits');
if ($client_id) {
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_jobs
         WHERE client_id = ? AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
    );
    $s->execute([$client_id]);
    $credits_used = (int)$s->fetchColumn();
}

// ── CSRF for the Trigger Audit button ─────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Health score colour ────────────────────────────────────────────────────────
function scoreColor(int $score): string {
    if ($score >= 80) return '#10b981';
    if ($score >= 50) return '#f59e0b';
    return '#ef4444';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — GTM Auditor</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">

  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">
          <?= $client ? htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8') : 'No client configured yet' ?>
        </p>
      </div>
      <?php if ($client_id): ?>
      <form method="POST" action="api/trigger-audit.php" id="trigger-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="client_id"  value="<?= $client_id ?>">
        <button type="submit" class="btn btn-primary" id="trigger-btn">
          ▶ Run Audit
        </button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Body -->
    <div class="page-body">

      <?php if (isset($_GET['welcome'])): ?>
      <div class="alert alert-success mb-4">
        🎉 Welcome to GTM Auditor! Connect your Google account to run your first audit.
        <a href="connections.php" class="btn btn-sm btn-secondary" style="margin-left:auto">Connect Google →</a>
      </div>
      <?php endif; ?>

      <?php if (!$client_id): ?>
      <!-- No client yet -->
      <div class="empty-state">
        <div class="empty-state-icon">🏗️</div>
        <h3>Set up your first client</h3>
        <p>Create a client, connect your Google account, and run your first GTM audit in minutes.</p>
        <a href="connections.php" class="btn btn-primary mt-4">Connect Google Account</a>
      </div>

      <?php else: ?>

      <!-- ── Stat Cards ─────────────────────────────────────────────────────── -->
      <div class="grid grid-4 mb-6">

        <!-- Health Score -->
        <div class="stat-card" style="align-items:center; justify-content:center; flex-direction:column; text-align:center;">
          <?php if ($last_score !== null): ?>
          <div class="health-score-ring">
            <?php
              $r = 36; $circ = 2 * M_PI * $r;
              $offset = $circ - ($last_score / 100) * $circ;
              $colour = scoreColor($last_score);
            ?>
            <svg width="90" height="90" viewBox="0 0 90 90">
              <circle cx="45" cy="45" r="<?= $r ?>" stroke="var(--bg-overlay)" stroke-width="8" fill="none"/>
              <circle cx="45" cy="45" r="<?= $r ?>"
                stroke="<?= $colour ?>" stroke-width="8" fill="none"
                stroke-dasharray="<?= round($circ,2) ?>"
                stroke-dashoffset="<?= round($offset,2) ?>"
                stroke-linecap="round"
                style="transition: stroke-dashoffset 1s ease"/>
            </svg>
            <span class="health-score-value" style="color:<?= $colour ?>"><?= $last_score ?></span>
          </div>
          <div class="health-score-label">Health Score</div>
          <?php else: ?>
          <div class="stat-icon brand" style="margin:0 auto">📊</div>
          <div class="stat-value" style="margin-top:var(--space-2)">—</div>
          <div class="stat-label">No audits yet</div>
          <?php endif; ?>
        </div>

        <!-- Critical findings -->
        <div class="stat-card">
          <div class="stat-icon critical">🔴</div>
          <div>
            <div class="stat-value" style="color:var(--critical)"><?= $critical_count ?></div>
            <div class="stat-label">Critical findings</div>
          </div>
        </div>

        <!-- Warnings -->
        <div class="stat-card">
          <div class="stat-icon warning">🟡</div>
          <div>
            <div class="stat-value" style="color:var(--warning)"><?= $warning_count ?></div>
            <div class="stat-label">Warnings</div>
          </div>
        </div>

        <!-- Audit credits -->
        <div class="stat-card">
          <div class="stat-icon brand">⚡</div>
          <div>
            <div class="stat-value">
              <?= $credits_used ?><?= $credits_limit === PHP_INT_MAX ? '' : '/' . $credits_limit ?>
            </div>
            <div class="stat-label">Audits this month</div>
          </div>
        </div>

      </div>

      <!-- ── Recent Audits Table ────────────────────────────────────────────── -->
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title">Audit History</div>
            <div class="card-subtitle">Recent audit runs for this client</div>
          </div>
          <a href="audits.php?client_id=<?= $client_id ?>" class="btn btn-ghost btn-sm">View all →</a>
        </div>

        <?php if (empty($recent_audits)): ?>
        <div class="empty-state" style="padding:var(--space-8)">
          <div class="empty-state-icon">🔎</div>
          <h3>No audits yet</h3>
          <p>Click "Run Audit" above to start your first GTM audit.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap" style="border:none;border-radius:0">
          <table>
            <thead>
              <tr>
                <th>Status</th>
                <th>Health Score</th>
                <th>Triggered by</th>
                <th>Started</th>
                <th>Duration</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent_audits as $job): ?>
              <?php
                $dur = '';
                if ($job['started_at'] && $job['completed_at']) {
                    $secs = strtotime($job['completed_at']) - strtotime($job['started_at']);
                    $dur  = $secs < 60 ? "{$secs}s" : round($secs/60, 1) . 'm';
                }
                $score_colour = $job['health_score'] !== null ? scoreColor((int)$job['health_score']) : 'var(--text-muted)';
              ?>
              <tr>
                <td>
                  <span class="flex items-center gap-2">
                    <span class="status-dot <?= htmlspecialchars($job['status'], ENT_QUOTES, 'UTF-8') ?>"></span>
                    <?= htmlspecialchars(ucfirst($job['status']), ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td>
                  <?php if ($job['health_score'] !== null): ?>
                  <span style="font-weight:700;color:<?= $score_colour ?>"><?= (int)$job['health_score'] ?></span>
                  <?php else: ?>
                  <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td><span class="badge badge-neutral"><?= htmlspecialchars($job['triggered_by'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td class="td-mono"><?= $job['started_at'] ? date('d M H:i', strtotime($job['started_at'])) : '—' ?></td>
                <td class="td-mono"><?= $dur ?: '—' ?></td>
                <td>
                  <?php if ($job['status'] === 'complete'): ?>
                  <a href="audit-report.php?id=<?= (int)$job['id'] ?>" class="btn btn-sm btn-secondary">View Report</a>
                  <?php elseif ($job['status'] === 'failed'): ?>
                  <span class="badge badge-critical">Failed</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php endif; ?>
    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->

<!-- Toast container -->
<div id="toast-container"></div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
// Handle the Trigger Audit form via AJAX
const triggerForm = document.getElementById('trigger-form');
if (triggerForm) {
  triggerForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('trigger-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Queuing…';
    try {
      const res  = await fetch('api/trigger-audit.php', {
        method: 'POST',
        body: new FormData(triggerForm)
      });
      const data = await res.json();
      if (data.success) {
        showToast('Audit queued — results ready in ~5 minutes.', 'success');
        setTimeout(() => location.reload(), 2000);
      } else {
        showToast(data.message || 'Failed to queue audit.', 'error');
        btn.disabled = false;
        btn.innerHTML = '▶ Run Audit';
      }
    } catch {
      showToast('Network error. Please try again.', 'error');
      btn.disabled = false;
      btn.innerHTML = '▶ Run Audit';
    }
  });
}
</script>
</body>
</html>
