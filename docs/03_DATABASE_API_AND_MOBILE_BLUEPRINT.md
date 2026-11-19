# 03 — Database, API, Mobile & Delivery Blueprint

This blueprint translates the feature catalog into a concrete, clean-slate **PHP 8 + MariaDB/MySQL + Separated Web UI + Flutter Android** architecture using the `qa_*` table prefix so it deploys cleanly either **standalone** or **inside SSMS** without colliding with legacy `finance_*` / `material_*` tables during cutover.

---

## 1. Clean-Slate Relational Database Schema (`qa_*`)

All currency columns use `DECIMAL(14,2)`, all tables use `InnoDB` + `utf8mb4_unicode_ci`, and every state-changing table enforces foreign keys and auditability.

### 1.1 Organization, Users & Auth (Standalone + SSMS Bridge)
| Table | Purpose & Key Columns |
|---|---|
| `qa_departments` | `id`, `code` (`asset_dept`, `finance_dept`, `edu_dept`, etc.), `name_en`, `name_am`, `head_user_id`, `is_active`, `created_at` |
| `qa_users` *(standalone only)* | `id`, `username`, `email`, `full_name`, `full_name_am`, `password_hash`, `role`, `qa_role`, `department_id`, `is_active`, `auth_version`, `last_login`, `created_at` |
| `qa_user_profiles` *(SSMS bridge)* | `user_id` (FK -> SSMS `users.id`), `qa_role` (`system_admin`, `chairperson`, `deputy_chairperson`, `department_head`, `department_staff`, `auditor`), `department_id`, `spending_approval_limit`, `is_active` |
| `qa_auth_refresh_tokens` *(standalone)* | `id`, `user_id`, `family_id`, `token_hash`, `expires_at`, `revoked_at`, `device_label` (mirrors SSMS `RefreshTokenService` in integrated mode) |
| `qa_payers` *(standalone fallback)* | `id`, `member_code`, `full_name`, `full_name_am`, `phone`, `section`, `status` (replaced by SSMS `members` adapter in integrated mode) |

### 1.2 Department 1 — Asset Department (`asset_dept`)
| Table | Purpose & Key Columns |
|---|---|
| `qa_asset_categories` | `id`, `code`, `name_en`, `name_am`, `asset_class` (`fixed`, `consumable`), `description`, `is_active` |
| `qa_asset_locations` | `id`, `name_en`, `name_am`, `building`, `room`, `custodian_user_id`, `is_active` |
| `qa_assets` | `id`, `asset_code` (`AST-2026-00001`, UNIQUE), `qr_token` (UNIQUE UUID/hash), `name_en`, `name_am`, `category_id`, `asset_class` (`fixed`/`consumable`), `serial_number`, `quantity`, `reserved_qty`, `min_quantity`, `unit`, `location_id`, `custodian_user_id`, `department_id`, `acquisition_source` (`purchase`/`donation`/`transfer`/`opening`), `po_id` (nullable FK), `acquisition_date`, `unit_cost`, `condition_status` (`new`/`good`/`fair`/`poor`/`damaged`/`under_repair`/`disposed`), `status` (`in_stock`/`assigned`/`low_stock`/`out_of_stock`/`maintenance`/`archived`), `photo_document_id`, `created_by`, `created_at`, `updated_at` |
| `qa_asset_movements` | `id`, `asset_id`, `movement_type` (`incoming`/`outgoing`/`transfer`/`adjustment`/`return`/`maintenance_out`/`maintenance_in`/`disposal`), `quantity_delta`, `balance_after`, `from_location_id`, `to_location_id`, `reference_type`, `reference_id`, `reason`, `performed_by`, `movement_date`, `ec_date`, `created_at` |
| `qa_asset_requests` | `id`, `request_number` (`AREQ-YYYY-NNNNN`), `requester_id`, `department_id`, `asset_id` (nullable if free-text), `requested_item_name`, `quantity`, `urgency` (`low`/`normal`/`high`/`urgent`), `needed_by_date`, `reason`, `status` (`PENDING_DEPARTMENT_HEAD`, `PENDING_DEPUTY_CHAIRPERSON`, `APPROVED`, `REJECTED`, `CANCELLED`, `FULFILLED`), `dept_head_id`, `dept_head_decision_at`, `dept_head_note`, `deputy_id`, `deputy_decision_at`, `deputy_note`, `created_at`, `updated_at` |
| `qa_asset_handovers` | `id`, `handover_number` (`HND-YYYY-NNNNN`), `asset_request_id` (nullable FK), `asset_id`, `handover_type` (`issue`/`return`/`transfer`), `sender_user_id`, `receiver_user_id`, `receiver_name`, `quantity`, `condition_at_handover`, `expected_return_date`, `sender_signature_path`, `receiver_signature_path`, `sender_sig_sha256`, `receiver_sig_sha256`, `evidence_document_id`, `notes`, `handed_over_at`, `created_at` |
| `qa_asset_counts` | `id`, `count_number`, `location_id`, `category_id`, `status` (`open`/`submitted`/`verified`), `conducted_by`, `verified_by`, `started_at`, `completed_at` |
| `qa_asset_count_items` | `id`, `count_id`, `asset_id`, `system_qty`, `counted_qty`, `variance`, `observed_condition`, `note`, `scanned_via_qr` |

