# 03 — Database, API, Mobile & Delivery Blueprint

This blueprint translates all requirements from `Asset_Management_System_PRD_v1.0.pdf`, `QIDUS_ASSET_Budget_Document_Procurement_Plan.pdf`, and `QIDUS_ASSET_Backend_Team_Handoff.pdf` into a plain **PHP 8.2+ + MySQL/MariaDB (PDO) + Separated Web UI + Flutter Android (Drift/SQLite + Keystore)** architecture using the clean-slate `qa_*` table prefix.

---

## 1. Clean-Slate Relational Database Schema (`qa_*`)

All tables use `InnoDB` + `utf8mb4_unicode_ci`, monetary amounts use `DECIMAL(14,2)`, and no operational history is ever hard-deleted.

### 1.1 Organization, Auth, SSMS Connector & Biometric Device Keys
| Table | Purpose & Key Columns |
|---|---|
| `qa_install_config` | School name (`en`/`am`), logo path, active connector (`ssms_direct`, `ssms_http`, `standalone`), numbering prefixes, policy JSON |
| `qa_operational_years` | `id`, `external_year_id` (nullable SSMS `academic_years.id`), `ec_year`, `label_en`, `label_am`, `start_date`, `end_date`, `status` (`active`/`closed`/`reopened`), `closed_by`, `closed_at`, `reopen_reason` |
| `qa_departments` | `id`, `code` (`asset_dept`, `finance_dept`, ...), `name_en`, `name_am`, `head_user_id`, `is_active` |
| `qa_users` *(standalone)* / `qa_user_profiles` *(SSMS bridge)* | `id`, `source_system`, `external_user_id`, `username`, `full_name`, `full_name_am`, `qa_role` (`asset_admin`, `asset_head`, `asset_officer`, `event_operator`, `maintenance_officer`, `finance_head`, `finance_staff`, `procurement_officer`, `document_controller`, `chairperson`, `deputy_chairperson`, `auditor`, `requester`), `department_id`, `approval_limit`, `auth_version`, `is_active` |
| `qa_device_keys` | `id`, `user_id`, `device_uuid`, `device_label`, `public_key_pem`, `key_algorithm` (`EC_P256`/`RSA_2048`), `biometric_bound` (TINYINT), `status` (`pending`/`active`/`revoked`), `approved_by`, `revoked_by`, `revoked_reason`, `last_used_at` |
| `qa_offline_leases` | `id`, `user_id`, `device_uuid`, `lease_jti`, `scope_json` (allowed actions, event_ids, location_ids), `issued_at`, `expires_at`, `revoked_at` |
| `qa_member_sync_cache` | `id`, `source_system` (`ssms`), `external_member_id`, `member_code`, `qr_token_ref`, `name_en`, `name_am`, `department_name`, `class_name`, `section_name`, `group_names`, `photo_url`, `source_status` (`active`/`inactive`/`archived`), `is_tombstoned`, `last_sync_revision`, `synced_at` (`UNIQUE(source_system, external_member_id)`) |
| `qa_member_asset_profiles` | `id`, `member_cache_id` (FK), `clothing_size`, `shoe_size`, `notes`, `updated_by`, `updated_at` *(stores asset-specific sizing locally without mutating SSMS)* |

