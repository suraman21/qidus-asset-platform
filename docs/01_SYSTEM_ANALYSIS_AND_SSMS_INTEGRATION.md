# 01 — Deep System Analysis & SSMS Integration Architecture

## 1. Synthesis of the Two Specification Documents

The QIDUS ASSET project is governed by two complementary requirement documents:

1. **`QIDUS_ASSET_Backend_Team_Handoff.pdf` (4 pages, English — Technical Integration & Mobile Handoff):**
   - Defines a bilingual (Amharic & English) Flutter Android app and backend integration contract for an Orthodox Sunday School.
   - Establishes 6 governance roles: `System Admin`, `Chairperson`, `Deputy Chairperson`, `Department Head`, `Department Staff`, and `Auditor`.
   - Defines core technical capabilities: central multi-device cloud database, server-enforced RBAC, unique asset IDs + QR resolution, two-stage asset request approvals (`PENDING_DEPARTMENT_HEAD -> PENDING_DEPUTY_CHAIRPERSON -> APPROVED/REJECTED`), asset handovers with drawn signature evidence & timestamps, budgets & transactions, procurement requests/quotations, in-app + optional server-side email/SMS notifications, date-filtered reports, and immutable audit logs.
   - Explicitly flags that the existing Flutter UI is a **frontend prototype** using `SharedPreferences` local storage, prototype role switching, and unintegrated Budget/Document/Procurement screens—requiring a production backend, real authentication, server-side authorization, and a clean API contract.

2. **`QIDUS_ASSET_Budget_Document_Procurement_Plan.pdf` (4 pages, Amharic — Functional Scope & Implementation Plan):**
   - Expands the three financial/administrative pillars in depth:
     1. **Budget Management (የበጀት አስተዳደር):** Annual budgets, department/line-item allocations, budget amendments & reallocations (with requester & approver), income/expense with receipt attachments, **Bank & Petty Cash ledgers**, **Bank statement reconciliation**, **Monthly member contributions**, and **Budget execution vs. remaining balance**.
     2. **Document Management (የሰነድ አያያዝ):** Uploading PDF/Word/Excel/image files with structured metadata (title, date, document number, author, department), folder/category organization, full search, document approval workflows, granular role permissions (view/download/edit/delete-request), version/access history, and deletion protection/backup.
     3. **Procurement Management (የግዢ አስተዳደር):** Purchase requisitions → **Asset inventory stock check** → **Budget availability gate (hard block before approval routing if unbudgeted)** → multi-supplier quotation comparison → hierarchical approval → Purchase Order (PO) → goods receipt & inspection with signature → **automatic handoff of fixed assets to Asset Management and supporting files to Document Management**.
   - **Critical Architectural Directives (Section 8):**
     - **Do not invent an incompatible standalone tech silo when integrating:** follow the existing API/Database patterns so the modules integrate cleanly.
     - **Strict Financial Immutability:** *"የተፈጸመ የገንዘብ ግብይት በቀጥታ እንዳይሰረዝ፤ ስህተት ሲኖር በማስተካከያ መዝገብ እንዲታረምና የለውጥ ታሪኩ እንዲቀመጥ"* — **Executed financial transactions must never be hard-deleted.** Corrections must be posted via reversing/adjustment entries linked to the original transaction, preserving full audit history.
   - **Guiding Principle (Section 9):** *"Every Birr, Every Asset, Every Document — Accounted For."*