### 1.3 Department 2A — Finance, Budget, Bank & Member Contributions (`finance_dept`)
| Table | Purpose & Key Columns |
|---|---|
| `qa_fin_fiscal_years` | `id`, `ec_year` (UNIQUE), `label_en`, `label_am`, `start_date`, `end_date`, `status` (`draft`/`active`/`closed`), `created_by` |
| `qa_fin_categories` | `id`, `code`, `name_en`, `name_am`, `type` (`income`/`expense`), `parent_id`, `is_active` |
| `qa_fin_accounts` | `id`, `account_code`, `name_en`, `name_am`, `account_type` (`bank`/`petty_cash`/`mobile_money`), `bank_name`, `branch_name`, `account_number_masked`, `custodian_user_id`, `opening_balance`, `current_balance`, `is_active` |
| `qa_fin_budgets` | `id`, `fiscal_year_id`, `department_id`, `category_id`, `line_code`, `title_en`, `title_am`, `approved_amount`, `amended_amount`, `committed_amount`, `spent_amount`, `status` (`draft`/`approved`/`locked`/`closed`), `approved_by`, `approved_at` |
| `qa_fin_budget_amendments` | `id`, `amendment_number`, `amendment_type` (`increase`/`decrease`/`reallocation`), `from_budget_id` (nullable), `to_budget_id`, `amount`, `reason`, `status` (`PENDING_FINANCE_HEAD`/`PENDING_DEPUTY_CHAIRPERSON`/`APPROVED`/`REJECTED`), `requested_by`, `approved_by`, `decision_at`, `decision_note` |
| `qa_fin_transactions` | **Append-Only Ledger:** `id`, `tx_number` (`TX-YYYY-NNNNN`), `entry_kind` (`STANDARD`/`REVERSAL`/`ADJUSTMENT`/`TRANSFER`), `reverses_tx_id` (nullable FK -> self), `type` (`income`/`expense`/`transfer`), `fiscal_year_id`, `department_id`, `category_id`, `budget_id`, `account_id`, `counter_account_id` (for transfers), `member_id` (SSMS `members.id` or `qa_payers.id`), `procurement_po_id` (nullable), `amount` (positive DECIMAL 14,2), `payment_method`, `voucher_no`, `payer_payee_name`, `description`, `transaction_date`, `ec_year`, `ec_month`, `ec_day`, `receipt_document_id`, `status` (`pending`/`confirmed`/`reversed`), `recorded_by`, `approved_by`, `created_at` *(NO DELETE endpoint/permission; corrections use `REVERSAL` + `ADJUSTMENT`)* |
| `qa_fin_bank_reconciliations` | `id`, `account_id`, `period_start`, `period_end`, `statement_ending_balance`, `ledger_ending_balance`, `difference`, `status` (`draft`/`reconciled`/`audited`), `reconciled_by`, `approved_by`, `notes`, `created_at` |
| `qa_fin_bank_statement_lines` | `id`, `reconciliation_id`, `line_date`, `description`, `reference_no`, `debit`, `credit`, `matched_tx_id` (nullable FK -> `qa_fin_transactions`), `match_status` (`unmatched`/`matched`/`discrepancy`/`resolved`), `note` |
| `qa_fin_contribution_plans` | `id`, `fiscal_year_id`, `name_en`, `name_am`, `monthly_amount`, `is_default`, `is_active` |
| `qa_fin_member_contributions` | `id`, `member_id`, `plan_id`, `ec_year`, `ec_month`, `expected_amount`, `paid_amount`, `status` (`unpaid`/`partial`/`paid`), `paid_date`, `receipt_no`, `transaction_id` (FK -> `qa_fin_transactions`), `recorded_by`, `created_at` |