### 1.2 Department 1 — Asset Dept (`asset_dept`: Fixed, Stock, Reusable, Events, Maintenance, Inspections, Disposal)
| Table | Purpose & Key Columns |
|---|---|
| `qa_asset_categories` | `id`, `parent_id`, `code`, `name_en`, `name_am`, `tracking_mode` (`fixed`/`consumable`/`reusable_member`), `qr_required`, `requires_return`, `field_schema_json` |
| `qa_asset_locations` | `id`, `name_en`, `name_am`, `building`, `room`, `custodian_user_id`, `is_active` |
| `qa_assets` | `id`, `asset_uuid`, `asset_code` (`AST-YYYY-NNNNN`, UNIQUE), `qr_token` (opaque UNIQUE token), `name_en`, `name_am`, `category_id`, `tracking_mode` (`fixed`/`consumable`/`reusable_member`), `serial_number`, `manufacturer`, `model`, `custom_fields_json`, `unit`, `quantity`, `reserved_qty`, `min_quantity`, `location_id`, `department_id`, `custodian_user_id`, `acquisition_source` (`purchase`/`donation`/`transfer`/`opening`), `po_id`, `acquisition_date`, `unit_cost`, `currency`, `condition_status` (`new`/`good`/`fair`/`poor`/`damaged`/`under_repair`/`disposed`), `status` (`in_stock`/`assigned`/`low_stock`/`out_of_stock`/`maintenance`/`retired`/`archived`), `primary_document_id`, `created_by`, `created_at` |
| `qa_asset_variants` | `id`, `asset_id`, `variant_code`, `size_label` (`XS`/`S`/`M`/`L`/`XL`/etc.), `color_or_style`, `location_id`, `quantity`, `reserved_qty`, `min_quantity` *(supports clothing/uniforms by size)* |
| `qa_asset_qr_history` | `id`, `asset_id`, `qr_token`, `status` (`active`/`revoked`), `issued_by`, `revoked_reason`, `issued_at`, `revoked_at` |
| `qa_asset_movements` | `id`, `asset_id`, `variant_id`, `operational_year_id`, `movement_type` (`incoming`/`outgoing`/`transfer`/`issue`/`return`/`adjustment`/`maintenance_out`/`maintenance_in`/`disposal`), `quantity_delta`, `balance_after`, `from_location_id`, `to_location_id`, `reference_type`, `reference_id`, `reason`, `performed_by`, `device_uuid`, `created_at` |
| `qa_asset_requests` | `id`, `request_number` (`AREQ-YYYY-NNNNN`), `operational_year_id`, `requester_id`, `department_id`, `asset_id`, `variant_id`, `requested_item_name`, `quantity`, `urgency`, `needed_by_date`, `reason`, `status` (`PENDING_DEPARTMENT_HEAD`/`PENDING_DEPUTY_CHAIRPERSON`/`RETURNED_FOR_CORRECTION`/`APPROVED`/`REJECTED`/`CANCELLED`/`FULFILLED`), `dept_head_id`, `dept_head_at`, `dept_head_note`, `deputy_id`, `deputy_at`, `deputy_note` |
| `qa_asset_handovers` | `id`, `handover_number` (`HND-YYYY-NNNNN`), `operational_year_id`, `asset_request_id`, `asset_id`, `variant_id`, `handover_type` (`issue`/`return`/`partial_return`/`transfer`), `sender_user_id`, `receiver_user_id`, `member_cache_id`, `quantity`, `condition_at_handover`, `expected_return_date`, `sender_sig_path`, `receiver_sig_path`, `sender_sig_sha256`, `receiver_sig_sha256`, `signed_by_operator_for_recipient`, `biometric_key_id`, `biometric_sig_b64`, `payload_sha256`, `verification_method` (`biometric_keystore`/`drawn_signature`/`pin_fallback`/`both`), `client_tx_uuid`, `device_tx_at`, `server_received_at` |
| `qa_event_templates` | `id`, `name_en`, `name_am`, `default_items_json`, `default_filter_json`, `default_return_days`, `rules_json` |
| `qa_events` | `id`, `event_code`, `template_id`, `operational_year_id`, `name_en`, `name_am`, `event_date`, `expected_return_date`, `status` (`draft`/`prepared`/`active`/`reconciling`/`closed`), `created_by` |
| `qa_event_stations` & `qa_event_allocations` | Station definitions (`station_name`, `operator_user_id`) + partitioned stock allocations (`event_id`, `station_id`, `asset_id`, `variant_id`, `allocated_qty`, `issued_qty`, `returned_qty`) to prevent multi-phone over-issue |
| `qa_event_participants` & `qa_event_transactions` | Pre-filtered eligible `member_cache_id` list + rapid `issue`/`return`/`partial_return`/`damaged`/`missing` records with `client_tx_uuid`, `station_id`, `verification_method`, and `sync_status` |
| `qa_asset_maintenance` | `id`, `ticket_no`, `asset_id`, `operational_year_id`, `maintenance_type` (`preventive`/`corrective`), `priority`, `issue_description`, `provider_name`, `scheduled_date`, `started_at`, `completed_at`, `parts_cost`, `service_cost`, `warranty_covered`, `status` (`scheduled`/`in_progress`/`completed`/`cancelled`), `document_id` |
| `qa_asset_inspections` & `qa_asset_inspection_items` | Physical count & audit campaigns (`operational_year_id`, `location_id`, `category_id`, `status`) + per-asset scan results (`inspected`/`not_found`/`relocated`/`damaged`/`duplicated`/`unregistered`, `system_qty`, `counted_qty`, `photo_doc_id`) |
| `qa_asset_disposals` | `id`, `disposal_no`, `asset_id`, `operational_year_id`, `reason_type` (`lost`/`stolen`/`destroyed`/`damaged_beyond_repair`/`sold`/`donated`/`retired`), `proceeds_amount`, `recipient`, `override_open_issues`, `status` (`PENDING_APPROVAL`/`APPROVED`/`REJECTED`/`COMPLETED`), `approved_by`, `document_id` |

