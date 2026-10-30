<?php
// ─── billing.php — Plan picker + Stripe Checkout ─────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/src/AuditCredits.php';
$user        = require_auth();
$active_page = 'billing';

$pdo = db();

// Fetch current plan
$plan_stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
$plan_stmt->execute([$user['plan_id'] ?? 1]);
$current_plan = $plan_stmt->fetch();

// Fetch all plans for the picker
$all_plans = $pdo->query("SELECT * FROM plans ORDER BY id ASC")->fetchAll();

// Credits used this month, counted with the same rules as the audit trigger
$credits_used  = GTMAuditor\AuditCredits::usedThisMonthForAccount($pdo, (int)$user['account_id']);
$credits_limit = (int)($current_plan['audit_credits_monthly'] ?? 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Billing & Plans — GTM Auditor</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
  <style>
    .plan-card {
      background: var(--bg-surface);
      border: 2px solid var(--border);
      border-radius: var(--radius-lg);
      padding: var(--space-6);
      display: flex;
      flex-direction: column;
      gap: var(--space-4);
      transition: all var(--duration) var(--ease);
      position: relative;
    }
    .plan-card:hover { border-color: var(--border-strong); transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .plan-card.current { border-color: var(--brand-primary); box-shadow: var(--shadow-glow); }
    .plan-card.popular::before {
      content: 'Most Popular';
      position: absolute;
      top: -12px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--brand-gradient);
      color: white;
      font-size: 0.7rem;
      font-weight: 700;
      padding: 3px 12px;
      border-radius: var(--radius-full);
      white-space: nowrap;
    }
    .plan-name  { font-size: 1.1rem; font-weight: 800; letter-spacing: -0.02em; }
    .plan-price { font-size: 2rem; font-weight: 800; letter-spacing: -0.04em; }
    .plan-price span { font-size: 0.9rem; font-weight: 400; color: var(--text-muted); }
    .plan-features { list-style: none; display: flex; flex-direction: column; gap: var(--space-2); flex: 1; }
    .plan-features li { font-size: 0.83rem; display: flex; gap: var(--space-2); }
    .plan-features li.off { opacity: 0.4; text-decoration: line-through; }
    .plan-features li::before { content: '✓'; color: var(--success); font-weight: 700; flex-shrink: 0; }
    .plan-features li.off::before { content: '✗'; color: var(--text-muted); }
  </style>
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Billing &amp; plans</h1>
        <p class="page-subtitle">
          Current plan: <strong><?= htmlspecialchars($current_plan['name'], ENT_QUOTES, 'UTF-8') ?></strong> ·
          <?= $credits_used ?> / <?= $credits_limit < 0 ? '∞' : $credits_limit ?> audits used this month
        </p>
      </div>
    </div>

    <div class="page-body">

      <?php if (!STRIPE_SECRET_KEY || STRIPE_SECRET_KEY === 'sk_test_REPLACE_ME'): ?>
      <div class="alert alert-warning mb-6">
        <span class="alert-icon"><?= app_icon('alert') ?></span><span>Stripe is not configured yet. Set <code>STRIPE_SECRET_KEY</code>, <code>STRIPE_PUBLISHABLE_KEY</code>, and <code>STRIPE_WEBHOOK_SECRET</code> in your <code>.env</code> file to enable billing.</span>
      </div>
      <?php endif; ?>

      <!-- Current usage card -->
      <div class="card mb-6">
        <div class="card-header">
          <div class="card-title">This month's usage</div>
        </div>
        <div class="flex items-center gap-6">
          <div>
            <div class="stat-value"><?= $credits_used ?></div>
            <div class="stat-label">Audits run</div>
          </div>
          <div style="flex:1;background:var(--bg-elevated);border-radius:var(--radius-full);height:8px;overflow:hidden">
            <?php
              $pct = $credits_limit > 0 ? min(100, ($credits_used / $credits_limit) * 100) : 0;
              $barColour = $pct >= 90 ? 'var(--critical)' : ($pct >= 70 ? 'var(--warning)' : 'var(--success)');
            ?>
            <div style="height:100%;width:<?= round($pct, 1) ?>%;background:<?= $barColour ?>;border-radius:var(--radius-full);transition:width 1s ease"></div>
          </div>
          <div>
            <div class="stat-value"><?= $credits_limit < 0 ? '∞' : $credits_limit ?></div>
            <div class="stat-label">Monthly limit</div>
          </div>
        </div>
      </div>

      <!-- Plan cards -->
      <div class="grid grid-4 mb-6">
        <?php
        $plan_features = [
          1 => ['1 website', '5 audits/month', 'GTM API checks', 'GA4 Reporting API', 'Manual audits only', false, false, false, false],
          2 => ['3 websites', '20 audits/month', 'GTM API checks', 'GA4 Reporting API', 'Weekly monitoring', 'Headless crawl', 'Email alerts', false, false],
          3 => ['10 websites', '100 audits/month', 'GTM API checks', 'GA4 Reporting API', 'Daily monitoring', 'Headless crawl', 'BigQuery raw data', 'Developer notes', 'PDF reports'],
          4 => ['Unlimited websites', 'Unlimited audits', 'GTM API checks', 'GA4 Reporting API', 'Hourly monitoring', 'Headless crawl', 'BigQuery raw data', 'Developer notes', 'White-label PDFs', 'Client workspaces', 'Slack alerts'],
        ];
        foreach ($all_plans as $plan):
          $isCurrent = (int)$plan['id'] === (int)($user['plan_id'] ?? 1);
          $isPopular  = (int)$plan['id'] === 3;
          $features   = $plan_features[$plan['id']] ?? [];
        ?>
        <div class="plan-card <?= $isCurrent ? 'current' : '' ?> <?= $isPopular ? 'popular' : '' ?>">
          <div>
            <div class="plan-name"><?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="plan-price">
              $<?= number_format($plan['price_monthly'], 0) ?><span>/mo</span>
            </div>
          </div>

          <ul class="plan-features">
            <?php foreach ($features as $feat):
              $isOff = $feat === false;
            ?>
            <?php if (!$isOff): ?>
            <li><?= htmlspecialchars($feat, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endif; ?>
            <?php endforeach; ?>
          </ul>

          <?php if ($isCurrent): ?>
          <button class="btn btn-secondary" disabled style="opacity:0.7">Current Plan</button>
          <?php elseif (!STRIPE_SECRET_KEY || STRIPE_SECRET_KEY === 'sk_test_REPLACE_ME'): ?>
          <button class="btn btn-secondary" disabled>Stripe not configured</button>
          <?php else: ?>
          <form method="POST" action="api/create-checkout.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
            <button type="submit" class="btn btn-primary" style="width:100%">
              <?= (int)$plan['id'] > (int)($user['plan_id'] ?? 1) ? 'Upgrade' : 'Downgrade' ?> to <?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?>
            </button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- One-time audit -->
      <div class="card" style="border-style:dashed;display:flex;align-items:center;gap:var(--space-6);flex-wrap:wrap">
        <div style="flex:1">
          <div style="font-size:1rem;font-weight:700;margin-bottom:4px">One-time Audit — $79</div>
          <div class="text-sm text-muted">Pro-level single audit, no subscription required. Perfect for a one-off health check.</div>
        </div>
        <?php if (STRIPE_SECRET_KEY && STRIPE_SECRET_KEY !== 'sk_test_REPLACE_ME'): ?>
        <form method="POST" action="api/create-checkout.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="plan_id" value="one_time">
          <button type="submit" class="btn btn-primary">Buy One-Time Audit — $79</button>
        </form>
        <?php else: ?>
        <button class="btn btn-secondary" disabled>Stripe not configured</button>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
<div id="toast-container"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
</body>
</html>
