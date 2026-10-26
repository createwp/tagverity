<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_auth();
$active_page = 'element-mapper';

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
$profile = null;
$pages = [];
if ($client) {
    $stmt = $pdo->prepare(
        "SELECT op.status, op.latest_scan_id, op.measurement_plan, ss.scan_data
         FROM onboarding_profiles op
         LEFT JOIN site_scans ss ON ss.id = op.latest_scan_id
         WHERE op.client_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$client['id']]);
    $profile = $stmt->fetch();
    if ($profile && $profile['scan_data']) {
        $scan = json_decode($profile['scan_data'], true);
        foreach ($scan['pages'] ?? [] as $page) {
            if (!empty($page['url'])) {
                $pages[] = ['url' => $page['url'], 'title' => $page['title'] ?: ($page['h1'] ?: $page['url'])];
            }
        }
    }
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$ready = $client && $profile && $profile['status'] === 'plan_ready' && $pages;
$returnToRelease = ($_GET['return'] ?? '') === 'release';
$returnUrl = ($returnToRelease ? 'publish-tags.php' : 'tracking-workspace.php') . '?client_id=' . (int)($client['id'] ?? 0);
$returnLabel = $returnToRelease ? 'Return to release review' : 'Return to tags & triggers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Visual element mapper - TagVerity</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">
    <header class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Visual element mapper</h1>
        <p class="page-subtitle"><?php if ($client): ?><?= htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?php endif; ?>Select a page element and create a draft trigger.</p>
      </div>
    </header>

    <div class="page-body element-mapper-page">
      <?php if (!$ready): ?>
        <section class="empty-state card">
          <div class="empty-state-icon" aria-hidden="true"><?= app_icon('tags') ?></div>
          <h2>Create a measurement plan first</h2>
          <p>Run the site scan and verify the proposed plan before mapping individual page elements.</p>
          <a class="btn btn-primary mt-4" href="onboarding.php<?= $client ? '?client_id=' . (int)$client['id'] : '' ?>">Open tracking setup</a>
        </section>
      <?php else: ?>
        <div id="element-mapper-app" data-client-id="<?= (int)$client['id'] ?>" data-api-base="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>" data-edit-mapping-id="<?= (int)($_GET['mapping_id'] ?? 0) ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

          <?php if ($returnToRelease): ?>
            <div class="release-notice mapper-release-preview-note">
              <strong>Release preview</strong>
              <span>Inspect the selected elements and selectors here. Live trigger behaviour must still pass the release verification checks before publishing.</span>
            </div>
          <?php endif; ?>

          <section class="card mapper-toolbar<?= $returnToRelease ? ' mt-4' : '' ?>">
            <div class="mapper-toolbar-copy">
              <span class="onboarding-eyebrow">Safe static preview</span>
              <h2>Choose a scanned page</h2>
              <p>Scripts and form submission are disabled. This preview is for selecting a stable element; live behaviour is verified later.</p>
            </div>
            <div class="mapper-toolbar-actions">
              <label class="mapper-page-picker">
                <span class="form-label">Page to preview</span>
                <select class="form-select" id="mapper-page-select">
                  <?php foreach ($pages as $page): ?>
                    <option value="<?= htmlspecialchars($page['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars(parse_url($page['url'], PHP_URL_PATH) ?: '/', ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button class="btn btn-secondary" id="mapper-fullscreen-button" type="button">Open full-screen mapper</button>
            </div>
          </section>

          <div class="mapper-workspace mt-4">
            <div class="mapper-preview-stack">
            <section class="card mapper-preview-card">
              <div class="mapper-preview-header">
                <div><strong>Page preview</strong><span id="mapper-preview-status">Loading safe preview...</span></div>
                <div class="mapper-preview-actions">
                  <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($returnLabel, ENT_QUOTES, 'UTF-8') ?></a>
                  <button class="btn btn-secondary btn-sm" id="reload-preview-button" type="button">Reload</button>
                  <button class="btn btn-ghost btn-sm mapper-fullscreen-close" id="mapper-fullscreen-close" type="button" hidden>Exit full screen</button>
                </div>
              </div>
              <iframe id="site-preview-frame" class="mapper-preview-frame" title="Selectable website preview" sandbox="allow-scripts" referrerpolicy="no-referrer"></iframe>
            </section>

            <section class="card mapper-drafts-panel">
              <div class="onboarding-section-heading">
                <div><span class="onboarding-eyebrow">Saved element mappings</span><h2>Mapped elements</h2><p>Each mapping supplies a trigger to the central event workspace, where you choose its events and parameters.</p></div>
                <a class="btn btn-primary" href="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($returnLabel, ENT_QUOTES, 'UTF-8') ?></a>
              </div>
              <div class="mapper-mapping-list" id="saved-mappings"></div>
            </section>
            </div>

            <aside class="card mapper-config-card">
              <div id="mapper-selection-empty" class="mapper-selection-empty">
                <div class="mapper-selection-icon" aria-hidden="true">↖</div>
                <h2>Select an element</h2>
                <p>Move over the page preview and click a button, link, form, heading, product value, search control, or other meaningful element.</p>
              </div>

              <form id="mapping-form" hidden>
                <input type="hidden" id="mapping-id" value="">
                <div class="mapper-selected-summary">
                  <span class="onboarding-eyebrow">Selected element</span>
                  <strong id="selected-element-label"></strong>
                  <small id="selected-element-meta"></small>
                </div>

                <div class="form-group">
                  <label class="form-label" for="element-business-name">What should people call this?</label>
                  <input class="form-input" id="element-business-name" type="text" maxlength="120" required placeholder="For example: Book a consultation">
                  <p class="form-help">This becomes the consistent business name in event parameters and reports.</p>
                </div>

                <fieldset class="mapper-fieldset">
                  <legend>Why do you want to measure it?</legend>
                  <label class="mapper-radio"><input type="radio" name="measurement_goal" value="product_analytics" checked><span><strong>Understand behaviour</strong><small>Product or content analytics</small></span></label>
                  <label class="mapper-radio"><input type="radio" name="measurement_goal" value="marketing_conversion"><span><strong>Count a conversion</strong><small>Marketing and advertising goal</small></span></label>
                  <label class="mapper-radio"><input type="radio" name="measurement_goal" value="user_experience"><span><strong>Improve the experience</strong><small>Engagement or abandonment</small></span></label>
                  <label class="mapper-radio"><input type="radio" name="measurement_goal" value="data_quality"><span><strong>Build reliable data</strong><small>Product, search, or ecommerce value</small></span></label>
                </fieldset>

                <fieldset class="mapper-fieldset">
                  <legend>How should it be tracked?</legend>
                  <label class="mapper-radio" data-intent-option="click"><input type="radio" name="tracking_intent" value="click"><span><strong>When it is clicked</strong><small>Buttons, links, navigation, downloads</small></span><em>Recommended</em></label>
                  <label class="mapper-radio" data-intent-option="visibility"><input type="radio" name="tracking_intent" value="visibility"><span><strong>When it becomes visible</strong><small>Messages, offers, sections, banners</small></span><em>Recommended</em></label>
                  <label class="mapper-radio" data-intent-option="form"><input type="radio" name="tracking_intent" value="form"><span><strong>Track the form journey</strong><small>Start, fields, abandonment, completion</small></span><em>Recommended</em></label>
                  <label class="mapper-radio" data-intent-option="ecommerce_value"><input type="radio" name="tracking_intent" value="ecommerce_value"><span><strong>Use it as product data</strong><small>Name, ID, category, price, currency</small></span><em>Recommended</em></label>
                  <label class="mapper-radio" data-intent-option="site_search"><input type="radio" name="tracking_intent" value="site_search"><span><strong>Use it as search data</strong><small>Search term, results, filter, sort</small></span><em>Recommended</em></label>
                </fieldset>

                <fieldset class="mapper-fieldset">
                  <legend>Where should this trigger run?</legend>
                  <label class="mapper-radio"><input type="radio" name="trigger_scope" value="page" checked><span><strong>Only on this page</strong><small>Use the selected page URL and selector together</small></span></label>
                  <label class="mapper-radio"><input type="radio" name="trigger_scope" value="global"><span><strong>Everywhere on the website</strong><small>Use one tag for matching buttons or elements across all pages</small></span><em>Fewer tags</em></label>
                  <p class="form-help">Global triggers must be tested on several pages to make sure the selector does not match unrelated elements.</p>
                </fieldset>

                <div class="form-group" id="data-key-group" hidden>
                  <label class="form-label" for="mapping-data-key">What data does this element contain?</label>
                  <select class="form-select" id="mapping-data-key"></select>
                </div>

                <div class="mapper-recommendation" id="trigger-recommendation"></div>

                <div class="form-group">
                  <label class="form-label" for="selected-css-selector">Technical selector</label>
                  <textarea class="form-textarea font-mono" id="selected-css-selector" rows="3" readonly></textarea>
                  <p class="form-help">TagVerity will test this selector during live preview before anything can be published.</p>
                </div>

                <button class="btn btn-primary btn-lg mapper-save-button" id="save-mapping-button" type="submit">Save element mapping</button>
              </form>
            </aside>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<div id="toast-container" aria-live="polite"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php if ($ready): ?><script src="<?= asset_url('assets/js/element-mapper.js') ?>"></script><?php endif; ?>
</body>
</html>