### 1.3 Department 2 — Finance, Budget, Bank, Procurement & Documents (`finance_dept`)
| Group | Tables |
|---|---|
| **2A. Budget & Finance** | `qa_fin_categories`, `qa_fin_accounts` (bank & petty cash), `qa_fin_budgets` (by `operational_year_id`, `department_id`, `category_id`, `approved`/`amended`/`committed`/`spent`), `qa_fin_budget_amendments` (`increase`/`decrease`/`reallocation` + approvals), `qa_fin_transactions` (**append-only:** `STANDARD`/`REVERSAL`/`ADJUSTMENT`/`TRANSFER`, `reverses_tx_id`, NO delete), `qa_fin_bank_reconciliations`, `qa_fin_bank_statement_lines`, `qa_fin_contribution_plans`, `qa_fin_member_contributions` (linked to `member_cache_id` & `transaction_id`) |
| **2B. Procurement** | `qa_suppliers`, `qa_procurement_requests` (`stock_check_status`, `budget_check_status`, multi-stage status), `qa_procurement_items`, `qa_procurement_quotations` & `qa_procurement_quotation_items`, `qa_purchase_orders` (`PO-YYYY-NNNNN`), `qa_goods_receipts` & `qa_goods_receipt_items` (auto-mints `qa_assets` / stock movements on accept) |
| **2C. Document Vault** | `qa_doc_folders`, `qa_documents` (`DOC-YYYY-NNNNN`, `approval_status`, `confidentiality`, `linked_entity_type`, `linked_entity_id`, soft `is_archived`), `qa_document_versions` (private storage keys outside web root + SHA-256), `qa_document_access_logs` |

### 1.4 Cross-Cutting Notifications, Audit, Findings & Idempotency
- `qa_notifications` *(standalone; SSMS uses `notifications`)*
- `qa_audit_logs` *(append-only: `actor_user_id`, `actor_role`, `impersonated_by_user_id`, `device_uuid`, `device_key_id`, `action`, `entity_type`, `entity_id`, `before_json`, `after_json`, `evidence_sha256`, `device_at`, `server_at`, `outcome`)*
- `qa_audit_findings` *(auditor findings & corrective actions with owner, severity, due date, status)*
- `qa_sync_outbox_receipts` & `qa_api_idempotency` *(client UUID + `idempotency_key` deduplication and conflict tracking)*

---

## 2. REST API Contract (`/api/v1/qa/*`)