### 1.4 Department 2B — Procurement Management (`finance_dept` + Asset Handoff)
| Table | Purpose & Key Columns |
|---|---|
| `qa_suppliers` | `id`, `name`, `contact_person`, `phone`, `email`, `tin_number`, `address`, `category`, `bank_details`, `is_active` |
| `qa_procurement_requests` | `id`, `pr_number` (`PR-YYYY-NNNNN`), `requester_id`, `department_id`, `budget_id`, `title`, `justification`, `needed_by_date`, `estimated_total`, `stock_check_status` (`PENDING`/`NOT_IN_STOCK`/`PARTIAL_STOCK`/`IN_STOCK_FULFILLABLE`), `stock_checked_by`, `stock_checked_at`, `stock_check_note`, `budget_check_status` (`PENDING`/`PASSED`/`BLOCKED_INSUFFICIENT`), `budget_checked_at`, `status` (`DRAFT`/`STOCK_CHECK_PENDING`/`BUDGET_BLOCKED`/`PENDING_DEPARTMENT_HEAD`/`PENDING_FINANCE_REVIEW`/`PENDING_DEPUTY_CHAIRPERSON`/`APPROVED`/`REJECTED`/`ORDERED`/`PARTIALLY_RECEIVED`/`RECEIVED`/`CLOSED`), `dept_head_id`, `dept_head_at`, `finance_reviewer_id`, `finance_reviewed_at`, `deputy_id`, `deputy_at`, `decision_note`, `created_at` |
| `qa_procurement_items` | `id`, `procurement_request_id`, `asset_id` (nullable), `item_name`, `specification`, `item_type` (`fixed_asset`/`consumable`/`service`), `requested_qty`, `available_stock_qty`, `qty_to_buy`, `estimated_unit_price`, `unit` |
| `qa_procurement_quotations` | `id`, `procurement_request_id`, `supplier_id`, `quote_number`, `quoted_total`, `delivery_days`, `warranty_terms`, `payment_terms`, `document_id`, `is_selected`, `selection_reason`, `entered_by`, `created_at` |
| `qa_procurement_quotation_items` | `id`, `quotation_id`, `procurement_item_id`, `unit_price`, `total_price`, `notes` |
| `qa_purchase_orders` | `id`, `po_number` (`PO-YYYY-NNNNN`), `procurement_request_id`, `supplier_id`, `selected_quotation_id`, `budget_id`, `total_amount`, `expected_delivery_date`, `status` (`ISSUED`/`PARTIALLY_RECEIVED`/`RECEIVED`/`PAID`/`CANCELLED`), `po_document_id`, `issued_by`, `issued_at` |
| `qa_goods_receipts` | `id`, `grn_number` (`GRN-YYYY-NNNNN`), `po_id`, `received_by`, `inspected_by`, `received_at`, `inspection_status` (`ACCEPTED`/`PARTIAL`/`REJECTED`), `receiver_signature_path`, `document_id`, `notes`, `asset_handoff_completed` |
| `qa_goods_receipt_items` | `id`, `grn_id`, `po_item_id`, `ordered_qty`, `received_qty`, `accepted_qty`, `rejected_qty`, `condition_note`, `created_asset_id` |

