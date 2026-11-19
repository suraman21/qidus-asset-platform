# 01 — Deep System Analysis & SSMS Integration Architecture

## 1. Synthesis of All Three Requirement Specifications

The QIDUS ASSET platform unifies three source specifications into a single coherent architecture:

1. **`QIDUS_ASSET_Backend_Team_Handoff.pdf` (4 pages, English):**
   - Defines the bilingual (Amharic & English) Flutter Android integration contract, 6 core governance roles (`System Admin`, `Chairperson`, `Deputy Chairperson`, `Department Head`, `Department Staff`, `Auditor`), two-stage request approvals (`PENDING_DEPARTMENT_HEAD -> PENDING_DEPUTY_CHAIRPERSON -> APPROVED/REJECTED`), drawn-signature handovers, QR asset lookup, notifications, audit trail, and prototype limitations (`SharedPreferences`, unauthenticated role switcher).

2. **`QIDUS_ASSET_Budget_Document_Procurement_Plan.pdf` (4 pages, Amharic):**
   - Defines the full operational scope of **Budget Management**, **Document Management**, and **Procurement Management**, establishing two critical mandates:
     - **Backend Alignment:** Follow the host API/database architecture rather than creating an incompatible silo.
     - **Financial Immutability:** *"የተፈጸመ የገንዘብ ግብይት በቀጥታ እንዳይሰረዝ፤ ስህተት ሲኖር በማስተካከያ መዝገብ እንዲታረምና የለውጥ ታሪኩ እንዲቀመጥ"* — **never hard-delete executed financial transactions**; post reversal/adjustment entries with full history.
     - **Guiding Principle:** *"Every Birr, Every Asset, Every Document — Accounted For."*

3. **`Asset_Management_System_PRD_v1.0.pdf` (17 pages, English — v1.0 Baseline):**
   - Elevates **Department 1 (Asset Management)** to production-grade rigor across **20 Functional Requirements (`FR-01`–`FR-20`)** and **Section 15 open-decision resolutions**:
     - **Independent School Deployment & cPanel Baseline:** Plain **PHP 8.2+** (no Laravel/Symfony), **MySQL/MariaDB**, **Apache/cPanel shared hosting** (no persistent Node.js/Python/Java daemons; pre-packaged Composer `vendor/`). Not a multi-tenant SaaS.
     - **Mobile-First & Advanced Offline-First:** Every essential asset workflow must exist in the Flutter Android app (**Drift + encrypted SQLite**, durable outbox, UUID idempotency keys, signed time-limited offline permission leases, explicit conflict surfaces).
     - **Three Distinct Tracking Modes:** (1) Individual Fixed Assets, (2) Quantity-Based Stock/Consumables, and (3) **Reusable Member-Issued Items** (clothing/uniforms by size/variant).
     - **Rapid Multi-Station Event Issue & Return:** Event templates, pre-filtered member packages, continuous SSMS member QR/card scanning, multi-station stock partitioning/allocation, and end-of-event reconciliation.
     - **Year-Based Workspaces:** Active school year default, prior years read-only unless reopened with audit entry, lifetime asset identity preserved continuously across years.
     - **Android Keystore Biometric-Protected Transaction Signing:** Never collect/store raw fingerprint images or templates; use per-user/per-device cryptographic keys protected by `local_auth` + Android Keystore to sign transaction challenges, verified server-side via stored public keys, alongside drawn signatures and PIN/supervisor fallback.
     - **Mandatory Replaceable Member-System Connector:** Isolates Asset & Finance business logic from SSMS internal tables using stable external member IDs, paginated initial sync + incremental cursor + tombstones (`SyncChangeFeedService`), retaining historical asset/fee records even if a member is renamed, transferred, or deactivated.

### Reconciling Document Boundaries with the Two-Department Mandate
Note that `Asset_Management_System_PRD_v1.0.pdf` scoped itself to **Asset Management** (treating Finance and org-wide DMS as external touchpoints), whereas the **Amharic Plan PDF** and product leadership mandate **two full separate departments** inside `qidus-asset-platform`:
- **Department 1 (`asset_dept`):** Implements 100% of `Asset_Management_System_PRD_v1.0.pdf` (`FR-01`–`FR-20`).
- **Department 2 (`finance_dept`):** Implements 100% of `QIDUS_ASSET_Budget_Document_Procurement_Plan.pdf` (Budget & Finance, Bank Reconciliation, Member Contributions, Procurement, and Document Management).
- **Modular Decoupling:** Because Dept 1 communicates with Dept 2 and SSMS through clean PHP service interfaces (`StockCheckPort`, `BudgetCheckPort`, `DocumentVaultPort`, `MemberConnectorInterface`, `IdentityAuthorityInterface`), a school can install **both departments together** OR configure Dept 1's ports to point to an external finance/document system.

