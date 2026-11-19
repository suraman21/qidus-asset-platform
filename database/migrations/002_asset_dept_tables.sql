-- ============================================================================
-- QIDUS ASSET Migration 002: Department 1 — Asset Dept Tables
-- Covers: Fixed/Stock/Reusable Assets, Variants/Sizes, QR, Movements,
--         2-Step Requests, Drawn+Biometric Handovers, Events/Stations,
--         Maintenance, Physical Inspections, Disposals
-- ============================================================================

CREATE TABLE IF NOT EXISTS `qa_asset_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `parent_id` INT UNSIGNED DEFAULT NULL,
    `code` VARCHAR(32) NOT NULL,
    `name_en` VARCHAR(120) NOT NULL,
    `name_am` VARCHAR(120) NOT NULL,
    `tracking_mode` ENUM('fixed','consumable','reusable_member') NOT NULL DEFAULT 'fixed',
    `qr_required` TINYINT(1) NOT NULL DEFAULT 1,
    `requires_return` TINYINT(1) NOT NULL DEFAULT 0,
    `field_schema_json` JSON DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_asset_cat_code` (`code`),
    CONSTRAINT `fk_qa_acat_parent` FOREIGN KEY (`parent_id`) REFERENCES `qa_asset_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_asset_locations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(32) NOT NULL,
    `name_en` VARCHAR(120) NOT NULL,
    `name_am` VARCHAR(120) NOT NULL,
    `building` VARCHAR(80) DEFAULT NULL,
    `room` VARCHAR(80) DEFAULT NULL,
    `custodian_user_id` INT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_loc_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_assets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asset_uuid` CHAR(36) NOT NULL,
    `asset_code` VARCHAR(40) NOT NULL,
    `qr_token` VARCHAR(96) NOT NULL,
    `name_en` VARCHAR(180) NOT NULL,
    `name_am` VARCHAR(180) DEFAULT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `tracking_mode` ENUM('fixed','consumable','reusable_member') NOT NULL DEFAULT 'fixed',
    `description` TEXT DEFAULT NULL,
    `serial_number` VARCHAR(120) DEFAULT NULL,
    `manufacturer` VARCHAR(120) DEFAULT NULL,
    `model` VARCHAR(120) DEFAULT NULL,
    `custom_fields_json` JSON DEFAULT NULL,
    `unit` VARCHAR(32) NOT NULL DEFAULT 'piece',
    `quantity` INT NOT NULL DEFAULT 1,
    `reserved_qty` INT NOT NULL DEFAULT 0,
    `min_quantity` INT NOT NULL DEFAULT 0,
    `location_id` INT UNSIGNED DEFAULT NULL,
    `department_id` INT UNSIGNED DEFAULT NULL,
    `custodian_user_id` INT UNSIGNED DEFAULT NULL,
    `acquisition_source` ENUM('purchase','donation','transfer','opening') NOT NULL DEFAULT 'purchase',
    `po_id` BIGINT UNSIGNED DEFAULT NULL,
    `acquisition_date` DATE DEFAULT NULL,
    `unit_cost` DECIMAL(14,2) DEFAULT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'ETB',
    `condition_status` ENUM('new','good','fair','poor','damaged','under_repair','disposed') NOT NULL DEFAULT 'good',
    `status` ENUM('in_stock','assigned','low_stock','out_of_stock','maintenance','retired','archived') NOT NULL DEFAULT 'in_stock',
    `primary_document_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_asset_uuid` (`asset_uuid`),
    UNIQUE KEY `uk_qa_asset_code` (`asset_code`),
    UNIQUE KEY `uk_qa_asset_qr` (`qr_token`),
    KEY `idx_qa_asset_mode_status` (`tracking_mode`, `status`),
    KEY `idx_qa_asset_cat` (`category_id`),
    KEY `idx_qa_asset_loc` (`location_id`),
    CONSTRAINT `fk_qa_asset_cat` FOREIGN KEY (`category_id`) REFERENCES `qa_asset_categories` (`id`),
    CONSTRAINT `fk_qa_asset_loc` FOREIGN KEY (`location_id`) REFERENCES `qa_asset_locations` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_qa_asset_dept` FOREIGN KEY (`department_id`) REFERENCES `qa_departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clothing/uniform sizes & item variants for reusable member-issued or stocked items
