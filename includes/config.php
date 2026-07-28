<?php
// ─── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad(); // safeLoad() silently skips missing .env (safe on prod)

// ─── App ──────────────────────────────────────────────────────────────────────
define('APP_ENV',     $_ENV['APP_ENV']     ?? 'production');
define('APP_NAME',    $_ENV['APP_NAME']    ?? 'GTM Auditor');
define('APP_VERSION', $_ENV['APP_VERSION'] ?? '1.0.0');
define('APP_URL',     rtrim($_ENV['APP_URL'] ?? '', '/'));
define('APP_ROOT',    dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', APP_ENV !== 'production' ? '1' : '0');

// ─── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'gtm_auditor');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// ─── Encryption — AES-256 for Google refresh token storage ───────────────────
// Key is stored ONLY in the environment variable, never in the database.
define('TOKEN_ENCRYPTION_KEY', $_ENV['TOKEN_ENCRYPTION_KEY'] ?? '');

// ─── Google OAuth ─────────────────────────────────────────────────────────────
define('GOOGLE_CLIENT_ID',     $_ENV['GOOGLE_CLIENT_ID']     ?? '');
define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');

// ─── Stripe ───────────────────────────────────────────────────────────────────
define('STRIPE_SECRET_KEY',       $_ENV['STRIPE_SECRET_KEY']       ?? '');
define('STRIPE_PUBLISHABLE_KEY',  $_ENV['STRIPE_PUBLISHABLE_KEY']  ?? '');
define('STRIPE_WEBHOOK_SECRET',   $_ENV['STRIPE_WEBHOOK_SECRET']   ?? '');

// ─── Mail ─────────────────────────────────────────────────────────────────────
define('MAIL_HOST',         $_ENV['MAIL_HOST']         ?? 'localhost');
define('MAIL_PORT',   (int)($_ENV['MAIL_PORT']         ?? 587));
define('MAIL_USERNAME',     $_ENV['MAIL_USERNAME']     ?? '');
define('MAIL_PASSWORD',     $_ENV['MAIL_PASSWORD']     ?? '');
define('MAIL_FROM_ADDRESS', $_ENV['MAIL_FROM_ADDRESS'] ?? '');
define('MAIL_FROM_NAME',    $_ENV['MAIL_FROM_NAME']    ?? APP_NAME);

// ─── Headless Crawl Microservice (Phase 2) ────────────────────────────────────
define('CRAWL_SERVICE_URL',    $_ENV['CRAWL_SERVICE_URL']    ?? '');
define('CRAWL_SERVICE_SECRET', $_ENV['CRAWL_SERVICE_SECRET'] ?? '');

// ─── Developer tooling ────────────────────────────────────────────────────────
define('DEVELOPER_MIGRATION_KEY', $_ENV['DEVELOPER_MIGRATION_KEY'] ?? '');

// ─── Session ──────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (APP_ENV !== 'local'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ─── Asset URL helper — appends filemtime as ?v= for cache busting ───────────
/**
 * Returns a versioned public URL for a static asset.
 *
 * @param string $path  Relative path from project root, e.g. 'assets/css/app.css'
 * @return string       Full URL with ?v={filemtime} cache-buster
 */
function asset_url(string $path): string {
    $abs = APP_ROOT . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $v   = file_exists($abs) ? filemtime($abs) : 1;
    return APP_URL . '/' . ltrim($path, '/') . '?v=' . $v;
}