### 1.5 Department 2C — Document Management (`finance_dept`)
| Table | Purpose & Key Columns |
|---|---|
| `qa_doc_folders` | `id`, `parent_id`, `department_id`, `ec_year`, `name_en`, `name_am`, `is_restricted` |
| `qa_documents` | `id`, `doc_number` (`DOC-YYYY-NNNNN`), `external_ref_no`, `folder_id`, `department_id`, `doc_type` (`letter`/`decision`/`contract`/`receipt`/`invoice`/`quotation`/`purchase_order`/`handover`/`audit_report`/`other`), `title_en`, `title_am`, `description`, `tags`, `document_date`, `ec_year`, `current_version`, `approval_status` (`DRAFT`/`PENDING_APPROVAL`/`APPROVED`/`RETURNED`), `approved_by`, `approved_at`, `confidentiality` (`department`/`leadership_finance`/`restricted`), `linked_entity_type`, `linked_entity_id`, `is_archived`, `archived_by`, `archived_at`, `created_by`, `created_at` |
| `qa_document_versions` | `id`, `document_id`, `version_number`, `original_filename`, `storage_key` (outside web root), `mime_type`, `file_size_bytes`, `sha256_hash`, `uploaded_by`, `change_note`, `created_at` |
| `qa_document_access_logs` | `id`, `document_id`, `version_number`, `user_id`, `action` (`upload`/`view`/`download`/`edit_meta`/`approve`/`return`/`archive`/`restore`), `ip_address`, `created_at` |

### 1.6 Notifications, Audit Logs, Audit Findings & Idempotency
| Table | Purpose & Key Columns |
|---|---|
| `qa_notifications` *(standalone; SSMS uses `notifications`)* | `id`, `recipient_user_id`, `title`, `message`, `type`, `entity_type`, `entity_id`, `is_read`, `read_at`, `created_at` |
| `qa_audit_logs` | **Append-Only:** `id`, `actor_user_id`, `actor_role`, `impersonated_by_user_id`, `department_id`, `action`, `entity_type`, `entity_id`, `before_json`, `after_json`, `ip_address`, `user_agent`, `created_at` |
| `qa_audit_findings` | `id`, `finding_code`, `auditor_user_id`, `department_id`, `severity` (`info`/`low`/`medium`/`high`/`critical`), `title`, `description`, `recommendation`, `linked_entity_type`, `linked_entity_id`, `status` (`open`/`in_progress`/`resolved`/`verified`), `management_response`, `resolved_at`, `verified_by` |
| `qa_api_idempotency` | `id`, `user_id`, `idempotency_key`, `endpoint`, `request_hash`, `response_code`, `response_json`, `created_at` (prevents duplicate sync posts on flaky mobile networks) |

---

## 2. REST API Integration Contract (`/api/v1/qa/*`)

All endpoints return consistent JSON:
- Success: `{"status": "success", "data": ...}` (HTTP `200` / `201`)
- Error: `{"status": "error", "code": "ERROR_CODE", "message": "..."}` (HTTP `400` / `401` / `403` / `404` / `409` / `422`)
- Mobile writes include `Idempotency-Key: <uuid-v4>` header.
- Authentication: `Authorization: Bearer <jwt>` (mobile) or verified PHP session + `X-CSRF-Token` (web).

### 2.1 Auth, Org & Notifications (Includes Handoff PDF §6 Endpoints)
| Method | Path | Allowed Roles | Notes |
|---|---|---|---|
| `POST` | `/api/v1/auth/login` | Public | Returns JWT + refresh token + `user`, `qa_role`, `department_id`, `capabilities` |
| `GET` | `/api/v1/users/me` | Authenticated | Current user profile, effective role, department, spending limit |
| `GET` | `/api/v1/departments` | Authenticated | Lists active departments & heads |
| `GET` | `/api/v1/notifications` | Authenticated | User's paginated notifications + unread count |
| `PATCH` | `/api/v1/notifications/{id}/read` | Recipient | Marks notification read |

