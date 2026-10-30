<?php
// ─── Cron entry point — deletes expired live-mapper sessions ─────────────────
// Cron schedule: hourly, at the top of the hour.
// Cron command: php /path/to/gtm-auditor/cron/sweep-mapper-sessions.php >> /dev/null 2>&1
// Cron line:    0 * * * *

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/MapperSessionManager.php';

use GTMAuditor\MapperSessionManager;

// Basic security: this script must only be run from CLI, never HTTP
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden.');
}

$deleted = (new MapperSessionManager())->purgeExpired(db(), 3600);

echo 'Purged ' . $deleted . ' expired mapper sessions.' . PHP_EOL;