---

## 2. Two-Department Governance & Role Matrix

### Department 1: Asset Department (`asset_dept` — የንብረት ክፍል)
- **Roles mapped from PRD §3 & Handoff §2:**
  - `asset_admin` (Asset Application Admin — local categories, locations, numbering, year workspaces, connector config; never overrides SSMS identity authority)
  - `asset_head` (Department Head — departmental oversight, Stage-1 request approvals, discrepancy/adjustment approvals)
  - `asset_officer` (Storekeeper/Staff — asset registration, QR labels, stock movements, handovers, PO goods receiving)
  - `event_operator` (Event Issue/Return Operator — scoped strictly to assigned event packages, member lists, and station stock allocations)
  - `maintenance_officer` (Maintenance & Repair — repair tickets, schedules, warranty, condition updates)

### Department 2: Finance & Related Department (`finance_dept` — የበጀት፣ የፋይናንስ፣ የሰነድና የግዢ ክፍል)
- **Roles mapped from Amharic Plan §5 & Handoff §2:**
  - `finance_head` (Finance & Related Dept Head — budget allocations, Stage-1 budget/procurement/document approvals, reconciliation sign-off)
  - `finance_staff` (Finance Officer — income/expense ledger entries, reversal/adjustment proposals, bank/petty cash, reconciliation, member contributions)
  - `procurement_officer` (Procurement Officer — requisitions, supplier directory, quotation comparison, PO issuance, delivery coordination)
  - `document_controller` (Document Staff — document indexing, folders, metadata, version upload, access log review)

### Cross-Cutting Authority & Oversight Roles
- **SSMS Super Admin / System Admin (`super_admin` / `school_admin` / `system_admin`):** Central account lifecycle & authentication authority, cross-department executive analytics, and full department impersonation (`asset_dept` / `finance_dept`) with immutable audit attribution.
- **Chairperson (`chairperson`):** Executive cross-department read visibility, high-threshold approvals, policy oversight.
- **Deputy Chairperson (`deputy_chairperson`):** Stage-2 final approval authority (`PENDING_DEPUTY_CHAIRPERSON -> APPROVED/REJECTED`) across asset requests, disposals, budget reallocations, and procurement approvals.
- **Inspector / Auditor (`auditor`):** Cross-department read-only access, physical inventory inspection campaigns, bank/ledger verification, immutable audit log inspection, and Audit Findings / Corrective Action tracking.
- **Department Staff / Requesters (`requester`):** Submit asset or procurement requests for their own department and track status.

*Separation-of-Duty Rule:* A requester cannot approve their own high-risk request, handover/inventory adjustments cannot silently alter signed history, and **no role may hard-delete confirmed financial transactions, assets with history, or audit logs**.

---

## 3. Deep Analysis of Existing SSMS (`fkss-sunday-school-platform`) & Connector Findings

Direct inspection of `fkss-sunday-school-platform` resolves the open integration dependencies listed in **PRD Section 15**:

### 3.1 Verified SSMS Capabilities
1. **Authentication & Session/Token Authority (`api/v1/core/auth.php`, `RefreshTokenService.php`, `admin/access_control.php`):**
   - **Mobile Auth:** SSMS issues 15-minute HMAC-SHA256 JWT access tokens (`uid`, `usr`, `rol`, `nam`, `av`, `fid`, `rsid`) + 90-day rotating refresh tokens (`POST /api/v1/auth/login` & `/auth/refresh`). **SSMS does NOT use OIDC** — the connector uses SSMS JWT + `av` (authorization version) revalidation so deactivated/role-changed SSMS users are blocked immediately on token refresh or protected calls.
   - **Web Auth & Super Admin Impersonation:** `admin/dashboard.php` + `admin/api_impersonate.php` switch `$_SESSION['admin_role']`, store `$_SESSION['original_admin_role']`, render the in-flow `#impersonateBar` banner, and write to `activity_logs`.
   - **Gap Found in `super-admin.php`:** `school_admin.php` has interactive `switchRole()` cards while `super-admin.php` has static department cards. QIDUS integration adds interactive `switchRole('asset_dept')` & `switchRole('finance_dept')` cards + live executive telemetry to **both** `super-admin.php` and `school_admin.php`.
