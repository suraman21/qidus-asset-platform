-- ============================================================================
-- QIDUS ASSET Migration 003: Department 2 — Finance, Procurement & Documents
-- Covers: Annual Budgets & Reallocations, Append-Only Income/Expense Ledger,
--         Bank & Petty Cash + Reconciliation, Monthly Member Contributions,
--         Procurement (Stock & Budget Gates, Quotations, POs, GRN),
--         Controlled Document Vault + Versioning + Access Logs
-- ============================================================================

CREATE TABLE IF NOT EXISTS `qa_fin_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(32) NOT NULL,
    `name_en` VARCHAR(120) NOT NULL,
    `name_am` VARCHAR(120) NOT NULL,
    `type` ENUM('income','expense') NOT NULL,
    `parent_id` INT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_fcat_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_fin_accounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `account_code` VARCHAR(32) NOT NULL,
    `name_en` VARCHAR(120) NOT NULL,
    `name_am` VARCHAR(120) DEFAULT NULL,
    `account_type` ENUM('bank','petty_cash','mobile_money') NOT NULL DEFAULT 'bank',
    `bank_name` VARCHAR(120) DEFAULT NULL,
    `branch_name` VARCHAR(120) DEFAULT NULL,
    `account_number_masked` VARCHAR(40) DEFAULT NULL,
    `custodian_user_id` INT UNSIGNED DEFAULT NULL,
    `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `current_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_fin_acc_code` (`account_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_fin_budgets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `operational_year_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `line_code` VARCHAR(40) NOT NULL,
    `title_en` VARCHAR(160) NOT NULL,
    `title_am` VARCHAR(160) DEFAULT NULL,
    `approved_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `amended_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `committed_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `spent_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft','approved','locked','closed') NOT NULL DEFAULT 'draft',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_budget_line` (`operational_year_id`, `department_id`, `category_id`, `line_code`),
    CONSTRAINT `fk_qa_bud_year` FOREIGN KEY (`operational_year_id`) REFERENCES `qa_operational_years` (`id`),
    CONSTRAINT `fk_qa_bud_dept` FOREIGN KEY (`department_id`) REFERENCES `qa_departments` (`id`),
    CONSTRAINT `fk_qa_bud_cat` FOREIGN KEY (`category_id`) REFERENCES `qa_fin_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_fin_budget_amendments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `amendment_number` VARCHAR(32) NOT NULL,
    `amendment_type` ENUM('increase','decrease','reallocation') NOT NULL,
    `from_budget_id` BIGINT UNSIGNED DEFAULT NULL,
    `to_budget_id` BIGINT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('PENDING_FINANCE_HEAD','PENDING_DEPUTY_CHAIRPERSON','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING_FINANCE_HEAD',
    `requested_by` INT UNSIGNED NOT NULL,
    `finance_head_id` INT UNSIGNED DEFAULT NULL,
    `finance_head_at` DATETIME DEFAULT NULL,
    `deputy_id` INT UNSIGNED DEFAULT NULL,
    `deputy_at` DATETIME DEFAULT NULL,
    `decision_note` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_bamend_num` (`amendment_number`),
    CONSTRAINT `fk_qa_ba_from` FOREIGN KEY (`from_budget_id`) REFERENCES `qa_fin_budgets` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_qa_ba_to` FOREIGN KEY (`to_budget_id`) REFERENCES `qa_fin_budgets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-Only Financial Ledger: executed rows are NEVER deleted; use REVERSAL / ADJUSTMENT entries
CREATE TABLE IF NOT EXISTS `qa_fin_transactions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tx_number` VARCHAR(32) NOT NULL,
    `entry_kind` ENUM('STANDARD','REVERSAL','ADJUSTMENT','TRANSFER') NOT NULL DEFAULT 'STANDARD',
    `reverses_tx_id` BIGINT UNSIGNED DEFAULT NULL,
    `type` ENUM('income','expense','transfer') NOT NULL,
    `operational_year_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED DEFAULT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `budget_id` BIGINT UNSIGNED DEFAULT NULL,
    `account_id` INT UNSIGNED NOT NULL,
    `counter_account_id` INT UNSIGNED DEFAULT NULL,
    `member_cache_id` INT UNSIGNED DEFAULT NULL,
    `procurement_po_id` BIGINT UNSIGNED DEFAULT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `payment_method` ENUM('cash','bank_transfer','mobile_money','check','other') NOT NULL DEFAULT 'cash',
    `voucher_number` VARCHAR(60) DEFAULT NULL,
    `payer_payee_name` VARCHAR(160) DEFAULT NULL,
    `description` VARCHAR(500) NOT NULL,
    `transaction_date` DATE NOT NULL,
    `ec_year` SMALLINT UNSIGNED DEFAULT NULL,
    `ec_month` TINYINT UNSIGNED DEFAULT NULL,
    `ec_day` TINYINT UNSIGNED DEFAULT NULL,
    `receipt_document_id` BIGINT UNSIGNED DEFAULT NULL,
    `status` ENUM('pending','confirmed','reversed') NOT NULL DEFAULT 'confirmed',
    `recorded_by` INT UNSIGNED NOT NULL,
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_fin_tx_num` (`tx_number`),
    KEY `idx_qa_ftx_date` (`transaction_date`, `type`, `status`),
    KEY `idx_qa_ftx_budget` (`budget_id`),
    KEY `idx_qa_ftx_acc` (`account_id`),
    CONSTRAINT `fk_qa_ftx_rev` FOREIGN KEY (`reverses_tx_id`) REFERENCES `qa_fin_transactions` (`id`),
    CONSTRAINT `fk_qa_ftx_acc` FOREIGN KEY (`account_id`) REFERENCES `qa_fin_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_fin_bank_reconciliations` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `account_id` INT UNSIGNED NOT NULL,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `statement_ending_balance` DECIMAL(14,2) NOT NULL,
    `ledger_ending_balance` DECIMAL(14,2) NOT NULL,
    `difference` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft','reconciled','audited') NOT NULL DEFAULT 'draft',
    `reconciled_by` INT UNSIGNED NOT NULL,
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_qa_brec_acc` FOREIGN KEY (`account_id`) REFERENCES `qa_fin_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_fin_bank_statement_lines` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reconciliation_id` BIGINT UNSIGNED NOT NULL,
    `line_date` DATE NOT NULL,
    `description` VARCHAR(300) NOT NULL,
    `reference_no` VARCHAR(80) DEFAULT NULL,
    `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `matched_tx_id` BIGINT UNSIGNED DEFAULT NULL,
    `match_status` ENUM('unmatched','matched','discrepancy','resolved') NOT NULL DEFAULT 'unmatched',
    `note` VARCHAR(300) DEFAULT NULL,
    CONSTRAINT `fk_qa_bsl_rec` FOREIGN KEY (`reconciliation_id`) REFERENCES `qa_fin_bank_reconciliations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qa_bsl_tx` FOREIGN KEY (`matched_tx_id`) REFERENCES `qa_fin_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_fin_contribution_plans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `operational_year_id` INT UNSIGNED NOT NULL,
    `name_en` VARCHAR(120) NOT NULL,
    `name_am` VARCHAR(120) DEFAULT NULL,
    `monthly_amount` DECIMAL(14,2) NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_fin_member_contributions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_cache_id` INT UNSIGNED NOT NULL,
    `plan_id` INT UNSIGNED DEFAULT NULL,
    `ec_year` SMALLINT UNSIGNED NOT NULL,
    `ec_month` TINYINT UNSIGNED NOT NULL,
    `expected_amount` DECIMAL(14,2) NOT NULL,
    `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    `paid_date` DATE DEFAULT NULL,
    `receipt_number` VARCHAR(60) DEFAULT NULL,
    `transaction_id` BIGINT UNSIGNED DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_member_contrib_month` (`member_cache_id`, `ec_year`, `ec_month`),
    CONSTRAINT `fk_qa_mc_member` FOREIGN KEY (`member_cache_id`) REFERENCES `qa_member_sync_cache` (`id`),
    CONSTRAINT `fk_qa_mc_tx` FOREIGN KEY (`transaction_id`) REFERENCES `qa_fin_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2B. Procurement Management
CREATE TABLE IF NOT EXISTS `qa_suppliers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(160) NOT NULL,
    `contact_person` VARCHAR(120) DEFAULT NULL,
    `phone` VARCHAR(40) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `tin_number` VARCHAR(40) DEFAULT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `category` VARCHAR(80) DEFAULT NULL,
    `bank_details` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_procurement_requests` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `pr_number` VARCHAR(32) NOT NULL,
    `operational_year_id` INT UNSIGNED NOT NULL,
    `requester_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED NOT NULL,
    `budget_id` BIGINT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(180) NOT NULL,
    `justification` TEXT NOT NULL,
    `needed_by_date` DATE DEFAULT NULL,
    `estimated_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `stock_check_status` ENUM('PENDING','NOT_IN_STOCK','PARTIAL_STOCK','IN_STOCK_FULFILLABLE') NOT NULL DEFAULT 'PENDING',
    `stock_checked_by` INT UNSIGNED DEFAULT NULL,
    `stock_checked_at` DATETIME DEFAULT NULL,
    `stock_check_note` VARCHAR(500) DEFAULT NULL,
    `budget_check_status` ENUM('PENDING','PASSED','BLOCKED_INSUFFICIENT') NOT NULL DEFAULT 'PENDING',
    `budget_checked_at` DATETIME DEFAULT NULL,
    `status` ENUM(
        'DRAFT',
        'STOCK_CHECK_PENDING',
        'BUDGET_BLOCKED',
        'PENDING_DEPARTMENT_HEAD',
        'PENDING_FINANCE_REVIEW',
        'PENDING_DEPUTY_CHAIRPERSON',
        'APPROVED',
        'REJECTED',
        'ORDERED',
        'PARTIALLY_RECEIVED',
        'RECEIVED',
        'CLOSED'
    ) NOT NULL DEFAULT 'DRAFT',
    `dept_head_id` INT UNSIGNED DEFAULT NULL,
    `dept_head_at` DATETIME DEFAULT NULL,
    `finance_reviewer_id` INT UNSIGNED DEFAULT NULL,
    `finance_reviewed_at` DATETIME DEFAULT NULL,
    `deputy_id` INT UNSIGNED DEFAULT NULL,
    `deputy_at` DATETIME DEFAULT NULL,
    `decision_note` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_pr_num` (`pr_number`),
    KEY `idx_qa_pr_status` (`status`, `department_id`),
    CONSTRAINT `fk_qa_pr_dept` FOREIGN KEY (`department_id`) REFERENCES `qa_departments` (`id`),
    CONSTRAINT `fk_qa_pr_budget` FOREIGN KEY (`budget_id`) REFERENCES `qa_fin_budgets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_procurement_items` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `procurement_request_id` BIGINT UNSIGNED NOT NULL,
    `asset_id` BIGINT UNSIGNED DEFAULT NULL,
    `item_name` VARCHAR(180) NOT NULL,
    `specification` VARCHAR(500) DEFAULT NULL,
    `item_type` ENUM('fixed_asset','consumable','service') NOT NULL DEFAULT 'fixed_asset',
    `requested_qty` INT NOT NULL DEFAULT 1,
    `available_stock_qty` INT NOT NULL DEFAULT 0,
    `qty_to_buy` INT NOT NULL DEFAULT 1,
    `estimated_unit_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `unit` VARCHAR(32) NOT NULL DEFAULT 'piece',
    CONSTRAINT `fk_qa_pi_pr` FOREIGN KEY (`procurement_request_id`) REFERENCES `qa_procurement_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_procurement_quotations` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `procurement_request_id` BIGINT UNSIGNED NOT NULL,
    `supplier_id` INT UNSIGNED NOT NULL,
    `quote_number` VARCHAR(60) DEFAULT NULL,
    `quoted_total` DECIMAL(14,2) NOT NULL,
    `delivery_days` SMALLINT UNSIGNED DEFAULT NULL,
    `warranty_terms` VARCHAR(255) DEFAULT NULL,
    `payment_terms` VARCHAR(255) DEFAULT NULL,
    `document_id` BIGINT UNSIGNED DEFAULT NULL,
    `is_selected` TINYINT(1) NOT NULL DEFAULT 0,
    `selection_reason` VARCHAR(500) DEFAULT NULL,
    `entered_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_qa_pq_pr` FOREIGN KEY (`procurement_request_id`) REFERENCES `qa_procurement_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qa_pq_sup` FOREIGN KEY (`supplier_id`) REFERENCES `qa_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_procurement_quotation_items` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `quotation_id` BIGINT UNSIGNED NOT NULL,
    `procurement_item_id` BIGINT UNSIGNED NOT NULL,
    `unit_price` DECIMAL(14,2) NOT NULL,
    `total_price` DECIMAL(14,2) NOT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    CONSTRAINT `fk_qa_pqi_q` FOREIGN KEY (`quotation_id`) REFERENCES `qa_procurement_quotations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qa_pqi_item` FOREIGN KEY (`procurement_item_id`) REFERENCES `qa_procurement_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_purchase_orders` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `po_number` VARCHAR(32) NOT NULL,
    `procurement_request_id` BIGINT UNSIGNED NOT NULL,
    `supplier_id` INT UNSIGNED NOT NULL,
    `selected_quotation_id` BIGINT UNSIGNED DEFAULT NULL,
    `budget_id` BIGINT UNSIGNED NOT NULL,
    `total_amount` DECIMAL(14,2) NOT NULL,
    `expected_delivery_date` DATE DEFAULT NULL,
    `status` ENUM('ISSUED','PARTIALLY_RECEIVED','RECEIVED','PAID','CANCELLED') NOT NULL DEFAULT 'ISSUED',
    `po_document_id` BIGINT UNSIGNED DEFAULT NULL,
    `issued_by` INT UNSIGNED NOT NULL,
    `issued_at` DATETIME NOT NULL,
    UNIQUE KEY `uk_qa_po_num` (`po_number`),
    CONSTRAINT `fk_qa_po_pr` FOREIGN KEY (`procurement_request_id`) REFERENCES `qa_procurement_requests` (`id`),
    CONSTRAINT `fk_qa_po_sup` FOREIGN KEY (`supplier_id`) REFERENCES `qa_suppliers` (`id`),
    CONSTRAINT `fk_qa_po_bud` FOREIGN KEY (`budget_id`) REFERENCES `qa_fin_budgets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_goods_receipts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `grn_number` VARCHAR(32) NOT NULL,
    `po_id` BIGINT UNSIGNED NOT NULL,
    `received_by` INT UNSIGNED NOT NULL,
    `inspected_by` INT UNSIGNED DEFAULT NULL,
    `received_at` DATETIME NOT NULL,
    `inspection_status` ENUM('ACCEPTED','PARTIAL','REJECTED') NOT NULL,
    `receiver_signature_path` VARCHAR(255) DEFAULT NULL,
    `document_id` BIGINT UNSIGNED DEFAULT NULL,
    `notes` VARCHAR(500) DEFAULT NULL,
    `asset_handoff_completed` TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uk_qa_grn_num` (`grn_number`),
    CONSTRAINT `fk_qa_grn_po` FOREIGN KEY (`po_id`) REFERENCES `qa_purchase_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_goods_receipt_items` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `grn_id` BIGINT UNSIGNED NOT NULL,
    `procurement_item_id` BIGINT UNSIGNED NOT NULL,
    `ordered_qty` INT NOT NULL,
    `received_qty` INT NOT NULL,
    `accepted_qty` INT NOT NULL,
    `rejected_qty` INT NOT NULL DEFAULT 0,
    `condition_note` VARCHAR(255) DEFAULT NULL,
    `created_asset_id` BIGINT UNSIGNED DEFAULT NULL,
    CONSTRAINT `fk_qa_grni_grn` FOREIGN KEY (`grn_id`) REFERENCES `qa_goods_receipts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2C. Document Management Vault
CREATE TABLE IF NOT EXISTS `qa_doc_folders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `parent_id` INT UNSIGNED DEFAULT NULL,
    `department_id` INT UNSIGNED DEFAULT NULL,
    `ec_year` SMALLINT UNSIGNED DEFAULT NULL,
    `name_en` VARCHAR(120) NOT NULL,
    `name_am` VARCHAR(120) DEFAULT NULL,
    `is_restricted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_documents` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `doc_number` VARCHAR(32) NOT NULL,
    `external_ref_no` VARCHAR(80) DEFAULT NULL,
    `folder_id` INT UNSIGNED DEFAULT NULL,
    `department_id` INT UNSIGNED DEFAULT NULL,
    `doc_type` ENUM('letter','decision','contract','receipt','invoice','quotation','purchase_order','handover','inspection_report','maintenance_record','audit_report','other') NOT NULL DEFAULT 'other',
    `title_en` VARCHAR(200) NOT NULL,
    `title_am` VARCHAR(200) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `tags` VARCHAR(255) DEFAULT NULL,
    `document_date` DATE NOT NULL,
    `ec_year` SMALLINT UNSIGNED DEFAULT NULL,
    `current_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `approval_status` ENUM('DRAFT','PENDING_APPROVAL','APPROVED','RETURNED') NOT NULL DEFAULT 'DRAFT',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `confidentiality` ENUM('department','leadership_finance','restricted') NOT NULL DEFAULT 'department',
    `linked_entity_type` VARCHAR(40) DEFAULT NULL,
    `linked_entity_id` BIGINT UNSIGNED DEFAULT NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `archived_by` INT UNSIGNED DEFAULT NULL,
    `archived_at` DATETIME DEFAULT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_doc_num` (`doc_number`),
    KEY `idx_qa_doc_type_year` (`doc_type`, `ec_year`, `is_archived`),
    KEY `idx_qa_doc_linked` (`linked_entity_type`, `linked_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_document_versions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `version_number` SMALLINT UNSIGNED NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `storage_key` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(120) NOT NULL,
    `file_size_bytes` BIGINT UNSIGNED NOT NULL,
    `sha256_hash` CHAR(64) NOT NULL,
    `uploaded_by` INT UNSIGNED NOT NULL,
    `change_note` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_qa_doc_ver` (`document_id`, `version_number`),
    CONSTRAINT `fk_qa_dv_doc` FOREIGN KEY (`document_id`) REFERENCES `qa_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qa_document_access_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `version_number` SMALLINT UNSIGNED DEFAULT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `action` ENUM('upload','view','download','edit_meta','approve','return','archive','restore') NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_qa_dal_doc` (`document_id`, `created_at`),
    CONSTRAINT `fk_qa_dal_doc` FOREIGN KEY (`document_id`) REFERENCES `qa_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