All responses use standard JSON (`status: success|error`), HTTPS, server-enforced RBAC + department/year scoping, PDO prepared queries, and `Idempotency-Key` support on mobile mutations.

### 2.1 Auth, Devices, Member Sync & Notifications
- `POST /api/v1/auth/login` | `POST /api/v1/auth/refresh` | `GET /api/v1/users/me` | `GET /api/v1/departments`
- `POST /api/v1/qa/devices/enroll` | `POST /api/v1/qa/devices/{id}/approve` | `POST /api/v1/qa/devices/{id}/revoke` *(registers/revokes Android Keystore public keys)*
- `GET /api/v1/qa/offline-lease` *(issues signed, time-limited offline permission snapshot)*
- `GET /api/v1/qa/members/sync?cursor={rev}&limit=200` *(paginated bootstrap + incremental cursor + tombstones from SSMS)*
- `GET /api/v1/qa/members/search?q=...&department=...&class=...&section=...`
- `GET /api/v1/notifications` | `PATCH /api/v1/notifications/{id}/read`

### 2.2 Department 1 — Asset Dept Endpoints
- `GET /api/v1/qa/assets` | `POST /api/v1/qa/assets` | `PATCH /api/v1/qa/assets/{id}` | `POST /api/v1/qa/assets/{id}/archive`
- `GET /api/v1/qa/assets/resolve/{qrCode}` | `POST /api/v1/qa/assets/{id}/qr-reprint`
- `POST /api/v1/qa/assets/{id}/movements` | `GET /api/v1/qa/assets/{id}/history`
- `GET /api/v1/qa/asset-requests` | `POST /api/v1/qa/asset-requests` | `POST /api/v1/qa/asset-requests/{id}/department-head-decision` | `POST /api/v1/qa/asset-requests/{id}/deputy-decision`
- `GET /api/v1/qa/handovers` | `POST /api/v1/qa/handovers` *(accepts drawn signatures + Keystore signature verification)*
- `GET /api/v1/qa/events` | `POST /api/v1/qa/events` | `GET /api/v1/qa/events/{id}/package` | `POST /api/v1/qa/events/{id}/sync-batch` | `GET /api/v1/qa/events/{id}/reconcile`
- `GET /api/v1/qa/maintenance` | `POST /api/v1/qa/maintenance` | `PATCH /api/v1/qa/maintenance/{id}`
- `GET /api/v1/qa/inspections` | `POST /api/v1/qa/inspections` | `POST /api/v1/qa/inspections/{id}/scans`
- `POST /api/v1/qa/disposals` | `POST /api/v1/qa/disposals/{id}/decision`
- `GET /api/v1/qa/years` | `POST /api/v1/qa/years/{id}/close` | `POST /api/v1/qa/years/{id}/reopen`

### 2.3 Department 2 — Finance, Procurement & Document Endpoints
- `GET /api/v1/qa/budgets` | `POST /api/v1/qa/budgets` | `POST /api/v1/qa/budget-amendments` | `POST /api/v1/qa/budget-amendments/{id}/decision`
- `GET /api/v1/qa/transactions` | `POST /api/v1/qa/transactions` | `POST /api/v1/qa/transactions/{id}/adjust` *(no `DELETE` route)*
- `GET /api/v1/qa/accounts` | `POST /api/v1/qa/reconciliations` | `POST /api/v1/qa/reconciliations/{id}/match`
- `GET /api/v1/qa/contributions` | `POST /api/v1/qa/contributions/pay`
- `GET /api/v1/qa/procurement-requests` | `POST /api/v1/qa/procurement-requests` | `POST /api/v1/qa/procurement-requests/{id}/stock-check` | `POST /api/v1/qa/procurement-requests/{id}/quotations` | `POST /api/v1/qa/procurement-requests/{id}/decision`
- `POST /api/v1/qa/purchase-orders` | `POST /api/v1/qa/goods-receipts`
- `GET /api/v1/qa/documents` | `POST /api/v1/qa/documents` | `GET /api/v1/qa/documents/{id}/download` | `POST /api/v1/qa/documents/{id}/approve` | `POST /api/v1/qa/documents/{id}/versions`

