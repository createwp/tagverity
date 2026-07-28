<?php
// ─── PDO singleton ────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

/**
 * Returns the shared PDO instance for the application.
 *
 * Uses a static variable so the connection is opened once per PHP process.
 * All queries must use prepared statements — never string-interpolate user input.
 *
 * @return PDO
 * @throws PDOException on connection failure
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
