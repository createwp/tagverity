<?php
// ─── Clients list + create ───────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/plans.php';
$user        = require_auth();
$active_page = 'clients';

$pdo = db();

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = '';
$success = '';

// ── Handle create client POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_client') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission.';
    } else {
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            $error = 'Client name is required.';
        } else {
            // Enforce max_clients plan limit
            $existing = (int)$pdo->prepare("SELECT COUNT(*) FROM clients WHERE account_id = ?")
                ->execute([$user['account_id']]) ? $pdo->query("SELECT COUNT(*) FROM clients WHERE account_id = {$user['account_id']}")->fetchColumn() : 0;

            // Re-fetch properly
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE account_id = ?");
            $countStmt->execute([$user['account_id']]);
            $existing = (int)$countStmt->fetchColumn();

            $max = plan_limit('max_clients');
            if ($max !== PHP_INT_MAX && $existing >= $max) {
                $error = 'You have reached the client limit for your plan. Upgrade to Agency for unlimited clients.';
            } else {
                try {
                    $pdo->prepare("INSERT INTO clients (account_id, name, created_at) VALUES (?, ?, NOW())")
                        ->execute([$user['account_id'], $name]);
                    $success = 'Client "' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" created successfully.';
                } catch (\Throwable $e) {
                    error_log('[GTMAuditor][Clients] Create error: ' . $e->getMessage());
                    $error = 'Failed to create client. Please try again.';
                }
            }
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// ── Fetch all clients for this account ────────────────────────────────────────
$clients_stmt = $pdo->prepare(
    "SELECT c.*,
       (SELECT COUNT(*) FROM audit_jobs aj WHERE aj.client_id = c.id AND aj.status = 'complete') AS total_audits,
       (SELECT health_score FROM audit_jobs aj2 WHERE aj2.client_id = c.id AND aj2.status = 'complete' ORDER BY aj2.completed_at DESC LIMIT 1) AS last_score
     FROM clients c
     WHERE c.account_id = ?
     ORDER BY c.created_at DESC"
);
$clients_stmt->execute([$user['account_id']]);
$clients = $clients_stmt->fetchAll();

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
  <title>Clients — GTM Auditor</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Clients</h1>
        <p class="page-subtitle"><?= count($clients) ?> client<?= count($clients) !== 1 ? 's' : '' ?> in your account</p>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-primary" onclick="document.getElementById('create-client-modal').style.display='flex'" id="new-client-btn">+ New client</button>
      </div>
    </div>

    <div class="page-body">

      <?php if ($error): ?>
      <div class="alert alert-error" data-auto-dismiss><span class="alert-icon"><?= app_icon('alert') ?></span><span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span></div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div class="alert alert-success" data-auto-dismiss><span class="alert-icon"><?= app_icon('check-circle') ?></span><span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span></div>
      <?php endif; ?>

      <?php if (empty($clients)): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><?= app_icon('users') ?></div>
        <h3>No clients yet</h3>
        <p>Create your first client to start running GTM audits.</p>
        <button class="btn btn-primary mt-4" onclick="document.getElementById('create-client-modal').style.display='flex'">
          + Create First Client
        </button>
      </div>
      <?php else: ?>

      <div class="grid grid-3">
        <?php foreach ($clients as $c): ?>
        <div class="card" style="display:flex;flex-direction:column;gap:var(--space-3)">
          <div class="flex items-center justify-between">
            <div>
              <div style="font-weight:600;font-size:0.95rem"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="text-sm text-muted"><?= (int)$c['total_audits'] ?> audit<?= $c['total_audits'] != 1 ? 's' : '' ?> run</div>
            </div>
            <?php if ($c['last_score'] !== null): ?>
            <div style="text-align:center">
              <div style="font-size:1.4rem;font-weight:800;color:<?= scoreColor((int)$c['last_score']) ?>"><?= (int)$c['last_score'] ?></div>
              <div class="text-xs text-muted">Health</div>
            </div>
            <?php endif; ?>
          </div>
          <div class="flex gap-2" style="margin-top:auto">
            <a href="dashboard.php?client_id=<?= (int)$c['id'] ?>" class="btn btn-secondary btn-sm" style="flex:1;justify-content:center">Dashboard</a>
            <a href="domain-map.php?client_id=<?= (int)$c['id'] ?>" class="btn btn-ghost btn-sm" aria-label="View domain map" title="View domain map"><?= app_icon('globe') ?></a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Create Client Modal -->
<div id="create-client-modal" style="display:none;position:fixed;inset:0;z-index:200;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);backdrop-filter:blur(4px)">
  <div class="card" style="width:100%;max-width:420px;box-shadow:var(--shadow-lg)">
    <div class="card-header">
      <div class="card-title">New Client</div>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('create-client-modal').style.display='none'">✕</button>
    </div>
    <form method="POST" action="clients.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action"     value="create_client">
      <div class="form-group">
        <label class="form-label" for="client-name">Client name</label>
        <input class="form-input" type="text" id="client-name" name="name"
               placeholder="Acme Corporation" required autofocus>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">Create Client</button>
    </form>
  </div>
</div>

<div id="toast-container"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
// Close modal on backdrop click
document.getElementById('create-client-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>
</body>
</html>
