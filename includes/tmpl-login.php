<?php require_once __DIR__ . '/icons.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — GTM Auditor</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="auth-page">
  <div class="auth-card">

    <div class="auth-logo">
      <div class="auth-logo-icon"><?= app_icon('brand') ?></div>
      <span class="auth-logo-name">TagVerity</span>
    </div>

    <h1 class="auth-title">Welcome back</h1>
    <p class="auth-subtitle">Sign in to your account to continue</p>

    <?php if (!empty($error)): ?>
    <div class="alert alert-error" role="alert">
      <span class="alert-icon"><?= app_icon('alert') ?></span><span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="login.php" id="login-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

      <div class="form-group">
        <label class="form-label" for="email">Email address</label>
        <input
          class="form-input"
          type="email"
          id="email"
          name="email"
          value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8') ?>"
          placeholder="you@example.com"
          required
          autocomplete="email"
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="password">
          Password
          <a href="password-reset.php" style="float:right; font-weight:400; color:var(--text-muted);">Forgot password?</a>
        </label>
        <input
          class="form-input"
          type="password"
          id="password"
          name="password"
          placeholder="••••••••"
          required
          autocomplete="current-password"
        >
      </div>

      <button type="submit" class="btn btn-primary btn-lg" style="width:100%" id="login-btn">
        Sign in
      </button>
    </form>

    <div class="auth-divider"><span>or</span></div>

    <a href="<?= htmlspecialchars($google_auth_url ?? '#', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-google" id="google-login-btn">
      <svg width="18" height="18" viewBox="0 0 18 18"><path fill="#4285F4" d="M16.51 8H8.98v3h4.3c-.18 1-.74 1.48-1.6 2.04v2.01h2.6a7.8 7.8 0 0 0 2.38-5.88c0-.57-.05-.66-.15-1.18z"/><path fill="#34A853" d="M8.98 17c2.16 0 3.97-.72 5.3-1.94l-2.6-2a4.8 4.8 0 0 1-7.18-2.54H1.83v2.07A8 8 0 0 0 8.98 17z"/><path fill="#FBBC05" d="M4.5 10.52a4.8 4.8 0 0 1 0-3.04V5.41H1.83a8 8 0 0 0 0 7.18l2.67-2.07z"/><path fill="#EA4335" d="M8.98 4.18c1.17 0 2.23.4 3.06 1.2l2.3-2.3A8 8 0 0 0 1.83 5.4L4.5 7.49a4.77 4.77 0 0 1 4.48-3.3z"/></svg>
      Continue with Google
    </a>

    <div class="auth-footer">
      Don't have an account? <a href="register.php">Create one free</a>
    </div>

  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
