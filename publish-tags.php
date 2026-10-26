<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_auth();
$active_page = 'publish-tags';

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
  <title>Review and publish - TagVerity</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">
    <header class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Review and publish</h1>
        <p class="page-subtitle"><?php if ($client): ?><?= htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?php endif; ?>Test and approve changes before publishing.</p>
      </div>
      <?php if ($client): ?><div class="page-header-actions"><a class="btn btn-secondary" href="tracking-workspace.php?client_id=<?= (int)$client['id'] ?>"><?= app_icon('arrow-left') ?> Tags &amp; triggers</a></div><?php endif; ?>
    </header>

    <div class="page-body publish-page">
      <?php if (!$client): ?>
        <section class="empty-state card"><h2>No website is available</h2><p>Add a client website before creating a tag release.</p></section>
      <?php else: ?>
        <div id="publish-app" data-client-id="<?= (int)$client['id'] ?>" data-api-base="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

          <ol class="release-steps" aria-label="Publishing workflow">
            <li class="current"><span>1</span><strong>Review changes</strong><small>New and modified tags only</small></li>
            <li><span>2</span><strong>Preview &amp; test</strong><small>Verify every trigger in the website</small></li>
            <li><span>3</span><strong>Approve</strong><small>Lock the tested release version</small></li>
            <li><span>4</span><strong>Publish</strong><small>Deploy the approved version</small></li>
          </ol>

          <div class="release-notice mt-4">
            <strong>Approval does not make tags live.</strong>
            <span>TagVerity only labels a release “published” after a deployment adapter confirms the external platform accepted it.</span>
          </div>

          <div class="publish-layout mt-6">
            <section class="card publish-drafts-card">
              <div class="card-header">
                <div><div class="card-title">Unpublished changes</div><p class="text-sm text-muted" id="release-change-copy">Only tracking that is new or changed since the last approved release appears here.</p></div>
                <label class="release-select-all"><input id="select-all-mappings" type="checkbox" checked> Select all changes</label>
              </div>
              <div class="release-change-summary" id="release-change-summary" aria-live="polite"></div>
              <div id="release-mapping-list" class="release-mapping-list" aria-live="polite"></div>
              <div class="release-preview-gate">
                <span class="release-preview-icon"><?= app_icon('play') ?></span>
                <div><strong>Preview and testing are required before publication</strong><p>Inspect the selected elements in the safe preview first. Live trigger behaviour must then pass the release verification checks before publication is unlocked.</p></div>
                <a class="btn btn-secondary" href="element-mapper.php?client_id=<?= (int)$client['id'] ?>&amp;return=release">Open visual preview</a>
              </div>
              <div class="release-create-panel">
                <label class="form-label" for="release-name">Release name</label>
                <input class="form-input" id="release-name" type="text" maxlength="150" placeholder="For example: Consultation form tracking">
                <div class="release-actions">
                  <button class="btn btn-secondary" id="save-release-draft" type="button">Save release draft</button>
                  <button class="btn btn-primary" id="approve-release" type="button">Approve release</button>
                </div>
              </div>
            </section>

            <aside class="card publish-readiness-card">
              <div class="card-header"><div><div class="card-title">Publication readiness</div><p class="text-sm text-muted">Clear instructions for every item that prevents a safe deployment.</p></div></div>
              <div id="readiness-summary" class="readiness-summary"></div>
              <div id="readiness-list" class="readiness-list" aria-live="polite"></div>
            </aside>
          </div>

          <section class="mt-8">
            <div class="onboarding-section-heading">
              <div><span class="onboarding-eyebrow">Version history</span><h2>Tag releases</h2><p>Each release keeps the exact mapping and platform templates that were approved.</p></div>
            </div>
            <div id="release-history" class="release-history" aria-live="polite"></div>
          </section>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<div id="toast-container" aria-live="polite"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php if ($client): ?><script src="<?= asset_url('assets/js/publish-tags.js') ?>"></script><?php endif; ?>
</body>
</html>
