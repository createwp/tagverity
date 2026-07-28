<?php
require_once __DIR__ . '/includes/auth.php';
// Redirect logged-in users to their dashboard
if (current_user()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}
header('Location: ' . APP_URL . '/login.php');
exit;