### 2.4 Reports, Audit & SSMS Executive Integration
- `GET /api/v1/qa/reports/summary` | `GET /api/v1/qa/reports/export/{reportKey}?format=pdf|xlsx`
- `GET /api/v1/qa/audit-logs` | `GET /api/v1/qa/audit-findings` | `POST /api/v1/qa/audit-findings`
- `GET /api/v1/qa/executive/summary` *(for SSMS `super-admin.php` & `school_admin.php`)*

---

## 3. Flutter Mobile App Stack & Offline Architecture

Built from zero in `mobile/qidus_asset_app/` targeting Android first with bilingual (`am`/`en`) UI:
- **Local Encrypted Persistence:** `drift` + `sqlcipher_flutter_libs` (key wrapped in `flutter_secure_storage` / Android Keystore).
- **Offline Outbox Engine:** Durable table `local_outbox` (`client_tx_uuid`, `idempotency_key`, `tx_type`, `payload_json`, `signature_file_path`, `biometric_sig_b64`, `status`: `saved_offline | waiting | syncing | synced | conflict | rejected`, `retry_count`, `error_message`), processed by `SyncCoordinator` + `connectivity_plus` + `workmanager` (resumable, best-effort).
- **Hardware & Security Packages:**
  - `mobile_scanner` (continuous asset & SSMS member QR scanning)
  - `local_auth` + Android Keystore native channel (`BiometricKeystoreSigner`: generates EC P-256 key requiring biometric unlock, signs SHA-256 challenge; **never handles raw fingerprint data**)
  - `signature` (drawn sender/receiver signature pad)
  - `dio` (HTTP client with JWT auto-refresh & idempotency headers)
  - `image_picker` + compression (asset & receipt photos)

---

## 4. Phased Implementation Roadmap (From Zero)

1. **Phase 0 — Clean Foundation & Connector Contract:** Repository skeleton, Phinx/SQL `qa_*` migrations, PDO core, `SSMSConnector` + `StandaloneConnector`, Monolog, static security test suite.
2. **Phase 1 — Identity, RBAC, Operational Years & Device Keys:** Auth bridge, role/department enforcement, year workspaces, `qa_device_keys`, `qa_offline_leases`, `qa_audit_logs`, and `qa_member_sync_cache` (+ SSMS `SyncChangeFeedService` `'members'` extension).
3. **Phase 2 — Department 1 Core (Assets, QR, 2-Step Requests & Signature Handovers):** Fixed/consumable/reusable assets, size variants, QR generation/resolution, movements, 2-step requests, drawn + Keystore-signed handovers.
4. **Phase 3 — Department 1 Field & Lifecycle (Events, Maintenance, Inspections, Disposal):** Reusable event templates, multi-station allocations, rapid mobile issue/return, maintenance tickets, inspection campaigns, disposal workflow, year close/carry-forward.
5. **Phase 4 — Department 2A (Budget, Append-Only Ledger, Bank Reconciliation & Contributions):** Fiscal budgets, amendments, adjustment-only ledger, bank/petty cash, statement matching, member contributions.
6. **Phase 5 — Department 2B & 2C (Procurement + Document Vault + Auditor Workbench):** Stock & budget gates, quotations matrix, POs, GRN → Asset/Finance/Doc handoff, private document vault, versioning, access logs, audit findings.
7. **Phase 6 — Web Consoles, PDF/XLSX Reports, Flutter App & SSMS Super Admin Cutover:** Separated `web/` dashboards, `Dompdf` (Amharic TTF) & `PhpSpreadsheet` reports, full offline-first Flutter Android build, and SSMS `super-admin.php` / `school_admin.php` / `api_impersonate.php` cutover retiring legacy `finance_*` and `material_*`.
