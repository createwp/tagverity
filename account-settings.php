<?php
// ─── account-settings.php ─────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
$user        = require_auth();
$active_page = 'settings';
$pdo         = db();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = '';
$success = '';
$can_manage_account = in_array($user['role'], ['superadmin', 'owner', 'admin'], true);
$allowed_tabs = $can_manage_account ? ['profile', 'account', 'security'] : ['profile', 'security'];
$active_tab = in_array($_GET['tab'] ?? '', $allowed_tabs, true) ? $_GET['tab'] : 'profile';

// ── Fetch account ──────────────────────────────────────────────────────────────
$account_stmt = $pdo->prepare("SELECT id, name, type FROM accounts WHERE id = ? LIMIT 1");
$account_stmt->execute([$user['account_id']]);
$account = $account_stmt->fetch();

// ── Handle profile update ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_account') {
        $active_tab = 'account';
    } elseif ($action === 'change_password') {
        $active_tab = 'security';
    } else {
        $active_tab = 'profile';
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission.';
    } else {
        if ($action === 'update_profile') {
            $name  = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (empty($name) || empty($email)) {
                $error = 'Name and email are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address.';
            } else {
                try {
                    // Check email uniqueness (excluding self)
                    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                    $chk->execute([$email, $user['id']]);
                    if ($chk->fetch()) {
                        $error = 'That email is already in use.';
                    } else {
                        $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")
                            ->execute([$name, $email, $user['id']]);
                        $success = 'Profile updated.';
                        // Refresh $user
                        $user['name']  = $name;
                        $user['email'] = $email;
                    }
                } catch (\Throwable $e) {
                    error_log('[GTMAuditor][Settings] profile update: ' . $e->getMessage());
                    $error = 'Update failed. Please try again.';
                }
            }
        } elseif ($action === 'update_account') {
            // Only owner/admin can update account name
            if (!$can_manage_account) {
                $error = 'Permission denied.';
            } else {
                $account_name = trim($_POST['account_name'] ?? '');
                if (empty($account_name)) {
                    $error = 'Account name is required.';
                } else {
                    $pdo->prepare("UPDATE accounts SET name = ? WHERE id = ?")
                        ->execute([$account_name, $user['account_id']]);
                    $account['name'] = $account_name;
                    $success = 'Account name updated.';
                }
            }
        } elseif ($action === 'change_password') {
            $current  = $_POST['current_password']  ?? '';
            $new_pass = $_POST['new_password']       ?? '';
            $confirm  = $_POST['confirm_password']   ?? '';
            $full     = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $full->execute([$user['id']]);
            $hash = $full->fetchColumn();

            if (!password_verify($current, $hash)) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($new_pass) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif ($new_pass !== $confirm) {
                $error = 'New passwords do not match.';
            } else {
                $newHash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $user['id']]);
                $success = 'Password changed successfully.';
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
  <title>Account Settings — GTM Auditor</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Account settings</h1>
        <p class="page-subtitle">Manage your profile, account and security.</p>
      </div>
    </div>
    <div class="page-body settings-page">

      <?php if ($error): ?>
      <div class="alert alert-error" data-auto-dismiss><span class="alert-icon"><?= app_icon('alert') ?></span><span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span></div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div class="alert alert-success" data-auto-dismiss><span class="alert-icon"><?= app_icon('check-circle') ?></span><span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span></div>
      <?php endif; ?>

      <nav class="settings-tabs" role="tablist" aria-label="Account settings">
        <a href="?tab=profile" class="settings-tab <?= $active_tab === 'profile' ? 'active' : '' ?>" role="tab" id="settings-tab-profile" aria-controls="settings-panel-profile" aria-selected="<?= $active_tab === 'profile' ? 'true' : 'false' ?>" tabindex="<?= $active_tab === 'profile' ? '0' : '-1' ?>" data-settings-tab="profile"><?= app_icon('user') ?> Profile</a>
        <?php if ($can_manage_account): ?>
        <a href="?tab=account" class="settings-tab <?= $active_tab === 'account' ? 'active' : '' ?>" role="tab" id="settings-tab-account" aria-controls="settings-panel-account" aria-selected="<?= $active_tab === 'account' ? 'true' : 'false' ?>" tabindex="<?= $active_tab === 'account' ? '0' : '-1' ?>" data-settings-tab="account"><?= app_icon('building') ?> Account</a>
        <?php endif; ?>
        <a href="?tab=security" class="settings-tab <?= $active_tab === 'security' ? 'active' : '' ?>" role="tab" id="settings-tab-security" aria-controls="settings-panel-security" aria-selected="<?= $active_tab === 'security' ? 'true' : 'false' ?>" tabindex="<?= $active_tab === 'security' ? '0' : '-1' ?>" data-settings-tab="security"><?= app_icon('lock') ?> Security</a>
      </nav>

      <section class="settings-panel" id="settings-panel-profile" role="tabpanel" aria-labelledby="settings-tab-profile" data-settings-panel="profile" <?= $active_tab !== 'profile' ? 'hidden' : '' ?>>
      <div class="card">
        <div class="card-header"><div class="card-title">Your Profile</div></div>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="update_profile">
          <div class="form-group">
            <label class="form-label" for="name">Full name</label>
            <input class="form-input" type="text" id="name" name="name"
                   value="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="email">Email address</label>
            <input class="form-input" type="email" id="email" name="email"
                   value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Role</label>
            <input class="form-input" type="text" value="<?= htmlspecialchars(ucfirst($user['role']), ENT_QUOTES, 'UTF-8') ?>" readonly
                   style="opacity:0.6;cursor:not-allowed">
          </div>
          <button type="submit" class="btn btn-primary">Save Profile</button>
        </form>
      </div>
      </section>

      <?php if ($can_manage_account): ?>
      <section class="settings-panel" id="settings-panel-account" role="tabpanel" aria-labelledby="settings-tab-account" data-settings-panel="account" <?= $active_tab !== 'account' ? 'hidden' : '' ?>>
      <div class="card">
        <div class="card-header"><div class="card-title">Account</div></div>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="update_account">
          <div class="form-group">
            <label class="form-label" for="account_name">Account / Company name</label>
            <input class="form-input" type="text" id="account_name" name="account_name"
                   value="<?= htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Account type</label>
            <input class="form-input" type="text" value="<?= htmlspecialchars(ucfirst($account['type']), ENT_QUOTES, 'UTF-8') ?>" readonly style="opacity:0.6;cursor:not-allowed">
          </div>
          <button type="submit" class="btn btn-primary">Save Account</button>
        </form>
      </div>
      </section>
      <?php endif; ?>

      <section class="settings-panel" id="settings-panel-security" role="tabpanel" aria-labelledby="settings-tab-security" data-settings-panel="security" <?= $active_tab !== 'security' ? 'hidden' : '' ?>>
      <div class="card">
        <div class="card-header"><div class="card-title">Change Password</div></div>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="change_password">
          <div class="form-group">
            <label class="form-label" for="current_password">Current password</label>
            <input class="form-input" type="password" id="current_password" name="current_password" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="new_password">New password</label>
            <input class="form-input" type="password" id="new_password" name="new_password" required minlength="8">
          </div>
          <div class="form-group">
            <label class="form-label" for="confirm_password">Confirm new password</label>
            <input class="form-input" type="password" id="confirm_password" name="confirm_password" required>
          </div>
          <button type="submit" class="btn btn-primary">Change Password</button>
        </form>
      </div>
      </section>

    </div>
  </div>
</div>
<div id="toast-container"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script src="<?= asset_url('assets/js/account-settings.js') ?>"></script>
</body>
</html>