CREATE TABLE IF NOT EXISTS `qa_asset_variants` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asset_id` BIGINT UNSIGNED NOT NULL,
    `variant_code` VARCHAR(48) NOT NULL,
    `size_label` VARCHAR(32) NOT NULL,
    `color_or_style` VARCHAR(60) DEFAULT NULL,
    `location_id` INT UNSIGNED DEFAULT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `reserved_qty` INT NOT NULL DEFAULT 0,
    `min_quantity` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_variant_code` (`asset_id`, `variant_code`),
    CONSTRAINT `fk_qa_var_asset` FOREIGN KEY (`asset_id`) REFERENCES `qa_assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_asset_qr_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asset_id` BIGINT UNSIGNED NOT NULL,
    `qr_token` VARCHAR(96) NOT NULL,
    `status` ENUM('active','revoked') NOT NULL DEFAULT 'active',
    `issued_by` INT UNSIGNED DEFAULT NULL,
    `revoked_by` INT UNSIGNED DEFAULT NULL,
    `revoked_reason` VARCHAR(255) DEFAULT NULL,
    `issued_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revoked_at` DATETIME DEFAULT NULL,
    UNIQUE KEY `uk_qa_qrh_token` (`qr_token`),
    KEY `idx_qa_qrh_asset` (`asset_id`, `status`),
    CONSTRAINT `fk_qa_qrh_asset` FOREIGN KEY (`asset_id`) REFERENCES `qa_assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Traceable stock & asset movement ledger (balances never overwritten silently)
CREATE TABLE IF NOT EXISTS `qa_asset_movements` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asset_id` BIGINT UNSIGNED NOT NULL,
    `variant_id` BIGINT UNSIGNED DEFAULT NULL,
    `operational_year_id` INT UNSIGNED DEFAULT NULL,
    `movement_type` ENUM('incoming','outgoing','transfer','issue','return','adjustment','maintenance_out','maintenance_in','disposal') NOT NULL,
    `quantity_delta` INT NOT NULL,
    `balance_after` INT NOT NULL,
    `from_location_id` INT UNSIGNED DEFAULT NULL,
    `to_location_id` INT UNSIGNED DEFAULT NULL,
    `reference_type` VARCHAR(40) DEFAULT NULL,
    `reference_id` BIGINT UNSIGNED DEFAULT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `performed_by` INT UNSIGNED DEFAULT NULL,
    `device_uuid` VARCHAR(64) DEFAULT NULL,
    `movement_date` DATE NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_qa_mov_asset` (`asset_id`, `created_at`),
    KEY `idx_qa_mov_year` (`operational_year_id`),
    CONSTRAINT `fk_qa_mov_asset` FOREIGN KEY (`asset_id`) REFERENCES `qa_assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Two-step Asset Request Workflow: PENDING_DEPARTMENT_HEAD -> PENDING_DEPUTY_CHAIRPERSON -> APPROVED/REJECTED
