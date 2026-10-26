<?php
// ─── Registration page ────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/rate-limit.php';

// Already logged in → go to dashboard
if (current_user()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = '';
$success = '';
$fields  = ['name' => '', 'email' => '', 'account_name' => '', 'account_type' => 'standalone'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit: 5 registrations per IP per 10 minutes
    rate_limit('register', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 5, 600);

    // CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $fields['name']         = trim($_POST['name']         ?? '');
        $fields['email']        = strtolower(trim($_POST['email']        ?? ''));
        $fields['account_name'] = trim($_POST['account_name'] ?? '');
        $fields['account_type'] = in_array($_POST['account_type'] ?? '', ['standalone','agency']) ? $_POST['account_type'] : 'standalone';
        $password               = $_POST['password']         ?? '';
        $password_confirm       = $_POST['password_confirm'] ?? '';

        // Validation
        if (empty($fields['name']) || empty($fields['email']) || empty($fields['account_name']) || empty($password)) {
            $error = 'All fields are required.';
        } elseif (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $password_confirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $pdo = db();

                // Check email uniqueness
                $exists = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $exists->execute([$fields['email']]);
                if ($exists->fetch()) {
                    $error = 'An account with this email already exists. <a href="login.php">Sign in instead?</a>';
                } else {
                    $pdo->beginTransaction();

                    // Create account (Starter plan = id 1)
                    $pdo->prepare(
                        "INSERT INTO accounts (name, type, plan_id, created_at) VALUES (?, ?, 1, NOW())"
                    )->execute([$fields['account_name'], $fields['account_type']]);
                    $account_id = (int)$pdo->lastInsertId();

                    // Create owner user
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $pdo->prepare(
                        "INSERT INTO users (account_id, email, name, password_hash, role, created_at)
                         VALUES (?, ?, ?, ?, 'owner', NOW())"
                    )->execute([$account_id, $fields['email'], $fields['name'], $hash]);
                    $user_id = (int)$pdo->lastInsertId();

                    // Set owner_user_id on the account
                    $pdo->prepare("UPDATE accounts SET owner_user_id = ? WHERE id = ?")
                        ->execute([$user_id, $account_id]);

                    // Create the default client (standalone = themselves; agency = placeholder)
                    $client_name = $fields['account_type'] === 'agency' ? 'My First Client' : $fields['account_name'];
                    $pdo->prepare(
                        "INSERT INTO clients (account_id, name, created_at) VALUES (?, ?, NOW())"
                    )->execute([$account_id, $client_name]);

                    $pdo->commit();

                    // Auto-login
                    $user = $pdo->prepare("SELECT u.*, a.plan_id FROM users u JOIN accounts a ON a.id = u.account_id WHERE u.id = ? LIMIT 1");
                    $user->execute([$user_id]);
                    login_user($user->fetch());

                    header('Location: ' . APP_URL . '/dashboard.php?welcome=1');
                    exit;
                }
            } catch (\Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                error_log('[GTMAuditor][Register] Error: ' . $e->getMessage());
                $error = 'A server error occurred. Please try again.';
            }
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account — GTM Auditor</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="auth-page">
  <div class="auth-card" style="max-width:460px">

    <div class="auth-logo">
      <div class="auth-logo-icon"><?= app_icon('brand') ?></div>
      <span class="auth-logo-name">TagVerity</span>
    </div>

    <h1 class="auth-title">Create your account</h1>
    <p class="auth-subtitle">Start auditing GTM containers for free</p>

    <?php if ($error): ?>
    <div class="alert alert-error"><span class="alert-icon"><?= app_icon('alert') ?></span><span><?= $error ?></span></div>
    <?php endif; ?>

    <form method="POST" action="register.php" id="register-form" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

      <div class="form-group">
        <label class="form-label" for="name">Your full name</label>
        <input class="form-input" type="text" id="name" name="name"
          value="<?= htmlspecialchars($fields['name'], ENT_QUOTES, 'UTF-8') ?>"
          placeholder="Jane Smith" required autocomplete="name">
      </div>

      <div class="form-group">
        <label class="form-label" for="email">Email address</label>
        <input class="form-input" type="email" id="email" name="email"
          value="<?= htmlspecialchars($fields['email'], ENT_QUOTES, 'UTF-8') ?>"
          placeholder="jane@agency.com" required autocomplete="email">
      </div>

      <div class="form-group">
        <label class="form-label" for="account_name">Account / Company name</label>
        <input class="form-input" type="text" id="account_name" name="account_name"
          value="<?= htmlspecialchars($fields['account_name'], ENT_QUOTES, 'UTF-8') ?>"
          placeholder="Acme Agency" required>
      </div>

      <div class="form-group">
        <label class="form-label">Account type</label>
        <div class="flex gap-3" style="margin-top:var(--space-1)">
          <label style="flex:1; cursor:pointer;">
            <input type="radio" name="account_type" value="standalone"
              <?= $fields['account_type'] === 'standalone' ? 'checked' : '' ?>
              style="display:none" class="acct-type-radio">
            <div class="card acct-type-card <?= $fields['account_type'] === 'standalone' ? 'selected' : '' ?>"
                 style="padding:var(--space-3); text-align:center; cursor:pointer;">
              <div class="account-type-icon"><?= app_icon('building') ?></div>
              <div style="font-size:0.8rem;font-weight:600">My Business</div>
              <div style="font-size:0.7rem;color:var(--text-muted)">Auditing my own sites</div>
            </div>
          </label>
          <label style="flex:1; cursor:pointer;">
            <input type="radio" name="account_type" value="agency"
              <?= $fields['account_type'] === 'agency' ? 'checked' : '' ?>
              style="display:none" class="acct-type-radio">
            <div class="card acct-type-card <?= $fields['account_type'] === 'agency' ? 'selected' : '' ?>"
                 style="padding:var(--space-3); text-align:center; cursor:pointer;">
              <div class="account-type-icon"><?= app_icon('briefcase') ?></div>
              <div style="font-size:0.8rem;font-weight:600">Agency</div>
              <div style="font-size:0.7rem;color:var(--text-muted)">Managing client sites</div>
            </div>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input class="form-input" type="password" id="password" name="password"
          placeholder="Min. 8 characters" required autocomplete="new-password">
        <div class="form-hint">At least 8 characters</div>
      </div>

      <div class="form-group">
        <label class="form-label" for="password_confirm">Confirm password</label>
        <input class="form-input" type="password" id="password_confirm" name="password_confirm"
          placeholder="••••••••" required autocomplete="new-password">
      </div>

      <button type="submit" class="btn btn-primary btn-lg" style="width:100%" id="register-btn">
        Create account — it's free
      </button>

      <div style="text-align:center;margin-top:var(--space-3);font-size:0.72rem;color:var(--text-muted)">
        By creating an account you agree to our <a href="#">Terms</a> and <a href="#">Privacy Policy</a>.
      </div>
    </form>

    <div class="auth-footer">
      Already have an account? <a href="login.php">Sign in</a>
    </div>

  </div>
</div>

<style>
.acct-type-card { transition: all var(--duration) var(--ease); }
.acct-type-card.selected { border-color: var(--brand-primary) !important; background: rgba(99,102,241,0.06); }
</style>
<script>
// Account type card toggle
document.querySelectorAll('.acct-type-radio').forEach(radio => {
  radio.addEventListener('change', () => {
    document.querySelectorAll('.acct-type-card').forEach(c => c.classList.remove('selected'));
    radio.nextElementSibling.classList.add('selected');
  });
});
// Click on card triggers radio
document.querySelectorAll('.acct-type-card').forEach(card => {
  card.addEventListener('click', () => {
    card.previousElementSibling.checked = true;
    document.querySelectorAll('.acct-type-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
  });
});
</script>
</body>
</html>