### 2.2 Department 1 — Asset Dept Endpoints (`/api/v1/qa/...`)
| Method | Path | Allowed Roles | Notes |
|---|---|---|---|
| `GET` | `/api/v1/qa/assets` | All (scoped by dept/role) | Search, filter by category/location/status/class |
| `GET` | `/api/v1/qa/assets/resolve/{qrCode}` | Authenticated | Resolves QR token or `asset_code` -> full asset + history |
| `POST` | `/api/v1/qa/assets` | `asset_staff`, `asset_head`, `system_admin` | Creates asset + mints `asset_code` & `qr_token` |
| `PATCH` | `/api/v1/qa/assets/{assetId}` | `asset_staff`, `asset_head`, `system_admin` | Updates metadata/location/condition (writes audit log) |
| `POST` | `/api/v1/qa/assets/{assetId}/movements` | `asset_staff`, `asset_head` | Stock in/out/transfer/maintenance/disposal |
| `GET` | `/api/v1/qa/asset-requests` | Authenticated (scoped) | Lists requests; dept heads & deputy see action queue |
| `POST` | `/api/v1/qa/asset-requests` | Authenticated staff/head | Always forces initial status `PENDING_DEPARTMENT_HEAD` |
| `POST` | `/api/v1/qa/asset-requests/{id}/department-head-decision` | `department_head` (matching dept) / `system_admin` | Approve -> `PENDING_DEPUTY_CHAIRPERSON`, Reject -> `REJECTED` |
| `POST` | `/api/v1/qa/asset-requests/{id}/deputy-decision` | `deputy_chairperson` / `system_admin` | Final `APPROVED` or `REJECTED`; notifies requester + Asset Dept |
| `POST` | `/api/v1/qa/handovers` | `asset_staff`, `asset_head` | Records handover + uploads drawn signature PNGs + updates custody/qty |
| `GET` | `/api/v1/qa/handovers` | `asset_dept`, `chairperson`, `deputy_chairperson`, `auditor` | Handover history & signature download links |
| `POST` | `/api/v1/qa/asset-counts` | `asset_staff`, `asset_head`, `auditor` | Cycle count sessions & QR scan tallies |

### 2.3 Department 2 — Finance, Procurement & Document Endpoints
| Method | Path | Allowed Roles | Notes |
|---|---|---|---|
| `GET` | `/api/v1/qa/budgets` | `finance_dept`, `chairperson`, `deputy_chairperson`, `auditor`, Dept Heads (own dept) | Budget lines with `approved`, `amended`, `committed`, `spent`, `remaining` |
| `POST` | `/api/v1/qa/budgets` | `finance_head`, `system_admin` | Create/allocate fiscal year budget lines |
| `POST` | `/api/v1/qa/budget-amendments` | `finance_staff`, `finance_head`, Dept Heads | Request increase/decrease/reallocation |
| `POST` | `/api/v1/qa/budget-amendments/{id}/decision` | `deputy_chairperson`, `chairperson` | Approves/rejects budget reallocation |
| `GET` | `/api/v1/qa/transactions` | `finance_dept`, `chairperson`, `deputy_chairperson`, `auditor` | Filterable income/expense/transfer ledger |
| `POST` | `/api/v1/qa/transactions` | `finance_staff`, `finance_head` | Posts standard entry or account transfer (updates running balance) |
| `POST` | `/api/v1/qa/transactions/{id}/adjust` | `finance_head`, `deputy_chairperson` | Posts `REVERSAL` + optional `ADJUSTMENT` pair (NO `DELETE` endpoint exists!) |
| `GET` / `POST` | `/api/v1/qa/accounts` & `/reconciliations` | `finance_staff`, `finance_head`, `auditor` (read) | Bank/petty cash balances & statement line matching |
| `GET` / `POST` | `/api/v1/qa/contributions` | `finance_staff`, `finance_head`, `auditor` (read) | Monthly member contribution tracking & collection posting |
| `GET` / `POST` | `/api/v1/qa/procurement-requests` | Staff (own), `finance_dept`, `asset_dept`, Approvers | Create PR; run `/stock-check` (`asset_dept`) + `/budget-check` (auto) |
| `POST` | `/api/v1/qa/procurement-requests/{id}/quotations` | `finance_staff`, `finance_head` | Attach vendor quotes & comparison data |
| `POST` | `/api/v1/qa/procurement-requests/{id}/decision` | `department_head`, `finance_head`, `deputy_chairperson`, `chairperson` | Advances state machine; blocks if stock or budget gate failed |
| `POST` | `/api/v1/qa/purchase-orders` & `/goods-receipts` | `finance_head` (PO), `asset_staff`/`asset_head` (GRN) | Issues PO; GRN inspection auto-mints assets & indexes docs |
| `GET` / `POST` | `/api/v1/qa/documents` | Role- & confidentiality-scoped | Multipart upload (stored outside web root), search, `/approve`, `/download`, `/versions` |

