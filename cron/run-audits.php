<?php
// ─── Cron entry point — called by SiteGround cron every 5 minutes ────────────
// Cron command: php /path/to/gtm-auditor/cron/run-audits.php >> /dev/null 2>&1

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/db.php';

use GTMAuditor\AuditJobRunner;

// Basic security: this script must only be run from CLI, never HTTP
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden.');
}

$runner = new AuditJobRunner();
$jobId  = $runner->processNext();

if ($jobId) {
    echo '[' . date('Y-m-d H:i:s') . '] Processed audit job #' . $jobId . PHP_EOL;
} else {
    echo '[' . date('Y-m-d H:i:s') . '] No queued jobs.' . PHP_EOL;
}
