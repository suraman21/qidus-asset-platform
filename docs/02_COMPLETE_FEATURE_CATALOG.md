# 02 — Complete Feature Catalog (Two-Department Breakdown)

This catalog combines every requirement from **`QIDUS_ASSET_Backend_Team_Handoff.pdf`** and **`QIDUS_ASSET_Budget_Document_Procurement_Plan.pdf`**, organized into **two independent departments** plus shared governance, web console, and Flutter mobile capabilities.

---

## Summary Matrix

| Area | Module Code | Bilingual Name | Primary Owner |
|---|---|---|---|
| **Department 1** | `ASSET` | Asset Department (**የንብረት ክፍል**) | `asset_head` / `asset_staff` |
| **Department 2A** | `FIN_BUDGET` | Budget & Finance (**የበጀትና ፋይናንስ ክፍል**) | `finance_head` / `finance_staff` |
| **Department 2B** | `PROCUREMENT` | Procurement Management (**የግዢ ክፍል**) | `finance_head` / `procurement_staff` (+ Asset stock check & receiving) |
| **Department 2C** | `DOCUMENTS` | Document Management (**የሰነድ አያያዝ ክፍል**) | `finance_head` / `finance_staff` (with cross-dept access rules) |
| **Shared Platform** | `CORE_GOV` | Auth, RBAC, 2-Step Approvals, Notifications, Audit, Reports, Mobile Sync, SSMS Super Admin | `system_admin`, `chairperson`, `deputy_chairperson`, `auditor`, SSMS `super_admin` |

---

## PART 1 — Department 1: Asset Department (`asset_dept` — የንብረት ክፍል)

### 1.1 Asset & Inventory Registry (`ASSET-01`)
- **ASSET-01.1 Unique Asset Identification:** System-generated unique human-readable asset tag (`AST-YYYY-NNNNN`) + immutable UUID + QR payload for every fixed asset and tracked batch.
- **ASSET-01.2 Asset Classification:** Distinguish between **Fixed Assets** (serialized/QR-tagged durable property: electronics, furniture, liturgical items, instruments) and **Consumable Stock** (quantity-tracked supplies with minimum reorder thresholds).
- **ASSET-01.3 Core Asset Attributes:** Bilingual name (`name_en`, `name_am`), category (`qa_asset_categories`), description, serial/model number, quantity & unit, minimum stock threshold, physical location/room, custodian/assigned department, acquisition source (`purchase`, `donation`, `transfer`), acquisition date (Ethiopian & Gregorian), unit cost / valuation, condition (`new`, `good`, `fair`, `poor`, `damaged`, `under_repair`, `disposed`), lifecycle status (`in_stock`, `assigned`, `low_stock`, `out_of_stock`, `maintenance`, `archived`), and photo gallery.
- **ASSET-01.4 CRUD & Soft Archiving:** Create, view, search, filter, update, and soft-archive assets (never hard-deleting assets that have movements, requests, or handover history).
- **ASSET-01.5 Asset History Timeline:** Unified chronological ledger per asset showing creation, stock movements, assignments, handovers, condition inspections, maintenance events, and disposal.

### 1.2 QR Code Generation & Mobile Resolution (`ASSET-02`)
- **ASSET-02.1 QR Tag Generation:** Generate printable QR labels (single asset label or batch A4 label sheets with school logo, asset code, and Amharic/English title).
- **ASSET-02.2 QR Scan & Server Resolution:** `GET /api/v1/qa/assets/resolve/{qrCode}` resolves scanned QR/barcode strings directly to the full asset record, current custodian, location, condition, and pending handover/request actions.
- **ASSET-02.3 Offline QR Cache:** Flutter app caches authorized asset directory + QR index in SQLite for offline field lookup during physical inventory audits.

### 1.3 Stock Movements, Maintenance & Disposal (`ASSET-03`)
- **ASSET-03.1 Stock Ledger:** Record `incoming`, `outgoing`, `transfer`, `adjustment`, `return`, `maintenance_out`, `maintenance_in`, and `disposal` transactions with reason, reference document, actor, and timestamp.
- **ASSET-03.2 Low-Stock & Maintenance Alerts:** Automatic in-app notifications when consumable quantity drops to `<= min_quantity` or scheduled maintenance is due.
- **ASSET-03.3 Asset Disposal Workflow:** Request and approve write-off/disposal of damaged or obsolete items with reason, photos, approval trail, and optional Document Vault certificate.