CREATE TABLE IF NOT EXISTS `qa_asset_requests` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_number` VARCHAR(32) NOT NULL,
    `operational_year_id` INT UNSIGNED DEFAULT NULL,
    `requester_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED NOT NULL,
    `asset_id` BIGINT UNSIGNED DEFAULT NULL,
    `variant_id` BIGINT UNSIGNED DEFAULT NULL,
    `requested_item_name` VARCHAR(180) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `urgency` ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `needed_by_date` DATE DEFAULT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM(
        'PENDING_DEPARTMENT_HEAD',
        'PENDING_DEPUTY_CHAIRPERSON',
        'RETURNED_FOR_CORRECTION',
        'APPROVED',
        'REJECTED',
        'CANCELLED',
        'FULFILLED'
    ) NOT NULL DEFAULT 'PENDING_DEPARTMENT_HEAD',
    `dept_head_id` INT UNSIGNED DEFAULT NULL,
    `dept_head_decision_at` DATETIME DEFAULT NULL,
    `dept_head_note` VARCHAR(500) DEFAULT NULL,
    `deputy_id` INT UNSIGNED DEFAULT NULL,
    `deputy_decision_at` DATETIME DEFAULT NULL,
    `deputy_note` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_areq_num` (`request_number`),
    KEY `idx_qa_areq_status` (`status`, `department_id`),
    CONSTRAINT `fk_qa_areq_req` FOREIGN KEY (`requester_id`) REFERENCES `qa_users` (`id`),
    CONSTRAINT `fk_qa_areq_dept` FOREIGN KEY (`department_id`) REFERENCES `qa_departments` (`id`),
    CONSTRAINT `fk_qa_areq_asset` FOREIGN KEY (`asset_id`) REFERENCES `qa_assets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custody handovers with drawn signatures + Android Keystore cryptographic signature evidence
CREATE TABLE IF NOT EXISTS `qa_asset_handovers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `handover_number` VARCHAR(32) NOT NULL,
    `operational_year_id` INT UNSIGNED DEFAULT NULL,
    `asset_request_id` BIGINT UNSIGNED DEFAULT NULL,
    `asset_id` BIGINT UNSIGNED NOT NULL,
    `variant_id` BIGINT UNSIGNED DEFAULT NULL,
    `handover_type` ENUM('issue','return','partial_return','transfer') NOT NULL DEFAULT 'issue',
    `sender_user_id` INT UNSIGNED NOT NULL,
    `receiver_user_id` INT UNSIGNED DEFAULT NULL,
    `member_cache_id` INT UNSIGNED DEFAULT NULL,
    `receiver_display_name` VARCHAR(150) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `condition_at_handover` ENUM('new','good','fair','poor','damaged') NOT NULL DEFAULT 'good',
    `expected_return_date` DATE DEFAULT NULL,
    `sender_sig_path` VARCHAR(255) DEFAULT NULL,
    `receiver_sig_path` VARCHAR(255) DEFAULT NULL,
    `sender_sig_sha256` CHAR(64) DEFAULT NULL,
    `receiver_sig_sha256` CHAR(64) DEFAULT NULL,
    `signed_by_operator_for_recipient` TINYINT(1) NOT NULL DEFAULT 0,
    `biometric_key_id` INT UNSIGNED DEFAULT NULL,
    `biometric_sig_b64` TEXT DEFAULT NULL,
    `payload_sha256` CHAR(64) DEFAULT NULL,
    `verification_method` ENUM('drawn_signature','biometric_keystore','pin_fallback','both') NOT NULL DEFAULT 'drawn_signature',
    `client_tx_uuid` CHAR(36) DEFAULT NULL,
    `device_tx_at` DATETIME DEFAULT NULL,
    `server_received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes` VARCHAR(500) DEFAULT NULL,
    UNIQUE KEY `uk_qa_hnd_num` (`handover_number`),
    UNIQUE KEY `uk_qa_hnd_client_uuid` (`client_tx_uuid`),
    KEY `idx_qa_hnd_asset` (`asset_id`, `handover_type`),
    CONSTRAINT `fk_qa_hnd_asset` FOREIGN KEY (`asset_id`) REFERENCES `qa_assets` (`id`),
    CONSTRAINT `fk_qa_hnd_req` FOREIGN KEY (`asset_request_id`) REFERENCES `qa_asset_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rapid Event-Based Member Issue & Return (FR-15, FR-16)
