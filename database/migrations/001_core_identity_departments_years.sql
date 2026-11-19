-- ============================================================================
-- QIDUS ASSET Migration 001: Core Identity, Departments, Years, Devices & Audit
-- Engine: InnoDB | Charset: utf8mb4_unicode_ci
-- ============================================================================

CREATE TABLE IF NOT EXISTS `qa_schema_migrations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `migration` VARCHAR(150) NOT NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_install_config` (
    `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    `school_name_en` VARCHAR(160) NOT NULL DEFAULT 'Sunday School',
    `school_name_am` VARCHAR(160) NOT NULL DEFAULT 'ሰንበት ትምህርት ቤት',
    `school_short_name` VARCHAR(40) NOT NULL DEFAULT 'QIDUS',
    `logo_path` VARCHAR(255) DEFAULT NULL,
    `integration_mode` ENUM('standalone','ssms_direct','ssms_http') NOT NULL DEFAULT 'standalone',
    `calendar_mode` ENUM('ethiopian','gregorian','dual') NOT NULL DEFAULT 'dual',
    `currency_code` VARCHAR(10) NOT NULL DEFAULT 'ETB',
    `asset_code_prefix` VARCHAR(20) NOT NULL DEFAULT 'AST',
    `policy_json` JSON DEFAULT NULL,
    `installed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_operational_years` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `external_year_id` INT UNSIGNED DEFAULT NULL,
    `ec_year` SMALLINT UNSIGNED NOT NULL,
    `label_en` VARCHAR(80) NOT NULL,
    `label_am` VARCHAR(80) NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `status` ENUM('active','closed','reopened') NOT NULL DEFAULT 'active',
    `closed_by` INT UNSIGNED DEFAULT NULL,
    `closed_at` DATETIME DEFAULT NULL,
    `reopened_by` INT UNSIGNED DEFAULT NULL,
    `reopened_at` DATETIME DEFAULT NULL,
    `reopen_reason` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_ec_year` (`ec_year`),
    KEY `idx_qa_year_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_departments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(40) NOT NULL,
    `name_en` VARCHAR(120) NOT NULL,
    `name_am` VARCHAR(120) NOT NULL,
    `parent_dept_code` VARCHAR(40) DEFAULT NULL,
    `head_user_id` INT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_dept_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source_system` VARCHAR(32) NOT NULL DEFAULT 'standalone',
    `external_user_id` VARCHAR(64) DEFAULT NULL,
    `username` VARCHAR(80) NOT NULL,
    `email` VARCHAR(160) DEFAULT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `full_name_am` VARCHAR(150) DEFAULT NULL,
    `password_hash` VARCHAR(255) DEFAULT NULL,
    `qa_role` ENUM(
        'system_admin',
        'chairperson',
        'deputy_chairperson',
        'asset_admin',
        'asset_head',
        'asset_officer',
        'event_operator',
        'maintenance_officer',
        'finance_head',
        'finance_staff',
        'procurement_officer',
        'document_controller',
        'auditor',
        'requester'
    ) NOT NULL DEFAULT 'requester',
    `department_id` INT UNSIGNED DEFAULT NULL,
    `approval_limit` DECIMAL(14,2) DEFAULT NULL,
    `auth_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_username` (`username`),
    UNIQUE KEY `uk_qa_source_ext_user` (`source_system`, `external_user_id`),
    KEY `idx_qa_user_role` (`qa_role`, `is_active`),
    CONSTRAINT `fk_qa_users_dept` FOREIGN KEY (`department_id`) REFERENCES `qa_departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_auth_refresh_tokens` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `family_id` CHAR(64) NOT NULL,
    `session_id` CHAR(64) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `device_label` VARCHAR(120) DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME DEFAULT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_refresh_session` (`session_id`),
    KEY `idx_qa_refresh_user_family` (`user_id`, `family_id`),
    CONSTRAINT `fk_qa_refresh_user` FOREIGN KEY (`user_id`) REFERENCES `qa_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Android Keystore biometric-protected public keys (NEVER stores raw fingerprints/templates)
CREATE TABLE IF NOT EXISTS `qa_device_keys` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `device_uuid` VARCHAR(64) NOT NULL,
    `device_label` VARCHAR(120) NOT NULL,
    `key_algorithm` ENUM('EC_P256','RSA_2048') NOT NULL DEFAULT 'EC_P256',
    `public_key_pem` TEXT NOT NULL,
    `biometric_bound` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `revoked_by` INT UNSIGNED DEFAULT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `revoked_reason` VARCHAR(255) DEFAULT NULL,
    `last_used_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_user_device` (`user_id`, `device_uuid`),
    KEY `idx_qa_device_status` (`status`),
    CONSTRAINT `fk_qa_dk_user` FOREIGN KEY (`user_id`) REFERENCES `qa_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_offline_leases` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `device_uuid` VARCHAR(64) NOT NULL,
    `lease_jti` CHAR(64) NOT NULL,
    `scope_json` JSON NOT NULL,
    `issued_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    UNIQUE KEY `uk_qa_lease_jti` (`lease_jti`),
    KEY `idx_qa_lease_user_dev` (`user_id`, `device_uuid`, `expires_at`),
    CONSTRAINT `fk_qa_lease_user` FOREIGN KEY (`user_id`) REFERENCES `qa_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Authoritative external member connector cache (SSMS or standalone payers)
CREATE TABLE IF NOT EXISTS `qa_member_sync_cache` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source_system` VARCHAR(32) NOT NULL DEFAULT 'ssms',
    `external_member_id` VARCHAR(64) NOT NULL,
    `member_code` VARCHAR(40) NOT NULL DEFAULT '',
    `qr_token_ref` VARCHAR(128) DEFAULT NULL,
    `name_en` VARCHAR(180) NOT NULL,
    `name_am` VARCHAR(180) DEFAULT NULL,
    `gender` ENUM('male','female','other') DEFAULT NULL,
    `department_name` VARCHAR(120) DEFAULT NULL,
    `class_name` VARCHAR(120) DEFAULT NULL,
    `section_name` VARCHAR(120) DEFAULT NULL,
    `group_names` VARCHAR(255) DEFAULT NULL,
    `photo_url` VARCHAR(255) DEFAULT NULL,
    `source_status` ENUM('active','inactive','archived','waiting','warning') NOT NULL DEFAULT 'active',
    `is_tombstoned` TINYINT(1) NOT NULL DEFAULT 0,
    `last_sync_revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_member_ext` (`source_system`, `external_member_id`),
    KEY `idx_qa_member_code` (`member_code`),
    KEY `idx_qa_member_qr` (`qr_token_ref`),
    KEY `idx_qa_member_status` (`source_status`, `is_tombstoned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_member_asset_profiles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_cache_id` INT UNSIGNED NOT NULL,
    `clothing_size` VARCHAR(24) DEFAULT NULL,
    `shoe_size` VARCHAR(24) DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_member_profile` (`member_cache_id`),
    CONSTRAINT `fk_qa_map_member` FOREIGN KEY (`member_cache_id`) REFERENCES `qa_member_sync_cache` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_notifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recipient_user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `message` TEXT NOT NULL,
    `type` VARCHAR(50) NOT NULL DEFAULT 'info',
    `entity_type` VARCHAR(50) DEFAULT NULL,
    `entity_id` BIGINT UNSIGNED DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_qa_notif_recipient` (`recipient_user_id`, `is_read`, `created_at`),
    CONSTRAINT `fk_qa_notif_user` FOREIGN KEY (`recipient_user_id`) REFERENCES `qa_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only immutable audit log
CREATE TABLE IF NOT EXISTS `qa_audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `actor_user_id` INT UNSIGNED DEFAULT NULL,
    `actor_role` VARCHAR(40) NOT NULL,
    `impersonated_by_user_id` INT UNSIGNED DEFAULT NULL,
    `department_id` INT UNSIGNED DEFAULT NULL,
    `device_uuid` VARCHAR(64) DEFAULT NULL,
    `device_key_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(80) NOT NULL,
    `entity_type` VARCHAR(60) NOT NULL,
    `entity_id` VARCHAR(64) DEFAULT NULL,
    `before_json` JSON DEFAULT NULL,
    `after_json` JSON DEFAULT NULL,
    `evidence_sha256` CHAR(64) DEFAULT NULL,
    `outcome` ENUM('accepted','rejected','conflict','correction','failed') NOT NULL DEFAULT 'accepted',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `device_at` DATETIME DEFAULT NULL,
    `server_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_qa_audit_entity` (`entity_type`, `entity_id`),
    KEY `idx_qa_audit_actor` (`actor_user_id`, `server_at`),
    KEY `idx_qa_audit_action` (`action`, `server_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_audit_findings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `finding_code` VARCHAR(32) NOT NULL,
    `auditor_user_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED DEFAULT NULL,
    `severity` ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `recommendation` TEXT DEFAULT NULL,
    `linked_entity_type` VARCHAR(60) DEFAULT NULL,
    `linked_entity_id` VARCHAR(64) DEFAULT NULL,
    `assigned_to_user_id` INT UNSIGNED DEFAULT NULL,
    `due_date` DATE DEFAULT NULL,
    `status` ENUM('open','in_progress','resolved','verified') NOT NULL DEFAULT 'open',
    `management_response` TEXT DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `verified_by` INT UNSIGNED DEFAULT NULL,
    `verified_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_finding_code` (`finding_code`),
    KEY `idx_qa_finding_status` (`status`, `severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_api_idempotency` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `idempotency_key` VARCHAR(100) NOT NULL,
    `endpoint` VARCHAR(120) NOT NULL,
    `request_hash` CHAR(64) NOT NULL,
    `response_code` SMALLINT UNSIGNED NOT NULL,
    `response_json` MEDIUMTEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_idem` (`user_id`, `idempotency_key`, `endpoint`),
    KEY `idx_qa_idem_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