### 1.4 Asset Requests & Two-Step Approval Workflow (`ASSET-04`)
Implements Section 4 of the Handoff PDF strictly on the server:
- **ASSET-04.1 Request Submission:** Staff/department user submits asset request specifying item (existing catalog item or requested specification), quantity, target department, urgency, needed-by date, and reason. Initial status is always forced server-side to **`PENDING_DEPARTMENT_HEAD`**.
- **ASSET-04.2 Stage 1 — Department Head Review:** Only the authorized Department Head (or higher override with audit reason) can approve/reject at `PENDING_DEPARTMENT_HEAD`:
  - Approve → status becomes **`PENDING_DEPUTY_CHAIRPERSON`**.
  - Reject → status becomes **`REJECTED`** (with mandatory rejection comment).
- **ASSET-04.3 Stage 2 — Deputy Chairperson Final Decision:** Only `deputy_chairperson` (or `super_admin` in emergency override) can act on `PENDING_DEPUTY_CHAIRPERSON`:
  - Approve → status becomes **`APPROVED`** (ready for Asset Handover/Fulfillment).
  - Reject → status becomes **`REJECTED`** (with mandatory reason).
- **ASSET-04.4 Immutable Decision Trail:** Every state transition records `actor_id`, `actor_role`, decision timestamp, remarks, and triggers notifications to requester and next approver.
- **ASSET-04.5 Cancellation & Fulfillment:** Requester may cancel while still `PENDING_DEPARTMENT_HEAD`; Asset Staff marks `FULFILLED` only once linked to a completed Handover record.

### 1.5 Asset Handover & Digital Signature Evidence (`ASSET-05`)
- **ASSET-05.1 Handover Execution:** Issue or return assets against an `APPROVED` request (or direct department transfer/return). Captures sender user, receiver user/custodian, item(s), issued quantity, condition at handover, handover notes, and server timestamp.
- **ASSET-05.2 Drawn Signature Capture:** Mobile signature pad captures sender & receiver drawn signatures as PNG files stored securely on the server (with SHA-256 hash & signature metadata stored in `qa_asset_handovers`; explicitly documented as drawn-signature evidence, not biometric verification).
- **ASSET-05.3 Return & Custody Tracking:** Track temporary loans/assignments with expected return date, overdue alerts, and return handover condition inspection.
- **ASSET-05.4 Handover Voucher PDF:** Printable/shareable bilingual handover receipt attached automatically to Document Management if configured.

### 1.6 Cross-Department Bridges (Asset Dept ↔ Finance/Procurement) (`ASSET-06`)
- **ASSET-06.1 Pre-Procurement Stock Check:** Before a Procurement Request moves forward, Asset Dept verifies whether the requested item is already available in stock (`available_qty`, `reserved_qty`, `stock_check_status`: `NOT_IN_STOCK` / `PARTIAL_STOCK` / `IN_STOCK_FULFILLABLE`), preventing duplicate purchases.
- **ASSET-06.2 Goods Receiving & Fixed-Asset Minting:** When a Procurement Order arrives, Asset Dept performs physical inspection (ordered vs. received quantity, quality/condition check, receiver signature) and **automatically creates new fixed-asset records (with QR tags)** or increments consumable stock balances.

### 1.7 Physical Inventory & Audit Count (`ASSET-07`)
- **ASSET-07.1 Cycle Count Campaigns:** Start periodic inventory audits by location or category, scan QR tags via mobile app, record counted vs. system quantity, flag missing/damaged discrepancies, and generate an Asset Variance Report for the Auditor and Chairperson.

---

## PART 2 — Department 2: Finance & Related Department (`finance_dept` — የበጀት፣ የፋይናንስ፣ የሰነድና የግዢ ክፍል)

### 2A. Budget & Finance Management (`FIN_BUDGET`)

#### 2A.1 Annual Budget & Department Allocations (`FIN-01`)
- **FIN-01.1 Fiscal Year Setup:** Define fiscal/Ethiopian budget years (`ec_year`, start/end dates, status: `draft`, `active`, `closed`).
- **FIN-01.2 Budget Lines & Allocations:** Allocate approved annual budget amounts by **department** and **expense category/line item** with description, approval reference, and effective dates.
- **FIN-01.3 Budget Amendments & Reallocations (`የበጀት ማሻሻያና ዳግም ምደባ`):** Increase, decrease, or transfer budget between two budget lines (`from_budget_line_id` → `to_budget_line_id`). Every amendment records requester, justification, approver (`deputy_chairperson` / `chairperson`), approval status, and timestamp—never silently overwriting the original approved allocation.
- **FIN-01.4 Real-Time Budget Execution & Remaining Balance:** Automatically compute `original_approved + amendments_net - committed_procurement - actual_expenses = remaining_available_budget` per line item, per department, and school-wide.