2. **Member Directory & Incremental Sync (`api/v1/routes/members.php`, `SyncChangeFeedService.php`, `api/v1/routes/sync.php`):**
   - SSMS exposes `apiMemberSafeRow()` (`id`, `member_code`, `student_name`, `father_name`, `grandfather_name`, `full_name_am`, `gender`, `age_group`, `status`, `member_type`, `current_section`, `current_class`, `photo_url`) without leaking PII (phones/addresses).
   - SSMS already has a monotonic `sync_changes` revision-cursor engine (`SyncChangeFeedService`, currently `SUPPORTED_ENTITIES = ['attendance']`) supporting `{op: DELETE}` tombstones, retention floors, and permission scoping. Extending it to `'members'` gives `SSMSConnector` native incremental delta sync + tombstones!
3. **Ethiopian Calendar, Notifications, Audit & Feature Gates:**
   - `admin/backend/ethiopian_date.php` & `calendar_system.php` + `academic_years` provide authoritative school-year and Ethiopian/Gregorian date boundaries.
   - `NotificationCenterService` (`notifications` table) and `activity_logs` are already centralized.
   - `FeatureGate.php` and `admin/access_control.php` enforce server-authoritative module & route RBAC.

### 3.2 Why Legacy SSMS `finance_dept` & `material_dept` Are Retired From Zero
- `admin/api_finance.php:204` permits `DELETE FROM finance_transactions WHERE id=?` (violating financial immutability).
- No bank/petty cash accounts, no reconciliation, no budget amendments/encumbrances, no procurement/quotations/POs, no document vault, no 2-step approval workflow, no QR/reusable-clothing/event operations, no biometric Keystore verification, no offline sync outbox, and zero `/api/v1/` endpoints.
- `admin/dashboards/material_department.php` is a monolithic legacy PHP page.

**Cutover Rule:** Build the new `qa_*` schema, plain-PHP `/api/v1/qa/*` backend, separated `web/` UI, and Flutter app cleanly from zero; migrate any needed historical rows via an idempotent one-time script; then retire legacy `api_finance.php`, `api_material.php`, `material_department.php`, and old `frontend/pages/finance_dept.php`.

---

## 4. Dual-Mode Deployment & Hosting Architecture

### 4.1 Hosting Baseline (PRD §13)
- **Server:** Plain **PHP 8.2+** on **Apache / cPanel shared hosting**, **MySQL 8 / MariaDB 10.6+**.
- **Zero Persistent Non-PHP Daemons:** No Node.js, Python, or Java background servers required. Optional cPanel cron handles scheduled maintenance (overdue return reminders, backup rotation, stale-lease cleanup); all core workflows execute synchronously in standard HTTP request/response cycles.
- **Composer Libraries (Pre-bundled `vendor/`):**
  - `chillerlan/php-qrcode` (QR code SVG/PNG label generation)
  - `dompdf/dompdf` (Bilingual Amharic/English PDF reports with embedded Ethiopic TTF fonts)
  - `phpoffice/phpspreadsheet` (Excel `.xlsx` exports & bank statement CSV/XLSX imports)
  - `monolog/monolog` (Structured JSON/file error & sync logging)
  - `robmorgan/phinx` (CLI/deployment-time database migrations only)
  - `guzzlehttp/guzzle` (HTTP client for standalone-to-SSMS connector calls)

### 4.2 Two Supported Topologies
1. **Independent School Deployment (Standalone + SSMS HTTP Connector):**
   - Installed in its own cPanel directory/subdomain (e.g., `assets.school.org`), with its own MySQL database (`qa_*` tables), private upload vault outside `public_html`, and `SSMSConnector` calling SSMS REST endpoints (`/api/v1/auth/*`, `/api/v1/members`, `/api/v1/sync/changes?entity=members`) over HTTPS.
2. **Embedded SSMS Deployment (Same Host / Direct DB + SSO):**
   - Mounted inside `fkss-sunday-school-platform` sharing the same database (`qa_*` tables alongside SSMS tables) and local `DirectSSMSConnector` (zero HTTP hop for member lookup, notifications, sessions, and `api_impersonate.php` Super Admin role switching), while still exposing the exact same `/api/v1/qa/*` REST contract to the Flutter app.