### 2.4 Reports, Audit & SSMS Super Admin Analytics
| Method | Path | Allowed Roles | Notes |
|---|---|---|---|
| `GET` | `/api/v1/qa/reports/summary` | Leadership, `auditor`, Dept Heads (scoped) | Date-filtered summary (Ethiopian & Gregorian) |
| `GET` | `/api/v1/qa/reports/export/{reportKey}` | Authorized roles | Streams generated `.pdf` or `.xlsx` |
| `GET` | `/api/v1/qa/audit-logs` & `/audit-findings` | `auditor`, `system_admin`, `chairperson` | Immutable log explorer + findings management |
| `GET` | `/api/v1/qa/executive/summary` | `super_admin`, `school_admin`, `chairperson`, `deputy_chairperson`, `auditor` | Cross-dept KPIs embedded directly in SSMS `super-admin.php` & `school_admin.php` |

---

## 3. Separated Web Dashboard & SSMS Integration Plan

Following SSMS's modern separated frontend convention (`frontend/pages/` + `frontend/js/` + `themes/`):
1. **Standalone & Shared Web Shells (`web/`):**
   - `web/pages/asset_dept.php` + `web/js/asset_dept.js`: Asset Registry, QR Label Printer, Request Approvals, Handover Ledger, Incoming PO Goods Receiving, Physical Counts.
   - `web/pages/finance_dept.php` + `web/js/finance_dept.js`: Overview, Annual Budgets & Amendments, Immutable Income/Expense Ledger + Adjustment Drawer, Bank & Petty Cash + Reconciliation, Monthly Member Contributions, Procurement & Quotation Matrix, Document Vault & Approval Queue, PDF/Excel Reports.
   - `web/pages/leadership_audit.php` + `web/js/leadership_audit.js`: Unified Deputy Chairperson / Chairperson Approval Queue + Auditor Workbench & Findings.
2. **SSMS Integration Touchpoints (in `fkss-sunday-school-platform` at integration time):**
   - `admin/dashboard.php`: Add `case 'asset_dept':` and point `case 'finance_dept':` to the new QIDUS `finance_dept.php` shell (preserving `#impersonateBar` banner).
   - `admin/api_impersonate.php`: Add `'asset_dept'` (and optionally `'qa_auditor'` / `'qa_deputy_chairperson'`) to `$allowedRoles`.
   - `admin/access_control.php` & `FeatureGate.php`: Register `asset_dept` pages, `qa_*` API routes, and `FEATURE_ASSET` / `FEATURE_PROCUREMENT` / `FEATURE_DOCUMENTS` flags.
   - `admin/dashboards/super-admin.php` & `admin/dashboards/school_admin.php`: Add interactive `switchRole('asset_dept')` & `switchRole('finance_dept')` department cards + live Executive Analytics widgets (`GET /api/v1/qa/executive/summary`) so Super Admin can inspect metrics directly OR click into either department with full impersonated access.
   - Deprecate/remove legacy `admin/api_finance.php`, `admin/api_material.php`, `admin/dashboards/material_department.php`, and old `frontend/pages/finance_dept.php` after optional one-time migration.