#### 2A.2 Income & Expense Ledger (`FIN-02`)
- **FIN-02.1 Income & Expense Entries:** Record categorized income (contributions, offerings, donations, events, etc.) and expenses (teaching materials, maintenance, utilities, procurement payments, etc.) with amount (`DECIMAL(14,2)`), currency (`ETB` default), payment method (`cash`, `bank_transfer`, `mobile_money`, `check`), bank/cash account reference, voucher/receipt number, Ethiopian & Gregorian date, payer/payee, and description.
- **FIN-02.2 Mandatory Evidence Attachments:** Attach receipt scans/photos or link directly to a stored Document Management file (`document_id`).
- **FIN-02.3 Strict Immutability — No Hard Deletes (`FIN-02.4` Mandate):**
  - **Executed/confirmed financial transactions can NEVER be deleted or silently overwritten.**
  - Corrections require posting a **Reversal / Adjustment Entry** (`entry_kind`: `STANDARD`, `REVERSAL`, `ADJUSTMENT`, referencing `reverses_transaction_id`) with mandatory reason, creator, approver, and full change history.

#### 2A.3 Bank Accounts & Petty Cash Management (`FIN-03`)
- **FIN-03.1 Cashbook & Bank Accounts Registry:** Maintain multiple organization bank accounts (bank name, branch, account number masked/stored safely, opening balance) and petty-cash drawers/custodians.
- **FIN-03.2 Deposits, Withdrawals & Inter-Account Transfers:** Track cash deposits to bank, bank withdrawals to petty cash, and account-to-account transfers as paired balanced entries so total cash + bank position is always exact.
- **FIN-03.3 Real-Time Account Balances:** Running balance ledger per bank account and petty cash box.

#### 2A.4 Bank Statement Reconciliation (`FIN-04`)
- **FIN-04.1 Statement Import / Entry:** Import CSV/Excel bank statement lines or enter periodic bank statement balances and line items for a date range.
- **FIN-04.2 Transaction Matching:** Match ledger transactions against bank statement lines (exact or manual match), track reconciliation status (`unmatched`, `matched`, `discrepancy`, `resolved`), and record explanatory notes for bank charges, unpresented checks, or deposits in transit.
- **FIN-04.3 Reconciliation Sign-Off:** Monthly reconciliation summary approved by Finance Head and reviewable by Auditor.

#### 2A.5 Monthly Member Contributions (`FIN-05`)
- **FIN-05.1 Member Fee Schedules:** Define monthly contribution rates (and optional special pledges/dues) per Ethiopian month (`ec_month` 1–13) and year (`ec_year`).
- **FIN-05.2 Payer Roster Integration:**
  - In **SSMS Integrated Mode**, pull active members from SSMS `members` (`member_code`, `student_name`, `full_name_am`, `section`).
  - In **Standalone Mode**, manage members/contributors via built-in `qa_payers` directory.
- **FIN-05.3 Payment Recording & Paid/Unpaid Tracking:** Record full or partial payments, issue receipt numbers, view paid vs. unpaid vs. overdue member lists by month/section, and auto-post confirmed collections into the Finance Income Ledger.

---

### 2B. Procurement Management (`PROCUREMENT`)

#### 2B.1 Purchase Requisition & Dual-Gate Validation (`PROC-01`)
- **PROC-01.1 Purchase Request Creation:** Any authorized department submits a requisition specifying requested item(s)/service(s), quantity, specification, justification, estimated unit/total cost, target budget line, and required-by date.
- **PROC-01.2 Gate 1 — Asset Stock Verification (`የንብረት ክምችት ማረጋገጥ`):** Before proceeding to approval, the workflow requires stock verification against Asset Dept inventory (recording `IN_STOCK` / `PARTIAL` / `NOT_IN_STOCK` and whether existing stock can fulfill part or all of the request).
- **PROC-01.3 Gate 2 — Budget Availability Enforcement (`የበጀት ማረጋገጫ`):** Server checks `remaining_available_budget` on the selected department/category budget line. **If insufficient budget exists, the backend blocks submission into the approval queue** until a budget amendment/reallocation is approved or scope is adjusted.

#### 2B.2 Supplier Directory & Quotation Comparison (`PROC-02`)
- **PROC-02.1 Supplier Registry:** Maintain approved vendors/suppliers (name, contact, phone, TIN/license info, bank details, category, performance notes).
- **PROC-02.2 Multi-Vendor Quotations (`የዋጋ ማወዳደር`):** Attach 1..N supplier quotations per procurement request (unit price, total price, delivery lead time, warranty terms, payment terms, quotation PDF/image stored in Document Vault).
- **PROC-02.3 Quotation Comparison Matrix:** Side-by-side comparison table highlighting lowest cost, delivery speed, warranty, and committee/finance selection rationale.

