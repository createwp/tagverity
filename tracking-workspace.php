<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_auth();
$active_page = 'tracking-workspace';

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
  <title>Tags and triggers - TagVerity</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">
    <header class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Tags &amp; triggers</h1>
        <p class="page-subtitle"><?php if ($client): ?><?= htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?php endif; ?>Configure events, triggers and publishing.</p>
      </div>
      <?php if ($client): ?>
      <div class="page-header-actions">
        <a class="btn btn-secondary" href="destination-connections.php?client_id=<?= (int)$client['id'] ?>">Destinations</a>
        <a class="btn btn-secondary" href="element-mapper.php?client_id=<?= (int)$client['id'] ?>&amp;return=workspace">Map an element</a>
        <a class="btn btn-primary" href="publish-tags.php?client_id=<?= (int)$client['id'] ?>">Review changes</a>
      </div>
      <?php endif; ?>
    </header>

    <div class="page-body tracking-workspace-page">
      <?php if (!$client): ?>
        <section class="empty-state card"><h2>No website is available</h2><p>Add a client website before configuring tracking.</p></section>
      <?php else: ?>
        <div id="tracking-workspace-app" data-client-id="<?= (int)$client['id'] ?>" data-api-base="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

          <template id="tracking-icon-plus"><?= app_icon('plus') ?></template>
          <template id="tracking-icon-minus"><?= app_icon('minus') ?></template>
          <template id="tracking-icon-x"><?= app_icon('x') ?></template>
          <template id="tracking-icon-pencil"><?= app_icon('pencil') ?></template>
          <template id="tracking-icon-chevron"><?= app_icon('chevron-right') ?></template>

          <div class="tracking-workspace-layout">
            <section class="tracking-event-section">
              <div class="tracking-browser-toolbar" aria-label="Find and filter tags">
                <label class="tracking-search-field" for="tracking-event-search">
                  <?= app_icon('search') ?>
                  <span class="sr-only">Search tags and triggers</span>
                  <input id="tracking-event-search" type="search" placeholder="Search tags or triggers" autocomplete="off">
                </label>
                <label class="tracking-browser-select" for="tracking-tag-type-filter">
                  <span>Destination</span>
                  <select id="tracking-tag-type-filter"><option value="all">All destinations</option></select>
                </label>
                <label class="tracking-browser-select" for="tracking-trigger-type-filter">
                  <span>Trigger type</span>
                  <select id="tracking-trigger-type-filter"><option value="all">All trigger types</option></select>
                </label>
              </div>
              <div class="tracking-controls-row">
                <div class="tracking-filters" role="group" aria-label="Filter events">
                  <button class="tracking-filter active" type="button" data-filter="all">All</button>
                  <button class="tracking-filter" type="button" data-filter="recommended">Recommended</button>
                  <button class="tracking-filter" type="button" data-filter="mapped">Mapped</button>
                  <button class="tracking-filter" type="button" data-filter="enabled">Included</button>
                  <button class="tracking-filter" type="button" data-filter="configured">Edited</button>
                  <button class="tracking-filter" type="button" data-filter="disabled">Excluded</button>
                </div>
                <section class="tracking-workspace-summary" id="tracking-workspace-summary" aria-label="Workspace summary" aria-live="polite"></section>
              </div>
              <div class="tracking-event-list" id="tracking-event-list" aria-live="polite"></div>
              <nav class="tracking-pagination" id="tracking-pagination" aria-label="Tag list pagination">
                <label class="tracking-page-size" for="tracking-page-size">Show
                  <select id="tracking-page-size">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                  </select>
                  per page
                </label>
                <span class="tracking-page-range" id="tracking-page-range" aria-live="polite"></span>
                <div class="tracking-page-actions">
                  <button class="tracking-page-button" id="tracking-page-previous" type="button" aria-label="Previous page" title="Previous page"><?= app_icon('arrow-left') ?></button>
                  <span id="tracking-page-number"></span>
                  <button class="tracking-page-button" id="tracking-page-next" type="button" aria-label="Next page" title="Next page"><?= app_icon('arrow-right') ?></button>
                </div>
              </nav>
            </section>

            <aside class="tracking-privacy-column">
              <section class="card tracking-privacy-card">
                <span class="onboarding-eyebrow">Privacy control</span>
                <h2>Personal identifiers</h2>
                <div class="tracking-privacy-lock"><strong>Raw form values are never captured</strong><p>Names, email addresses, phone numbers, messages, and other typed values are blocked from event parameters, storage, and logs. Allowed identifiers are trimmed, standardised and validated immediately before one-way hashing.</p></div>
                <form id="tracking-privacy-form">
                  <label class="tracking-privacy-option">
                    <input type="radio" name="pii_policy" value="off" checked>
                    <span><strong>Do not use identifiers</strong><small>Includes hashed identifiers. Recommended default.</small></span>
                  </label>
                  <label class="tracking-privacy-option">
                    <input type="radio" name="pii_policy" value="hashed">
                    <span><strong>Allow selected identifiers as SHA-256 hashes</strong><small>Only after the required consent and destination rules pass.</small></span>
                  </label>
                  <div class="tracking-hashed-fields" id="tracking-hashed-fields" hidden>
                    <p>Allow only:</p>
                    <label><input type="checkbox" value="email"> Email</label>
                    <label><input type="checkbox" value="phone"> Phone</label>
                    <label><input type="checkbox" value="full_name"> Full name</label>
                    <label><input type="checkbox" value="first_name"> First name</label>
                    <label><input type="checkbox" value="last_name"> Last name</label>
                  </div>
                  <button class="btn btn-secondary tracking-hash-learn" id="tracking-hash-demo-open" type="button" hidden><?= app_icon('info') ?> See how SHA-256 works</button>
                  <button class="btn btn-primary tracking-privacy-save" id="tracking-privacy-save" type="submit">Save privacy policy</button>
                </form>
                <p class="tracking-policy-note">This setting defines the tracking policy. Server-side hashing and destination delivery are not activated until those integrations are configured and verified.</p>
              </section>
            </aside>
          </div>

          <div class="tracking-hash-modal" id="tracking-hash-modal" hidden>
            <section class="tracking-hash-dialog" role="dialog" aria-modal="true" aria-labelledby="tracking-hash-title">
              <header class="tracking-hash-dialog-header">
                <div><span class="onboarding-eyebrow">Browser-only example</span><h2 id="tracking-hash-title">See how SHA-256 works</h2><p>Enter sample values for the identifier fields you selected. TagVerity normalises and hashes them locally in this browser. Nothing is sent or saved.</p></div>
                <button class="btn btn-ghost tracking-hash-close" id="tracking-hash-close" type="button" aria-label="Close SHA-256 example"><?= app_icon('x') ?></button>
              </header>
              <div class="tracking-hash-demo-fields" id="tracking-hash-demo-fields"></div>
              <div class="tracking-hash-dialog-note"><?= app_icon('info') ?><p>Hashing reduces exposure; it does not make personal information anonymous or remove consent and privacy obligations.</p></div>
              <footer class="tracking-hash-dialog-actions">
                <button class="btn btn-secondary" id="tracking-hash-cancel" type="button">Close</button>
                <button class="btn btn-primary" id="tracking-hash-generate" type="button">Generate example hashes</button>
              </footer>
            </section>
          </div>

          <div class="tracking-hash-modal" id="tracking-payload-modal" hidden>
            <section class="tracking-hash-dialog tracking-payload-dialog" role="dialog" aria-modal="true" aria-labelledby="tracking-payload-title">
              <header class="tracking-hash-dialog-header">
                <div><span class="onboarding-eyebrow">Safe test data only</span><h2 id="tracking-payload-title">Destination payload preview</h2><p id="tracking-payload-subtitle">See how the saved canonical event is translated before release testing.</p></div>
                <button class="btn btn-ghost tracking-hash-close" id="tracking-payload-close" type="button" aria-label="Close payload preview"><?= app_icon('x') ?></button>
              </header>
              <div class="tracking-payload-status" id="tracking-payload-status"></div>
              <div class="tracking-payload-columns">
                <section><h3>Canonical event</h3><pre id="tracking-canonical-payload"></pre></section>
                <section><h3>Destination payload</h3><pre id="tracking-destination-payload"></pre></section>
              </div>
              <footer class="tracking-hash-dialog-actions"><a class="btn btn-secondary" href="destination-connections.php?client_id=<?= (int)$client['id'] ?>">Manage connection</a><button class="btn btn-primary" id="tracking-payload-done" type="button">Done</button></footer>
            </section>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<div id="toast-container" aria-live="polite"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php if ($client): ?>
  <script src="<?= asset_url('assets/js/identifier-processor.js') ?>"></script>
  <script src="<?= asset_url('assets/js/tracking-workspace.js') ?>"></script>
<?php endif; ?>
</body>
</html>
