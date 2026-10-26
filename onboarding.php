<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_auth();
$active_page = 'onboarding';

$pdo = db();
$requestedClientId = (int)($_GET['client_id'] ?? 0);
if ($requestedClientId > 0) {
    $stmt = $pdo->prepare('SELECT id, name FROM clients WHERE id = ? AND account_id = ? LIMIT 1');
    $stmt->execute([$requestedClientId, $user['account_id']]);
} else {
    $stmt = $pdo->prepare('SELECT id, name FROM clients WHERE account_id = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$user['account_id']]);
}
$client = $stmt->fetch();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tracking setup - TagVerity</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main-content">
    <header class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Set up your tracking</h1>
        <p class="page-subtitle"><?php if ($client): ?><?= htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?php endif; ?>Guided setup from site scan to draft tracking.</p>
      </div>
    </header>

    <div class="page-body onboarding-page">
      <?php if (!$client): ?>
        <section class="empty-state card">
          <div class="empty-state-icon" aria-hidden="true">+</div>
          <h2>Create a client first</h2>
          <p>TagVerity needs a client workspace before it can scan a website and save a measurement plan.</p>
          <a class="btn btn-primary mt-4" href="clients.php">Create client</a>
        </section>
      <?php else: ?>
        <div id="onboarding-app" data-client-id="<?= (int)$client['id'] ?>" data-api-base="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

          <ol class="onboarding-steps" aria-label="Setup progress">
            <li class="active" data-step="scan">
              <button type="button" data-step-target="scan" aria-current="step"><span>1</span><strong>Scan website</strong><small>Learn the site</small></button>
            </li>
            <li data-step="review">
              <button type="button" data-step-target="review" disabled><span>2</span><strong>Review findings</strong><small>Check what we found</small></button>
            </li>
            <li data-step="verify">
              <button type="button" data-step-target="verify" disabled><span>3</span><strong>Business meaning</strong><small>Name what matters</small></button>
            </li>
            <li data-step="ready">
              <button type="button" data-step-target="ready" disabled><span>4</span><strong>Ready to configure</strong><small>Choose the next action</small></button>
            </li>
          </ol>

          <div class="onboarding-panel" data-step-panel="scan">
            <section class="card onboarding-hero" id="scan-card">
            <div>
              <span class="onboarding-eyebrow">Step 1 of 4</span>
              <h2>Let TagVerity learn the website first</h2>
              <p>We inspect a small, public sample of pages for services, products, forms, search, filters, ecommerce signals, analytics tools, and consent technology. You stay in control of every decision.</p>
              <div class="onboarding-visual-points" aria-label="What the scan looks for">
                <span><?= app_icon('globe') ?> Pages</span>
                <span><?= app_icon('briefcase') ?> Services</span>
                <span><?= app_icon('activity') ?> Forms</span>
              </div>
            </div>
            <form class="onboarding-scan-form" id="scan-form">
              <label class="form-label" for="site-url">Website address</label>
              <div class="onboarding-url-row">
                <input class="form-input" id="site-url" type="url" inputmode="url" placeholder="https://example.com" required autocomplete="url">
                <button class="btn btn-primary" id="scan-button" type="submit">Scan website</button>
              </div>
              <p class="form-help">Public pages only. Private networks, logged-in areas, form values, and personal data are never scanned.</p>
            </form>
            </section>

            <section class="card onboarding-progress" id="scan-progress" hidden aria-live="polite">
              <span class="spinner" aria-hidden="true"></span>
              <div><h2>Reading the public site structure...</h2><p>This bounded first scan can take several seconds. We will show exactly what was and was not covered.</p></div>
            </section>
          </div>

          <section class="onboarding-panel" id="scan-results" data-step-panel="review" hidden>
            <div class="onboarding-section-heading">
              <div><span class="onboarding-eyebrow">Step 2 of 4</span><h2>Does this look like your website?</h2><p>Review the broad picture. You can correct the business names in the next step.</p></div>
              <button class="btn btn-secondary btn-sm" id="rescan-button" type="button">Scan again</button>
            </div>
            <div class="onboarding-coverage" id="coverage-summary"></div>
            <div class="grid grid-2 mt-4">
              <article class="card onboarding-discovery-card"><h3>Services and products</h3><div id="offer-discoveries"></div></article>
              <article class="card onboarding-discovery-card"><h3>Forms and site features</h3><div id="feature-discoveries"></div></article>
            </div>
            <article class="card onboarding-technology-card mt-4"><h3>Detected technology</h3><div class="onboarding-chips" id="technology-discoveries"></div></article>
            <p class="onboarding-coverage-note" id="coverage-note"></p>
            <div class="onboarding-wizard-actions">
              <button class="btn btn-secondary" type="button" data-step-back="scan"><?= app_icon('arrow-left') ?> Previous</button>
              <div><strong>Looks broadly correct?</strong><span>Next, confirm what the services, products and forms mean to your business.</span></div>
              <button class="btn btn-primary" type="button" data-step-next="verify">Verify business meaning <?= app_icon('arrow-right') ?></button>
            </div>
          </section>

          <section class="onboarding-panel" id="questionnaire-section" data-step-panel="verify" hidden>
            <div class="onboarding-section-heading">
              <div><span class="onboarding-eyebrow">Step 3 of 4</span><h2>Verify the business meaning</h2><p id="questionnaire-intro"></p></div>
            </div>
            <form id="questionnaire-form">
              <div class="onboarding-questions" id="questionnaire-fields"></div>
              <div class="onboarding-save-bar">
                <button class="btn btn-secondary" type="button" data-step-back="review"><?= app_icon('arrow-left') ?> Previous</button>
                <div><strong>Nothing will be published.</strong><span>Your answers create safe, editable tracking recommendations.</span></div>
                <button class="btn btn-primary" id="create-plan-button" type="submit"><span id="create-plan-label">Prepare my tracking</span> <?= app_icon('arrow-right') ?></button>
              </div>
            </form>
          </section>

          <section class="onboarding-panel" id="measurement-plan-section" data-step-panel="ready" hidden>
            <div class="onboarding-section-heading">
              <div><span class="onboarding-eyebrow">Step 4 of 4</span><h2>Your tracking foundation is ready</h2><p>TagVerity has prepared editable recommendations from the scan and your answers. Nothing is live yet.</p></div>
            </div>
            <div class="onboarding-ready-message">
              <span><?= app_icon('check-circle') ?></span>
              <div><strong>You have finished the guided setup.</strong><p>Next, review your draft tracking choices. The release process will then guide you through previewing and testing every trigger before anything can be published.</p></div>
            </div>
            <div class="onboarding-ready-actions">
              <button class="btn btn-secondary" id="edit-answers-button" type="button"><?= app_icon('arrow-left') ?> Edit business answers</button>
              <a class="btn btn-primary" href="tracking-workspace.php?client_id=<?= (int)$client['id'] ?>">Review draft tags <?= app_icon('arrow-right') ?></a>
            </div>
          </section>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<div id="toast-container" aria-live="polite"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php if ($client): ?><script src="<?= asset_url('assets/js/onboarding.js') ?>"></script><?php endif; ?>
</body>
</html>