#### 2B.3 Hierarchical Approval & Purchase Order (PO) (`PROC-03`)
- **PROC-03.1 Multi-Stage Procurement Approval:** Configurable authority thresholds routing through Department Head → Finance/Budget Verification → Deputy Chairperson (and Chairperson above threshold), with full decision history & notifications.
- **PROC-03.2 Purchase Order Generation:** Upon final approval, lock/encumber the budget amount (`committed_amount`) and generate a uniquely numbered **Purchase Order (`PO-YYYY-NNNNN`)** containing selected supplier, itemized quantities, agreed prices, delivery deadline, and printable PDF.

#### 2B.4 Goods Receiving, Payment & Cross-Module Handoff (`PROC-04`)
- **PROC-04.1 Goods Receipt & Quality Inspection (`ዕቃ መረከብ`):** Compare delivered items against the PO (accept full, partial, or reject damaged goods), capture inspector & receiver signature, and record receiving notes.
- **PROC-04.2 Automatic Asset Dept Handoff:** Fixed assets / stock items marked as accepted on the Goods Receipt automatically queue or create Asset Registry entries (or consumable stock-in movements) in **Asset Dept** with `po_id` traceability.
- **PROC-04.3 Automatic Finance & Document Handoff:**
  - Supplier payment records link the PO, invoice, and receipt directly to a **Finance Expense Transaction** (releasing budget encumbrance into actual expense).
  - Quotations, PO PDFs, vendor invoices, payment vouchers, and signed receiving notes are automatically indexed inside **Document Management**.

---

### 2C. Document Management (`DOCUMENTS`)

#### 2C.1 Centralized Document Repository (`DOC-01`)
- **DOC-01.1 Multi-Format Upload & Validation:** Securely upload PDF, Word (`.docx`/`.doc`), Excel (`.xlsx`/`.xls`), and images (`.jpg`/`.png`/`.webp`) with server-side MIME/magic-byte verification, max size enforcement, SHA-256 checksum deduplication, and private out-of-web-root file storage (served only through authorized download endpoints).
- **DOC-01.2 Structured Metadata & Numbering:** Title (Amharic/English), unique document reference number (`DOC-YYYY-NNNNN` or official letter number), document type (`letter`, `decision`, `contract`, `receipt`, `invoice`, `quotation`, `purchase_order`, `handover`, `audit_report`, `other`), fiscal year, department, author/preparer, document date (Ethiopian & Gregorian), tags, and linked entity (`asset_id`, `transaction_id`, `procurement_id`).

#### 2C.2 Organization, Search & Lifecycle (`DOC-02`)
- **DOC-02.1 Hierarchical Folders & Categories:** Browse and filter documents by Fiscal Year, Department, Document Type, Approval Status, and custom folders.
- **DOC-02.2 Fast Search:** Search across title, document number, tags, author, department, date range, and linked transaction/asset/PO reference.
- **DOC-02.3 Document Approval Workflow (`የሰነድ ማጽደቅ`):** Submit draft official letters, contracts, or decisions for designated reviewer/approver sign-off (`DRAFT -> PENDING_APPROVAL -> APPROVED / RETURNED`), tracking approver remarks and approval date.

#### 2C.3 Security, Versioning & Audit Trail (`DOC-03`)
- **DOC-03.1 Role-Based Document Permissions:** Per-role and per-department access matrix (`view`, `download`, `upload`, `edit_metadata`, `approve`, `archive`) plus sensitive-document restriction flags (e.g., Leadership/Auditor/Finance-only).
- **DOC-03.2 Document Versioning:** Replacing a document file creates a new version (`v1`, `v2`, ...) while preserving prior versions for audit inspection.
- **DOC-03.3 Full Access & Change History (`የሰነድ ታሪክ`):** Immutable log of every upload, view, download, metadata edit, approval decision, and archive/restore request (`who`, `when`, `IP/device`, `action`).
- **DOC-03.4 Deletion Protection & Backup:** End users cannot permanently purge documents; "delete" performs a soft-archive (`is_archived = 1`) restricted to authorized admin governance, with automated backup support.

---

## PART 3 — Cross-Cutting Platform Features (`CORE_GOV`)

