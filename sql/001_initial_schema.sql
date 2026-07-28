-- GTM Auditor — Full Database Schema
-- Run once against a fresh `gtm_auditor` database.
-- Matches all tables defined in the design spec §2.

-- MySQL 5.7 compatibility: enable large prefix for utf8mb4 indexes
SET GLOBAL innodb_large_prefix = ON;
SET GLOBAL innodb_file_format = Barracuda;
SET GLOBAL innodb_file_per_table = ON;

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ─── Plans ────────────────────────────────────────────────────────────────────
CREATE TABLE plans (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(50)  NOT NULL,
    price_monthly           DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    audit_credits_monthly   INT          NOT NULL DEFAULT 5,
    has_crawl               TINYINT(1)   NOT NULL DEFAULT 0,
    has_bigquery            TINYINT(1)   NOT NULL DEFAULT 0,
    has_realtime_monitoring TINYINT(1)   NOT NULL DEFAULT 0,
    has_developer_notes     TINYINT(1)   NOT NULL DEFAULT 0,
    has_whitelabel          TINYINT(1)   NOT NULL DEFAULT 0,
    has_client_workspaces   TINYINT(1)   NOT NULL DEFAULT 0,
    max_websites            INT          NOT NULL DEFAULT 1,
    max_clients             INT          NOT NULL DEFAULT 1,
    monitoring_frequency    ENUM('manual','weekly','daily','hourly') NOT NULL DEFAULT 'manual',
    stripe_price_id         VARCHAR(100),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the four pricing tiers from the design spec
INSERT INTO plans (id, name, price_monthly, audit_credits_monthly, has_crawl, has_bigquery,
    has_realtime_monitoring, has_developer_notes, has_whitelabel, has_client_workspaces,
    max_websites, max_clients, monitoring_frequency) VALUES
(1, 'Starter',  49.00,   5,   0, 0, 0, 0, 0, 0,  1,  1, 'manual'),
(2, 'Growth',   99.00,   20,  1, 0, 0, 0, 0, 0,  3,  1, 'weekly'),
(3, 'Pro',      249.00,  100, 1, 1, 1, 1, 0, 0,  10, 1, 'daily'),
(4, 'Agency',   599.00,  -1,  1, 1, 1, 1, 1, 1, -1, -1, 'hourly');
-- Note: -1 = unlimited (enforced in PHP via plan_limit() helper)

-- ─── Accounts ─────────────────────────────────────────────────────────────────
CREATE TABLE accounts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150) NOT NULL,
    type          ENUM('agency','standalone') NOT NULL DEFAULT 'standalone',
    plan_id       INT UNSIGNED NOT NULL DEFAULT 1,
    owner_user_id INT UNSIGNED,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Users ────────────────────────────────────────────────────────────────────
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id    INT UNSIGNED NOT NULL,
    email         VARCHAR(255) NOT NULL,
    name          VARCHAR(150) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    role          ENUM('owner','admin','member','viewer') NOT NULL DEFAULT 'member',
    google_sub    VARCHAR(100),
    email_verified TINYINT(1)  NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email (email(191)),
    FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ─── Clients ──────────────────────────────────────────────────────────────────