### How Both Documents Fit Together
| Aspect | Handoff PDF (English) | Plan PDF (Amharic) | Combined Design Decision |
|---|---|---|---|
| **Core Domains** | Asset Registry, QR, Requests/Approvals, Handovers, Budgets, Procurement, Reports, Audit | Detailed Budget/Bank/Member Fees, Document Vault, End-to-End Procurement + Asset/Finance handoff | Split cleanly into **2 Operational Departments**: **(1) Asset Dept** & **(2) Finance & Related Dept**, governed by shared Leadership/Audit workflows |
| **Financial Integrity** | Track amounts, approvals, and audit logs | **Never delete** completed financial transactions; use adjustment records | Append-only ledger (`qa_fin_transactions`) + reversal/adjustment journal (`adjusted_tx_id`) + `qa_audit_logs` |
| **Procurement Gates** | Quotations, supplier details, status, approvals | Mandatory **Stock Check** (Asset Dept) + **Budget Check** (Finance Dept) *before* approval routing | Enforced server-side state machine connecting Asset Dept & Finance Dept |
| **Reporting & Calendar** | Date-filtered reports & audit trail | Monthly/annual P&L, budget execution, bank/cash, member fees, procurement, docs, audit findings — **PDF & Excel export** | Ethiopian + Gregorian dual-date filtering, PDF & `.xlsx` exports |
| **Tech Stack** | REST API or Firebase + Flutter Android | Follow existing backend API/DB architecture (PHP 8 + MariaDB + Flutter) | **PHP 8 + MariaDB/MySQL (`/api/v1`) + Separated HTML/JS/CSS Web UI + New Clean-Slate Flutter App** |

---

## 2. Two-Department Organizational Split

Rather than bundling everything under one monolithic role, QIDUS ASSET models **two independent, cooperating departments** plus cross-cutting oversight:

### Department 1: Asset Department (`asset_dept` — የንብረት ክፍል)
Owns physical & consumable property lifecycle:
- Fixed assets & consumable inventory catalog, categories, locations, custodians, and condition tracking.
- Unique asset IDs + QR code label generation & mobile scan lookup.
- Stock-in / stock-out / internal transfers / maintenance / damage / disposal workflows.
- Asset requests (`PENDING_DEPARTMENT_HEAD -> PENDING_DEPUTY_CHAIRPERSON -> APPROVED/REJECTED`).
- Digital handovers (issuer, receiver, quantity, timestamp, drawn signature capture, evidence photo).
- Pre-procurement **Stock Availability Verification** (confirms item isn't already in warehouse before buying).
- **Goods Receiving & Inspection** from approved Procurement Orders, automatically minting fixed-asset records or increasing stock quantities.
- Periodic physical inventory counts & variance reports.

### Department 2: Finance & Related Department (`finance_dept` — የበጀት፣ የፋይናንስ፣ የሰነድና የግዢ ክፍል)
Owns financial stewardship, procurement execution, and institutional document control across three tightly linked sub-modules:
- **2A. Budget & Finance (`budget_finance`):** Annual budgets, department/line-item allocations, budget amendments/reallocations, append-only income & expense transactions with receipts, bank & petty-cash accounts, bank statement reconciliation, monthly member contributions, and budget vs. actual execution analytics.
- **2B. Procurement (`procurement`):** Purchase requisitions, cross-dept stock verification, automated budget sufficiency gate, multi-supplier quotation capture & comparison matrix, hierarchical approval routing, Purchase Order (PO) issuance, supplier payment linkage, and goods-receipt handoff to Asset Dept + Document Vault.
- **2C. Document Management (`documents`):** Centralized vault for official letters, decisions, contracts, quotations, POs, invoices, receipts, and handover certificates (PDF, Word, Excel, images), structured numbering & folders, full-text/metadata search, document approval workflows, role-scoped permissions, version & access logs, and soft-archive protection.

### Cross-Cutting Governance Roles (Both Departments)
Following Section 2 of the Handoff PDF and Section 5 of the Amharic Plan:
- **System Admin (`system_admin` / SSMS `super_admin` & `school_admin`):** User/role/department assignment, system configuration, backups, and—in SSMS integrated mode—cross-department analytics and full impersonation into either department.
- **Chairperson (`chairperson`):** Executive read-all oversight, high-value/policy approvals, cross-department summaries.
- **Deputy Chairperson (`deputy_chairperson`):** Final approval authority (`PENDING_DEPUTY_CHAIRPERSON -> APPROVED / REJECTED`) for asset requests, budget reallocations, and procurement approvals within authority thresholds.
- **Department Head (`asset_head`, `finance_head` — scoped by `department_id`):** First-level review (`PENDING_DEPARTMENT_HEAD`), departmental budget management, staff task oversight.
- **Department Staff (`asset_staff`, `finance_staff` — scoped by `department_id`):** Day-to-day recording, request submission, handover execution, quotation entry, document upload.
- **Auditor (`auditor`):** Read-only access across both departments (transactions, budgets, bank reconciliations, procurement trails, asset handovers, documents, immutable audit logs) + ability to record **Audit Findings & Corrective Action Recommendations** without mutating operational records.

---

## 3. Deep Analysis of Existing SSMS (`fkss-sunday-school-platform`)

Inspecting the actual `fkss-sunday-school-platform` codebase reveals how SSMS works—and why the existing legacy finance and material code must be replaced from scratch rather than patched.

### 3.1 How SSMS Currently Works (Verified Architecture)
1. **Configuration & Feature Gating (`config.php`, `school_config.php`, `FeatureGate.php`):**
   - `config.php` loads environment secrets from outside web root (`.fkss_env.php` / `.wbws_env.php`), opens the `$conn` (`mysqli`) connection, initializes session security, and runs `admin/access_control.php`.
   - `FeatureGate.php` enforces module availability at both web dashboard (`forRoleDashboard`) and `/api/v1/` (`forApiResource`) layers.
2. **Centralized Web RBAC (`admin/access_control.php` & `frontend/layouts/base.php`):**
   - Every `/admin/*` script is gated automatically by `admin/access_control.php` via `$ROLE_MAP` (sessions + CSRF via `requireCsrfForPost()` + `generateCsrfToken()`).
   - Separated `/frontend/pages/*.php` shells (such as `finance_dept.php` and `mezmur_dept.php`) enforce `$requiredRoles` and `$requiredFeature` inside `frontend/layouts/base.php`, injecting `window.APP` (CSRF, user, school theme/branding, Ethiopian calendar picker via `calendar_system.php`) and loading page-specific ES/JS modules (`frontend/js/*.js`) with no inline CSS or SQL in HTML shells.
3. **Dashboard Routing & Super Admin Impersonation (`admin/dashboard.php`, `admin/api_impersonate.php`, `school_admin.php`, `super-admin.php`):**
   - `admin/dashboard.php` routes `$_SESSION['admin_role']` to the matching dashboard (e.g., `super_admin`, `school_admin`, `finance_dept`, `material_dept`, `photo_dept`, etc.).
   - `admin/api_impersonate.php` allows **both `super_admin` and `school_admin`** to switch `$_SESSION['admin_role']` into any department role (`action=switch`, `role=...`), storing `$_SESSION['original_admin_role']`, logging every switch/restore to `activity_logs`, and prompting `admin/dashboard.php` to inject the in-flow `#impersonateBar` ("Back to School Admin") banner at the top of `<body>`.
   - **Important SSMS Gap Discovered:** `admin/dashboards/school_admin.php` (`#section-departments`) renders interactive department cards with `switchRole(...)` buttons and live KPIs, whereas `admin/dashboards/super-admin.php` (`#section-departments`) currently renders only static non-interactive cards without `switchRole()` buttons and lacks live cross-department financial/asset telemetry. Integrating QIDUS ASSET must wire **interactive `switchRole()` + executive analytics into both `super-admin.php` and `school_admin.php`**.
4. **REST API v1 & Mobile Auth (`api/v1/index.php`, `api/v1/core/auth.php`, `acl.php`, `middleware.php`, `RefreshTokenService.php`):**
   - `/api/v1/{resource}/{id?}/{subAction?}` routes cleanly to `api/v1/routes/*.php`.
   - Uses 15-minute HMAC-SHA256 JWT access tokens (`uid`, `usr`, `rol`, `nam`, `av` authorization version, `fid`/`rsid` refresh binding) + 90-day rotating refresh tokens (`RefreshTokenService`).
   - `api/v1/core/acl.php` enforces server-side role/scope guards (including `av` authorization-version revalidation on privilege changes/deactivation).
5. **Ethiopian Calendar, Notifications, Audit & Search:**
   - `admin/backend/ethiopian_date.php` and `calendar_system.php` provide dual Ethiopian/Gregorian date conversion.
   - `NotificationCenterService.php` & `notifications` table deliver in-app notification feeds + bell badge across dashboards and `/api/v1/notifications`.
   - `activity_logs` and `wbws_audit_log` record administrative actions; `members` (`member_code`, `student_name`, `full_name_am`, `status`) stores Sunday School members.
   - Existing Flutter app (`Mobile/wbws_flutter_app`) uses SQLite offline tables, idempotent sync outboxes, Ethiopian calendar widgets, and `BrandPalette` / `AppConfig` school-specific theming.

### 3.2 Why Existing SSMS `finance_dept` & `material_dept` Are Being Replaced From Zero
Inspection of `admin/api_finance.php`, `admin/api_material.php`, `admin/dashboards/material_department.php`, `frontend/pages/finance_dept.php`, and `sql/legacy/php-migrations/backend/004_add_finance_material_tables.php` confirms why patching the old modules is unsafe:
1. **Violates Financial Immutability:** `admin/api_finance.php:204` literally executes `DELETE FROM finance_transactions WHERE id=?`—allowing hard-deletion of confirmed financial records without reversal journals or audit trails.
2. **Missing Core Finance & Governance Capabilities:** No annual department budget allocations/reallocations, no budget-enforcement gate, no bank accounts or petty cash ledgers, no bank statement reconciliation, no receipt/document attachment vault, and no multi-step approval workflow.
3. **Missing Procurement & Document Modules Entirely:** Zero tables or endpoints exist for purchase requisitions, supplier quotations, comparison matrices, POs, goods receiving inspection, or controlled document management.
4. **Inadequate Material Module (`material_department.php` / `api_material.php`):**
   - Built as a single monolithic 40KB PHP file with inline CSS/JS and non-normalized `requested_by VARCHAR(100)` text instead of user/department foreign keys.
   - Single-step `pending/approved/denied` status with no Department Head → Deputy Chairperson hierarchy, no asset QR/tag IDs, no drawn-signature handovers, and no stock-check / goods-receiving bridge to Finance/Procurement.
5. **Zero Mobile `/api/v1/` Support:** Neither `finance` nor `material` exists in `api/v1/index.php` `$routeMap`—the mobile app cannot talk to them at all.

**Cutover Strategy:** Legacy `api_finance.php`, `api_material.php`, `material_department.php`, old `frontend/pages/finance_dept.php` / `frontend/js/finance.js`, and legacy `finance_*` / `material_*` tables will be cleanly deprecated/archived and replaced from scratch by the new `qa_*` schema, `/api/v1/` endpoints, and separated `web/` dashboards. (If historical SSMS data exists in production `finance_transactions` or `material_items`, a one-time idempotent import script can migrate historical rows into `qa_fin_transactions` and `qa_assets` before dropping/archiving legacy endpoints.)

---

## 4. Dual-Mode Architecture: Standalone + SSMS Integrated

To guarantee QIDUS ASSET works **both** as an independent product and as a first-class SSMS subsystem without duplicating business logic, the platform uses a **Clean Core + Host Adapter** design:

```text
+-------------------------------------------------------------------------+
|                    CLIENTS (Role-Scoped & Bilingual)                    |
|   [ New QIDUS ASSET Flutter App ]     [ Separated Web Dashboards ]      |
|     - Asset Dept / Finance Dept         - Asset Dept Console            |
|     - Leadership / Auditor              - Finance/Proc/Docs Console     |
|     - Offline SQLite + Sync Outbox      - Exec / Auditor / SuperAdmin   |
+------------------------------------+------------------------------------+
                                     | HTTPS (JWT + Session/CSRF)
+------------------------------------v------------------------------------+
|                      QIDUS ASSET REST API (/api/v1)                     |
|   Auth & RBAC Middleware | Idempotency | Ethiopian Date | File Vault    |
+------------------------------------+------------------------------------+
                                     |
+------------------------------------v------------------------------------+
|                    QIDUS ASSET DOMAIN CORE (PHP 8)                      |
|  [Asset Engine]  [Finance/Budget/Bank]  [Procurement]  [Doc Vault]      |
|  [2-Step Approval State Machine]  [Audit Trail]  [PDF/XLSX Reports]     |
+------------------------------------+------------------------------------+
                                     | Adapter Interfaces
        +----------------------------+----------------------------+
        | STANDALONE ADAPTER                                      | SSMS INTEGRATED ADAPTER
        v                                                         v
+------------------------------+                     +----------------------------------+
| Standalone Host Runtime      |                     | SSMS Host (fkss-sunday-school)   |
| - qa_users & qa_departments  |                     | - Shares SSMS `users` + JWT/Sess |
| - qa_auth_refresh_tokens     |                     | - Reads SSMS `members` for fees  |
| - Built-in payer directory   |                     | - Writes to `notifications` &    |
| - qa_notifications & logs    |                     |   `activity_logs`                |
+------------------------------+                     | - SuperAdmin/SchoolAdmin         |
                                                     |   switchRole() -> asset_dept /   |
                                                     |   finance_dept + Exec Analytics  |
                                                     +----------------------------------+
```

### 4.1 Five Adapter Contracts (Zero Core Rewrites Between Modes)
| Adapter Interface | Standalone Implementation | SSMS Integrated Implementation |
|---|---|---|
| `IdentityAdapter` | Reads `qa_users`, issues standalone JWT + PHP session | Shares SSMS `users`, `RefreshTokenService`, `api/v1/core/auth.php`, & session `$_SESSION['admin_id']` |
| `RoleDepartmentAdapter` | Maps `qa_users.role` + `qa_user_departments` | Maps SSMS `users.role` (`asset_dept`, `finance_dept`, `super_admin`, `school_admin`, plus scoped QIDUS sub-roles `qa_role` / `department_id` in `qa_user_profiles`) |
| `MemberDirectoryAdapter` | Uses `qa_payers` (external/standalone member table) | Queries SSMS `members` table (`id`, `member_code`, `student_name`, `full_name_am`, `status`) directly |
| `NotificationAdapter` | Writes to `qa_notifications` + optional Email/SMS webhook | Writes to SSMS `NotificationCenterService` (`notifications` table) + optional Email/SMS webhook |
| `AuditSinkAdapter` | Writes immutable rows to `qa_audit_logs` | Writes immutable rows to `qa_audit_logs` AND mirrors major role/admin events to SSMS `activity_logs` |

### 4.2 Super Admin & School Admin Integration in SSMS
To satisfy the requirement that **Super Admin can log in to either new department with full access and analyze/report/track everything**:
1. **SSMS Dashboard & Impersonation Integration:**
   - Update `admin/api_impersonate.php` `$allowedRoles` to include `asset_dept` (and `qa_chairperson`, `qa_deputy_chair`, `qa_auditor` or unified `asset_dept` / `finance_dept` + `qa_sub_role` context).
   - Update `admin/dashboard.php` switch statement so `asset_dept` opens `/web/pages/asset_dept.php` (or `/frontend/pages/asset_dept.php`) and `finance_dept` opens `/web/pages/finance_dept.php`, preserving the in-flow `#impersonateBar` ("Back to School Admin / Super Admin") banner.
   - Update `admin/access_control.php` `$ROLE_MAP` and `FeatureGate.php` (`FEATURE_ASSET`, `FEATURE_FINANCE`, `FEATURE_PROCUREMENT`, `FEATURE_DOCUMENTS`).
   - Add interactive **Asset Dept** & **Finance Dept** cards with `switchRole('asset_dept')` / `switchRole('finance_dept')` and live KPI badges to **both** `admin/dashboards/super-admin.php` and `admin/dashboards/school_admin.php`.
2. **Super Admin Cross-Department Analytics, Tracking & Reporting:**
   - Dedicated **Executive & Audit Overview** widget/endpoint (`GET /api/v1/qa/executive/summary`) exposed inside `super-admin.php` and `school_admin.php`:
     - Total asset valuation, active asset count, pending handovers, overdue maintenance/returns.
     - Fiscal-year budget vs. actual spend, cash + bank balances, monthly contribution collection rate, unreconciled bank lines.
     - Pending approvals awaiting Department Head or Deputy Chairperson, open procurement POs, and recent immutable audit/variance alerts.
   - **Full Accountability When Impersonating:** Every action performed by Super Admin while switched into `asset_dept` or `finance_dept` records both the acting `actor_user_id` (the Super Admin's real user ID) and `acting_role` / `impersonated_by` in `qa_audit_logs` and `activity_logs`.