### 3.1 Authentication, RBAC & User/Department Management
- Secure password hashing (`Argon2id` / `bcrypt`), account activation/deactivation, password reset, brute-force rate limiting.
- Short-lived JWT access tokens + rotating refresh tokens with `authorization_version` (`av`) instant revocation on role change or deactivation (for mobile/API), plus HttpOnly/SameSite PHP sessions + CSRF tokens (for web).
- Server-enforced RBAC on every endpoint (`System Admin`, `Chairperson`, `Deputy Chairperson`, `Department Head`, `Department Staff`, `Auditor` + department scoping).
- Zero secrets or service keys ever embedded in mobile APK or client JS.

### 3.2 Notifications (In-App + Optional Server-Side Email/SMS)
- Real-time in-app notification center for: asset request submitted/approved/rejected, handover completed, low stock, budget amendment decision, procurement stage transitions, bank reconciliation alerts, document approval requests, and audit findings.
- Read/unread state (`PATCH /api/v1/qa/notifications/{id}/read`), mark-all-read, and deep link to target record.
- Optional server-side Email/SMS/Telegram dispatch adapter configured strictly via server environment secrets (never from mobile client).

### 3.3 Immutable Audit Trail & Auditor Workspace
- Append-only `qa_audit_logs` capturing every create, update, state transition, approval, rejection, financial reversal/adjustment, document download/edit, failed authorization attempt, and Super Admin impersonation context (`actor_user_id`, `acting_as_role`, `entity_type`, `entity_id`, `before_json`, `after_json`, `ip_address`, `created_at`).
- Dedicated **Auditor Workbench**: cross-department read-only explorer, variance checks (unreconciled bank items, budget overruns, unfulfilled approved requests, missing receipts, asset count variances), and **Audit Findings & Corrective Action Tracking** (`open`, `in_progress`, `resolved`, `verified`).

### 3.4 Bilingual Reporting & Exports (PDF + Excel `.xlsx`)
All reports support **Gregorian & Ethiopian date-range filtering**, department filtering, and one-click export to **PDF** (bilingual Amharic/English layout with school header/seal) and **Excel (`.xlsx`)**:
1. Monthly & Annual Income/Expense Statement.
2. Budget Allocation vs. Actual Execution & Remaining Balance Report.
3. Bank Account & Petty Cash Balance + Bank Reconciliation Report.
4. Monthly Member Contribution Collection Report (Paid / Partial / Unpaid lists).
5. Procurement Pipeline Report (Requests, Quotations Comparison, POs, Goods Received, Pending).
6. Asset Registry, Valuation, Custody/Handover, Low-Stock, Maintenance & Inventory Variance Reports.
7. Document Register & Change/Access Audit Report.
8. Audit Findings, Corrective Actions & System Activity Trail Report.

### 3.5 Web Dashboards (Separated Frontend Architecture)
- **Asset Dept Dashboard:** Inventory KPIs, low-stock & overdue-return alerts, asset & QR label manager, request review queue, handover history, incoming PO receiving queue, cycle-count audit panel.
- **Finance & Related Dept Dashboard:** Fiscal overview, budget allocation & amendment manager, immutable income/expense ledger (with reversal/adjustment drawer), bank & petty cash + reconciliation workbench, member contributions grid, procurement & quotation matrix, document vault & approval queue, PDF/Excel report builder.
- **Leadership / Deputy Chairperson / Auditor Portal:** Unified cross-department approval inbox, executive financial & asset KPIs, audit log & findings workspace.
- **SSMS Super Admin / School Admin Integration:** Interactive department cards in `super-admin.php` and `school_admin.php` to impersonate `asset_dept` or `finance_dept` with one click (`#impersonateBar` banner + audit logging) and live cross-department executive analytics.

### 3.6 New QIDUS ASSET Flutter Android Application
- **Bilingual Amharic & English UI** with Ethiopian + Gregorian calendar support and role-adaptive home dashboards.
- **Real Server Auth:** Login, secure token storage, refresh-token rotation, biometric/PIN app lock, and automatic permission-driven UI.
- **Asset & QR Field Operations:** Camera QR/barcode scanner → instant asset resolution, offline asset search, asset request submission, Department Head & Deputy Chairperson mobile approval inbox, digital handover form with **drawn signature canvas** & photo capture.
- **Finance, Procurement & Document Mobile Views:** View department budgets/balances, capture & submit expense receipts/member contribution payments, review & approve procurement requests/quotations/POs, inspect & upload supporting documents (PDF/photo).
- **Offline-First SQLite + Sync Outbox:** Durable local queue for field captures (requests, handovers, receipts, cycle counts) with UUID idempotency keys, conflict surfacing, and automatic background sync when connectivity returns.