CREATE TABLE IF NOT EXISTS `qa_event_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name_en` VARCHAR(150) NOT NULL,
    `name_am` VARCHAR(150) DEFAULT NULL,
    `default_items_json` JSON NOT NULL,
    `default_filter_json` JSON DEFAULT NULL,
    `default_return_days` SMALLINT UNSIGNED DEFAULT 7,
    `rules_json` JSON DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_code` VARCHAR(32) NOT NULL,
    `template_id` INT UNSIGNED DEFAULT NULL,
    `operational_year_id` INT UNSIGNED DEFAULT NULL,
    `name_en` VARCHAR(180) NOT NULL,
    `name_am` VARCHAR(180) DEFAULT NULL,
    `event_date` DATE NOT NULL,
    `expected_return_date` DATE DEFAULT NULL,
    `status` ENUM('draft','prepared','active','reconciling','closed') NOT NULL DEFAULT 'draft',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_event_code` (`event_code`),
    KEY `idx_qa_event_status` (`status`, `operational_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_event_stations` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_id` BIGINT UNSIGNED NOT NULL,
    `station_code` VARCHAR(32) NOT NULL,
    `station_name` VARCHAR(120) NOT NULL,
    `operator_user_id` INT UNSIGNED DEFAULT NULL,
    `device_uuid` VARCHAR(64) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY `uk_qa_station_code` (`event_id`, `station_code`),
    CONSTRAINT `fk_qa_st_event` FOREIGN KEY (`event_id`) REFERENCES `qa_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_event_allocations` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_id` BIGINT UNSIGNED NOT NULL,
    `station_id` BIGINT UNSIGNED NOT NULL,
    `asset_id` BIGINT UNSIGNED NOT NULL,
    `variant_id` BIGINT UNSIGNED DEFAULT NULL,
    `allocated_qty` INT NOT NULL DEFAULT 0,
    `issued_qty` INT NOT NULL DEFAULT 0,
    `returned_qty` INT NOT NULL DEFAULT 0,
    CONSTRAINT `fk_qa_alloc_station` FOREIGN KEY (`station_id`) REFERENCES `qa_event_stations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qa_alloc_asset` FOREIGN KEY (`asset_id`) REFERENCES `qa_assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_event_participants` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_id` BIGINT UNSIGNED NOT NULL,
    `member_cache_id` INT UNSIGNED NOT NULL,
    `prepared_items_json` JSON DEFAULT NULL,
    `eligibility_source` ENUM('filter','manual') NOT NULL DEFAULT 'filter',
    UNIQUE KEY `uk_qa_ev_part` (`event_id`, `member_cache_id`),
    CONSTRAINT `fk_qa_ep_event` FOREIGN KEY (`event_id`) REFERENCES `qa_events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qa_ep_member` FOREIGN KEY (`member_cache_id`) REFERENCES `qa_member_sync_cache` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_event_transactions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `client_tx_uuid` CHAR(36) NOT NULL,
    `event_id` BIGINT UNSIGNED NOT NULL,
    `station_id` BIGINT UNSIGNED NOT NULL,
    `member_cache_id` INT UNSIGNED NOT NULL,
    `asset_id` BIGINT UNSIGNED NOT NULL,
    `variant_id` BIGINT UNSIGNED DEFAULT NULL,
    `tx_type` ENUM('issue','return','partial_return','damaged','missing') NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `condition_note` VARCHAR(255) DEFAULT NULL,
    `operator_user_id` INT UNSIGNED NOT NULL,
    `verification_method` ENUM('drawn_signature','biometric_keystore','pin_fallback','operator_confirm') NOT NULL DEFAULT 'operator_confirm',
    `biometric_key_id` INT UNSIGNED DEFAULT NULL,
    `biometric_sig_b64` TEXT DEFAULT NULL,
    `receiver_sig_path` VARCHAR(255) DEFAULT NULL,
    `conflict_status` ENUM('clean','needs_review','resolved') NOT NULL DEFAULT 'clean',
    `device_tx_at` DATETIME NOT NULL,
    `server_received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_evtx_uuid` (`client_tx_uuid`),
    KEY `idx_qa_evtx_member` (`event_id`, `member_cache_id`),
    CONSTRAINT `fk_qa_evtx_event` FOREIGN KEY (`event_id`) REFERENCES `qa_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maintenance & Repairs (FR-08)
CREATE TABLE IF NOT EXISTS `qa_asset_maintenance` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_number` VARCHAR(32) NOT NULL,
    `asset_id` BIGINT UNSIGNED NOT NULL,
    `operational_year_id` INT UNSIGNED DEFAULT NULL,
    `maintenance_type` ENUM('preventive','corrective') NOT NULL DEFAULT 'corrective',
    `priority` ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `issue_description` TEXT NOT NULL,
    `provider_name` VARCHAR(150) DEFAULT NULL,
    `scheduled_date` DATE DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `work_performed` TEXT DEFAULT NULL,
    `parts_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `service_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `warranty_covered` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    `document_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_maint_num` (`ticket_number`),
    CONSTRAINT `fk_qa_maint_asset` FOREIGN KEY (`asset_id`) REFERENCES `qa_assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Physical Inspection Campaigns (FR-09)
CREATE TABLE IF NOT EXISTS `qa_asset_inspections` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `campaign_code` VARCHAR(32) NOT NULL,
    `operational_year_id` INT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(180) NOT NULL,
    `location_id` INT UNSIGNED DEFAULT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `lead_inspector_id` INT UNSIGNED NOT NULL,
    `status` ENUM('open','in_progress','submitted','closed') NOT NULL DEFAULT 'open',
    `started_at` DATETIME DEFAULT NULL,
    `closed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_insp_code` (`campaign_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_asset_inspection_items` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `inspection_id` BIGINT UNSIGNED NOT NULL,
    `asset_id` BIGINT UNSIGNED DEFAULT NULL,
    `scanned_qr_token` VARCHAR(96) DEFAULT NULL,
    `outcome` ENUM('inspected','not_found','relocated','damaged','duplicated','unregistered') NOT NULL,
    `system_qty` INT NOT NULL DEFAULT 0,
    `counted_qty` INT NOT NULL DEFAULT 0,
    `variance_qty` INT NOT NULL DEFAULT 0,
    `note` VARCHAR(500) DEFAULT NULL,
    `inspector_user_id` INT UNSIGNED NOT NULL,
    `scanned_at` DATETIME NOT NULL,
    CONSTRAINT `fk_qa_inspi_parent` FOREIGN KEY (`inspection_id`) REFERENCES `qa_asset_inspections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controlled Asset Disposal & Retirement (FR-10)
CREATE TABLE IF NOT EXISTS `qa_asset_disposals` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `disposal_number` VARCHAR(32) NOT NULL,
    `asset_id` BIGINT UNSIGNED NOT NULL,
    `operational_year_id` INT UNSIGNED DEFAULT NULL,
    `reason_type` ENUM('lost','stolen','destroyed','damaged_beyond_repair','sold','donated','retired') NOT NULL,
    `reason_detail` TEXT NOT NULL,
    `proceeds_amount` DECIMAL(14,2) DEFAULT NULL,
    `recipient_name` VARCHAR(150) DEFAULT NULL,
    `override_open_issues` TINYINT(1) NOT NULL DEFAULT 0,
    `override_reason` VARCHAR(500) DEFAULT NULL,
    `status` ENUM('PENDING_APPROVAL','APPROVED','REJECTED','COMPLETED') NOT NULL DEFAULT 'PENDING_APPROVAL',
    `requested_by` INT UNSIGNED NOT NULL,
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `disposal_date` DATE DEFAULT NULL,
    `document_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_disp_num` (`disposal_number`),
    CONSTRAINT `fk_qa_disp_asset` FOREIGN KEY (`asset_id`) REFERENCES `qa_assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
