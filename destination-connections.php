<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/google-oauth.php';
$user = require_auth();
$active_page = 'destination-connections';
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
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$googleAuthUrl = $client ? google_oauth_url('/destination-connections.php?client_id=' . (int)$client['id']) : '';
$googleAuthMessage = (!GOOGLE_CLIENT_ID || !GOOGLE_CLIENT_SECRET)
    ? 'An administrator needs to finish the Google sign-in setup.'
    : ($googleAuthUrl === '' ? 'Secure credential storage must be configured before Google sign-in can be enabled.' : '');
$flashError = trim((string)($_GET['error'] ?? ''));
$flashConnected = isset($_GET['connected']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Event destinations - TagVerity</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">
    <header class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Event destinations</h1>
        <p class="page-subtitle"><?php if ($client): ?><?= htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?php endif; ?>Connect each platform once, then route many events to it.</p>
      </div>
      <?php if ($client): ?><div class="page-header-actions"><a class="btn btn-secondary" href="tracking-workspace.php?client_id=<?= (int)$client['id'] ?>"><?= app_icon('arrow-left') ?> Tags &amp; triggers</a></div><?php endif; ?>
    </header>
    <div class="page-body destination-connections-page">
      <?php if (!$client): ?>
        <section class="empty-state card"><h2>No website is available</h2><p>Add a client website before connecting event destinations.</p></section>
      <?php else: ?>
        <?php if ($flashConnected): ?><div class="alert alert-success" data-auto-dismiss><span class="alert-icon"><?= app_icon('check-circle') ?></span><span>Google account connected. Open the destination you want to finish setting up.</span></div><?php endif; ?>
        <?php if ($flashError): ?><div class="alert alert-error" data-auto-dismiss><span class="alert-icon"><?= app_icon('alert') ?></span><span>Google sign-in was not completed. Please try again.</span></div><?php endif; ?>
        <div id="destination-connections-app" data-client-id="<?= (int)$client['id'] ?>" data-api-base="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>" data-google-auth-url="<?= htmlspecialchars($googleAuthUrl, ENT_QUOTES, 'UTF-8') ?>" data-google-auth-message="<?= htmlspecialchars($googleAuthMessage, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <section class="destination-intro card">
            <div><?= app_icon('server') ?></div>
            <div><h2>Connect your analytics and advertising tools</h2><p>Open one platform at a time. TagVerity will explain what is needed and where to find it. Nothing is published while you are setting up.</p></div>
          </section>
          <div class="destination-grid" id="destination-connection-grid"></div>
          <dialog id="destination-dialog" class="reveal-dialog" aria-labelledby="destination-dialog-title">
            <header class="destination-dialog-header">
              <span class="destination-mark" id="destination-dialog-mark" aria-hidden="true"></span>
              <div>
                <h2 class="destination-dialog-title" id="destination-dialog-title"></h2>
                <span class="destination-status" id="destination-dialog-status"></span>
                <p class="destination-dialog-summary" id="destination-dialog-summary"></p>
              </div>
              <button type="button" class="destination-dialog-close" data-dialog-close aria-label="Close destination setup"><?= app_icon('x') ?></button>
            </header>
            <div class="destination-dialog-body" id="destination-dialog-body"></div>
          </dialog>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<div id="toast-container" aria-live="polite"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php if ($client): ?><script src="<?= asset_url('assets/js/destination-connections.js') ?>"></script><?php endif; ?>
</body>
</html>