CREATE TABLE clients (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    name       VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Google Connections ────────────────────────────────────────────────────────
CREATE TABLE google_connections (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id      INT UNSIGNED NOT NULL,
    google_email    VARCHAR(255) NOT NULL DEFAULT '',
    google_sub      VARCHAR(100) NOT NULL DEFAULT '',
    refresh_token   TEXT         NOT NULL COMMENT 'AES-256-GCM encrypted, base64-encoded',
    scopes          JSON,
    token_expires_at DATETIME,
    connected_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_account_google (account_id, google_sub(100)),
    FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ─── GTM Containers ───────────────────────────────────────────────────────────
CREATE TABLE gtm_containers (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id            INT UNSIGNED NOT NULL,
    google_connection_id INT UNSIGNED NOT NULL,
    gtm_account_id       VARCHAR(50)  NOT NULL,
    container_id         VARCHAR(50)  NOT NULL,
    gtm_public_id        VARCHAR(20)  NOT NULL COMMENT 'GTM-XXXXX',
    name                 VARCHAR(255) NOT NULL DEFAULT '',
    last_synced_at       DATETIME,
    FOREIGN KEY (client_id)            REFERENCES clients(id),
    FOREIGN KEY (google_connection_id) REFERENCES google_connections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── GA4 Properties ───────────────────────────────────────────────────────────
CREATE TABLE ga4_properties (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id            INT UNSIGNED NOT NULL,
    google_connection_id INT UNSIGNED NOT NULL,
    property_id          VARCHAR(20)  NOT NULL,
    display_name         VARCHAR(255) NOT NULL DEFAULT '',
    last_synced_at       DATETIME,
    FOREIGN KEY (client_id)            REFERENCES clients(id),
    FOREIGN KEY (google_connection_id) REFERENCES google_connections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── GA4 Streams ──────────────────────────────────────────────────────────────
CREATE TABLE ga4_streams (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ga4_property_id INT UNSIGNED NOT NULL,
    stream_id       VARCHAR(30)  NOT NULL,
    measurement_id  VARCHAR(20)  NOT NULL COMMENT 'G-XXXXXXXX',
    stream_url      VARCHAR(500),
    FOREIGN KEY (ga4_property_id) REFERENCES ga4_properties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── BigQuery Connections ─────────────────────────────────────────────────────
CREATE TABLE bigquery_connections (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ga4_property_id INT UNSIGNED NOT NULL,
    gcp_project_id  VARCHAR(100) NOT NULL,
    dataset_id      VARCHAR(100) NOT NULL,
    status          ENUM('discovered','connected','error') NOT NULL DEFAULT 'discovered',
    discovered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ga4_property_id) REFERENCES ga4_properties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Domains ──────────────────────────────────────────────────────────────────
CREATE TABLE domains (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id         INT UNSIGNED NOT NULL,
    url               VARCHAR(500) NOT NULL,
    name              VARCHAR(255) NOT NULL DEFAULT '',
    verified          TINYINT(1)   NOT NULL DEFAULT 0,
    verified_by_user_id INT UNSIGNED,
    verified_at       DATETIME,
    is_yours          TINYINT(1) COMMENT '1=yes, 0=no, NULL=not answered',
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE domain_containers (
    domain_id    INT UNSIGNED NOT NULL,
    container_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (domain_id, container_id),
    FOREIGN KEY (domain_id)    REFERENCES domains(id),
    FOREIGN KEY (container_id) REFERENCES gtm_containers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE domain_ga4_properties (
    domain_id    INT UNSIGNED NOT NULL,
    property_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (domain_id, property_id),
    FOREIGN KEY (domain_id)   REFERENCES domains(id),
    FOREIGN KEY (property_id) REFERENCES ga4_properties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cross_domain_configs (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id         INT UNSIGNED NOT NULL,
    primary_domain_id INT UNSIGNED NOT NULL,
    linked_domain_id  INT UNSIGNED NOT NULL,
    linker_enabled    TINYINT(1)   NOT NULL DEFAULT 0,
    domains_configured JSON,
    verified          TINYINT(1)   NOT NULL DEFAULT 0,
    last_verified_at  DATETIME,
    FOREIGN KEY (client_id)         REFERENCES clients(id),
    FOREIGN KEY (primary_domain_id) REFERENCES domains(id),
    FOREIGN KEY (linked_domain_id)  REFERENCES domains(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Audit Jobs ───────────────────────────────────────────────────────────────
CREATE TABLE audit_jobs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id     INT UNSIGNED NOT NULL,
    triggered_by  ENUM('manual','scheduled') NOT NULL DEFAULT 'manual',
    status        ENUM('queued','running','complete','failed') NOT NULL DEFAULT 'queued',
    data_sources  JSON COMMENT 'Which APIs were used: {gtm_api, ga4_api, bigquery, crawl}',
    credits_used  INT NOT NULL DEFAULT 0,
    health_score  TINYINT UNSIGNED COMMENT '0–100 composite score',
    error_message VARCHAR(512),
    started_at    DATETIME,
    completed_at  DATETIME,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_status (client_id, status),
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Audit Findings ───────────────────────────────────────────────────────────
CREATE TABLE audit_findings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_job_id    INT UNSIGNED NOT NULL,
    category        ENUM('container_health','trigger_quality','data_quality','implementation') NOT NULL,
    severity        ENUM('critical','warning','info') NOT NULL,
    title           VARCHAR(255) NOT NULL,
    description     TEXT         NOT NULL COMMENT 'Marketer-readable plain language',
    recommendation  TEXT         NOT NULL COMMENT 'What to do to fix it',
    developer_notes TEXT                  COMMENT 'Technical fix instructions (Pro+ only)',
    fix_owner       ENUM('gtm','developer','ga4_admin','hosting','multiple') NOT NULL,
    affected_item   VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Tag name, domain, trigger name, etc.',
    evidence        JSON COMMENT 'Raw API snippet or BQ row count supporting this finding',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_severity (audit_job_id, severity),
    FOREIGN KEY (audit_job_id) REFERENCES audit_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Crawl Results (Phase 2) ──────────────────────────────────────────────────
CREATE TABLE crawl_results (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_job_id     INT UNSIGNED NOT NULL,
    page_url         VARCHAR(500) NOT NULL,
    tags_fired       JSON,
    network_requests JSON,
    redirect_chain   JSON,
    errors           JSON,
    crawled_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (audit_job_id) REFERENCES audit_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Monitoring ───────────────────────────────────────────────────────────────
CREATE TABLE monitoring_schedules (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id   INT UNSIGNED NOT NULL,
    frequency   ENUM('weekly','daily','hourly') NOT NULL DEFAULT 'weekly',
    last_run_at DATETIME,
    next_run_at DATETIME,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE monitoring_alerts (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id                INT UNSIGNED NOT NULL,
    audit_job_id             INT UNSIGNED NOT NULL,
    channel                  ENUM('email','webhook','slack') NOT NULL,
    finding_severity_threshold ENUM('critical','warning','info') NOT NULL DEFAULT 'critical',
    sent_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id)    REFERENCES clients(id),
    FOREIGN KEY (audit_job_id) REFERENCES audit_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Credit Usage Tracking ────────────────────────────────────────────────────
CREATE TABLE credit_usage (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id   INT UNSIGNED NOT NULL,
    month        CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
    credits_used INT          NOT NULL DEFAULT 0,
    UNIQUE KEY uq_account_month (account_id, month),
    FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Rate Limits ──────────────────────────────────────────────────────────────
CREATE TABLE rate_limits (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action       VARCHAR(50)  NOT NULL,
    identifier   VARCHAR(100) NOT NULL COMMENT 'IP address or user ID',
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action_identifier_time (action, identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
