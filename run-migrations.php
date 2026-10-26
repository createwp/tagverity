<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

if (PHP_SAPI !== 'cli') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('POST required.');
    }

    $provided_key = (string)($_POST['key'] ?? '');
    if (DEVELOPER_MIGRATION_KEY === '' || !hash_equals(DEVELOPER_MIGRATION_KEY, $provided_key)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

header('Content-Type: application/json');
$pdo = db();
$results = [];

// Migration 002: Scan-led onboarding
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_scans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            requested_by_user_id INT UNSIGNED NOT NULL,
            root_url VARCHAR(500) NOT NULL,
            status ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
            pages_discovered INT UNSIGNED NOT NULL DEFAULT 0,
            pages_scanned INT UNSIGNED NOT NULL DEFAULT 0,
            scan_data JSON,
            questionnaire JSON,
            error_code VARCHAR(50),
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_site_scans_client_created (client_id, created_at),
            FOREIGN KEY (client_id) REFERENCES clients(id),
            FOREIGN KEY (requested_by_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS onboarding_profiles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            latest_scan_id INT UNSIGNED,
            site_url VARCHAR(500) NOT NULL,
            status ENUM('not_started','questions_ready','plan_ready') NOT NULL DEFAULT 'not_started',
            business_type VARCHAR(50),
            answers JSON,
            measurement_plan JSON,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_onboarding_client (client_id),
            FOREIGN KEY (client_id) REFERENCES clients(id),
            FOREIGN KEY (latest_scan_id) REFERENCES site_scans(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $results[] = ['label' => '002 - scan-led onboarding', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 002 failed: ' . $e->getMessage());
    $results[] = ['label' => '002 - scan-led onboarding', 'ok' => false, 'msg' => $e->getMessage()];
}

// Migration 003: Visual element mappings and generated templates
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS measurement_elements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            site_scan_id INT UNSIGNED NOT NULL,
            created_by_user_id INT UNSIGNED NOT NULL,
            page_url VARCHAR(500) NOT NULL,
            element_name VARCHAR(120) NOT NULL,
            element_role VARCHAR(50) NOT NULL,
            tracking_intent VARCHAR(50) NOT NULL,
            measurement_goal VARCHAR(50) NOT NULL,
            css_selector VARCHAR(1000) NOT NULL,
            selector_strategy VARCHAR(50) NOT NULL DEFAULT 'generated_css',
            data_key VARCHAR(50),
            preview_text VARCHAR(255),
            element_attributes JSON,
            event_template JSON NOT NULL,
            trigger_template JSON NOT NULL,
            tag_templates JSON NOT NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_measurement_elements_client_status (client_id, status),
            INDEX idx_measurement_elements_scan (site_scan_id),
            FOREIGN KEY (client_id) REFERENCES clients(id),
            FOREIGN KEY (site_scan_id) REFERENCES site_scans(id),
            FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $results[] = ['label' => '003 - visual element mappings', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 003 failed: ' . $e->getMessage());
    $results[] = ['label' => '003 - visual element mappings', 'ok' => false, 'msg' => $e->getMessage()];
}

// Migration 004: Account-scoped superadmin role
try {
    $pdo->exec(
        "ALTER TABLE users
         MODIFY COLUMN role ENUM('superadmin','owner','admin','member','viewer') NOT NULL DEFAULT 'member'"
    );
    $results[] = ['label' => '004 - account-scoped superadmin role', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 004 failed: ' . $e->getMessage());
    $results[] = ['label' => '004 - account-scoped superadmin role', 'ok' => false, 'msg' => $e->getMessage()];
}

// Migration 005: Versioned tag releases
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tag_releases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            site_scan_id INT UNSIGNED NOT NULL,
            version_number INT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            status ENUM('draft','approved','publishing','published','failed') NOT NULL DEFAULT 'draft',
            mapping_ids JSON NOT NULL,
            release_snapshot JSON NOT NULL,
            readiness_snapshot JSON NOT NULL,
            approved_by_user_id INT UNSIGNED,
            approved_at DATETIME,
            published_by_user_id INT UNSIGNED,
            published_at DATETIME,
            error_code VARCHAR(80),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tag_release_client_version (client_id, version_number),
            INDEX idx_tag_release_client_status (client_id, status),
            FOREIGN KEY (client_id) REFERENCES clients(id),
            FOREIGN KEY (site_scan_id) REFERENCES site_scans(id),
            FOREIGN KEY (approved_by_user_id) REFERENCES users(id),
            FOREIGN KEY (published_by_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $results[] = ['label' => '005 - versioned tag releases', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 005 failed: ' . $e->getMessage());
    $results[] = ['label' => '005 - versioned tag releases', 'ok' => false, 'msg' => $e->getMessage()];
}

// Migration 006: Page or site-wide trigger scope
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute(['measurement_elements', 'trigger_scope']);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE measurement_elements
             ADD COLUMN trigger_scope ENUM('page','global') NOT NULL DEFAULT 'page' AFTER page_url"
        );
    }
    $results[] = ['label' => '006 - trigger scope', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 006 failed: ' . $e->getMessage());
    $results[] = ['label' => '006 - trigger scope', 'ok' => false, 'msg' => $e->getMessage()];
}

// Migration 007: Per-event tracking configuration
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tracking_event_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            event_key VARCHAR(191) NOT NULL,
            source_event_name VARCHAR(80) NOT NULL,
            approved_event_name VARCHAR(80) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            selected_parameters JSON NOT NULL,
            updated_by_user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tracking_event_client_key (client_id, event_key),
            INDEX idx_tracking_event_client_enabled (client_id, enabled),
            FOREIGN KEY (client_id) REFERENCES clients(id),
            FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $results[] = ['label' => '007 - event tracking configuration', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 007 failed: ' . $e->getMessage());
    $results[] = ['label' => '007 - event tracking configuration', 'ok' => false, 'msg' => $e->getMessage()];
}

// Migration 008: Client tracking privacy policy
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tracking_privacy_settings (
            client_id INT UNSIGNED PRIMARY KEY,
            hashed_pii_enabled TINYINT(1) NOT NULL DEFAULT 0,
            allowed_hashed_fields JSON NOT NULL,
            updated_by_user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id),
            FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $results[] = ['label' => '008 - tracking privacy policy', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 008 failed: ' . $e->getMessage());
    $results[] = ['label' => '008 - tracking privacy policy', 'ok' => false, 'msg' => $e->getMessage()];
}

// Migration 009: Compatible trigger override per configured event
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute(['tracking_event_settings', 'trigger_intent']);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE tracking_event_settings
             ADD COLUMN trigger_intent ENUM('click','visibility') NULL AFTER selected_parameters"
        );
    }
    $results[] = ['label' => '009 - configurable compatible trigger', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 009 failed: ' . $e->getMessage());
    $results[] = ['label' => '009 - configurable compatible trigger', 'ok' => false, 'msg' => $e->getMessage()];
}

// Migration 010: Multi-destination event routing
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute(['tracking_event_settings', 'event_group']);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE tracking_event_settings ADD COLUMN event_group VARCHAR(40) NOT NULL DEFAULT 'interaction' AFTER approved_event_name");
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tracking_event_destinations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            event_key VARCHAR(191) NOT NULL,
            platform ENUM('ga4','posthog','meta','google_ads') NOT NULL,
            destination_event_name VARCHAR(120) NOT NULL,
            mapping_type VARCHAR(40) NOT NULL DEFAULT 'custom',
            settings JSON NOT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            updated_by_user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tracking_destination_event (client_id, event_key, platform),
            INDEX idx_tracking_destination_client_status (client_id, status),
            FOREIGN KEY (client_id) REFERENCES clients(id),
            FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $results[] = ['label' => '010 - multi-destination event routing', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 010 failed: ' . $e->getMessage());
    $results[] = ['label' => '010 - multi-destination event routing', 'ok' => false, 'msg' => $e->getMessage()];
}

// Migration 011: Client destination connections
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS destination_connections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            platform ENUM('ga4','posthog','meta','google_ads') NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            configuration JSON NOT NULL,
            encrypted_credentials TEXT NULL,
            status ENUM('not_configured','configured','verified','error') NOT NULL DEFAULT 'not_configured',
            last_verified_at DATETIME NULL,
            last_error_code VARCHAR(80) NULL,
            updated_by_user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_destination_connection_client_platform (client_id, platform),
            INDEX idx_destination_connection_client_status (client_id, status),
            FOREIGN KEY (client_id) REFERENCES clients(id),
            FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $results[] = ['label' => '011 - client destination connections', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 011 failed: ' . $e->getMessage());
    $results[] = ['label' => '011 - client destination connections', 'ok' => false, 'msg' => $e->getMessage()];
}

echo json_encode(['ok' => !in_array(false, array_column($results, 'ok'), true), 'results' => $results]);