---

## 4. New QIDUS ASSET Flutter Mobile App Architecture

Built from zero as a dedicated, bilingual (`am` / `en`) Android application (`mobile/qidus_asset_app/`):
- **Core Infrastructure:**
  - `AppConfig` supporting **Standalone Server URL** or **SSMS Host URL** (never embedding server secrets or service keys).
  - `SessionService` + `TokenStore` (`flutter_secure_storage` for JWT + rotating refresh token + `av` revalidation handling).
  - `LocalDatabase` (`sqflite`): offline cache of authorized assets, QR index, departments, categories, budgets, plus **`sync_outbox` table** (UUID `idempotency_key`, operation type, JSON payload, file attachments, retry backoff, status).
  - Dual **Ethiopian & Gregorian** date display/picker (`ethiopian_calendar.dart`).
- **Role-Adaptive Modules:**
  - **Home & Approvals Inbox:** Badge counts for `PENDING_DEPARTMENT_HEAD` / `PENDING_DEPUTY_CHAIRPERSON` items.
  - **Asset Scanner & Registry (`mobile_scanner`):** Scan QR → resolve asset → view history, submit request, log condition/count, or launch handover.
  - **Handover Signature Flow (`signature`):** Capture sender & receiver drawn signatures locally → queue PNGs + payload in outbox → upload reliably.
  - **Finance & Contributions:** Capture expense receipt photos, record member monthly fees, view budget balances & bank/cash summaries.
  - **Procurement & Documents:** Create/review purchase requests, compare attached quotations, approve POs, inspect goods receipts, upload/preview PDF & image documents.

---

## 5. Phased Implementation Roadmap (From Zero)

1. **Phase 0 — Clean-Slate Foundation & Adapters:** Set up `database/` migration runner, `backend/` core (`IdentityAdapter`, `MemberDirectoryAdapter`, `NotificationAdapter`, `AuditSinkAdapter`), and static security test harness.
2. **Phase 1 — Auth, RBAC, Departments & SSMS Bridge:** Implement `qa_departments`, `qa_users`/`qa_user_profiles`, JWT + session auth, `qa_audit_logs`, and SSMS Super Admin impersonation & access-control hooks.
3. **Phase 2 — Department 2A (Finance, Budget, Bank Reconciliation & Member Fees):** Implement fiscal years, budget lines/amendments, append-only ledger (no hard-deletes), bank/petty cash accounts, reconciliation matching, and member fee collection.
4. **Phase 3 — Department 1 (Asset Registry, QR, 2-Step Requests & Signature Handovers):** Implement `qa_assets`, QR generation/resolution, movements, `PENDING_DEPARTMENT_HEAD -> PENDING_DEPUTY_CHAIRPERSON` state machine, drawn-signature handovers, and cycle counts.
5. **Phase 4 — Department 2B (Procurement & Cross-Dept Gates):** Implement purchase requisitions, Asset Dept stock-check gate, Finance budget-availability gate, supplier quotations & comparison, POs, and Goods Receiving → Asset/Document handoff.
6. **Phase 5 — Department 2C (Document Management) & Auditor Workbench:** Implement private file vault, versioning, metadata search, document approval workflow, access logs, and Auditor findings tracking.
7. **Phase 6 — Web Dashboards, PDF/Excel Reports & Flutter Mobile App:** Complete separated web consoles, SSMS Super Admin executive analytics, bilingual PDF/XLSX generators, and the offline-first Flutter Android app + full integration/RBAC test suites.
