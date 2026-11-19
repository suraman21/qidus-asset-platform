# 02 — Complete Feature Catalog (Two-Department Breakdown)

This catalog consolidates **every requirement** from `Asset_Management_System_PRD_v1.0.pdf` (`FR-01`–`FR-20`), `QIDUS_ASSET_Budget_Document_Procurement_Plan.pdf`, and `QIDUS_ASSET_Backend_Team_Handoff.pdf` across **two independent departments** plus shared cross-cutting capabilities.

---

## Summary Matrix

| Area | Module Code | Bilingual Name | Spec Source Coverage |
|---|---|---|---|
| **Department 1** | `ASSET` | Asset Department (**የንብረት ክፍል**) | PRD `FR-01`–`FR-10`, `FR-15`–`FR-17` + Handoff + Plan Stock/GRN bridges |
| **Department 2A** | `FIN_BUDGET` | Budget & Finance (**የበጀትና ፋይናንስ ክፍል**) | Amharic Plan §1, §4, §6, §8 + Handoff §3/§5 + PRD §2.3/FR-07 touchpoints |
| **Department 2B** | `PROCUREMENT` | Procurement Management (**የግዢ ክፍል**) | Amharic Plan §3, §4 + PRD `FR-07` + Handoff §3/§5 |
| **Department 2C** | `DOCUMENTS` | Document Management (**የሰነድ አያያዝ ክፍል**) | Amharic Plan §2, §4 + PRD `FR-11` |
| **Shared Platform** | `CORE_GOV` | SSMS Connector, Offline Sync, Biometrics, Approvals, Notifications, Audit, Reports, Web & Mobile | PRD `FR-12`–`FR-14`, `FR-18`–`FR-20`, §8–§13 + Handoff §2–§10 + SSMS Super Admin |

---

## PART 1 — Department 1: Asset Department (`asset_dept` — የንብረት ክፍል)

### 1.1 Asset Registration & Three-Mode Tracking (`FR-01`, `FR-02`)
- **ASSET-01.1 Three Distinct Tracking Modes:**
  1. **Individual Fixed Assets (`fixed`):** Uniquely serialized/QR-tagged durable property (computers, projectors, furniture, liturgical/sound equipment) with per-unit custody, location, condition, warranty, and lifetime history.
  2. **Quantity-Based Stock (`consumable`):** Non-serialized supplies tracked by quantity, unit of measure, storage location, batch/lot (optional), minimum reorder level, and balance ledger.
  3. **Reusable Member-Issued Items (`reusable_member`):** Clothing, uniforms, vestments, or gear tracked individually or by **item type + size/variant** (`XS`, `S`, `M`, `L`, `XL`, etc.), issued temporarily to members and tracked until return.
- **ASSET-01.2 Unique Non-Reusable Asset Identity:** System-generated human-readable asset code (`AST-YYYY-NNNNN`) + immutable UUID + opaque QR token.
- **ASSET-01.3 Configurable Categories & Category-Specific Fields:** Categories and subcategories (`qa_asset_categories`) determine tracking mode, required attributes, QR policy, maintenance rules, and custom category schema fields (e.g., `size_chart` for clothing, `serial_no`/`warranty_until` for electronics).
- **ASSET-01.4 Core Asset Fields:** Bilingual names (`name_en`, `name_am`), description, specifications, serial/model/manufacturer, acquisition date (Ethiopian & Gregorian), source (`purchase`, `donation`, `transfer`, `opening`), supplier, PO reference, acquisition cost & currency (`ETB`), location, custodian, condition (`new`, `good`, `fair`, `poor`, `damaged`, `under_repair`, `disposed`), operating status (`in_stock`, `assigned`, `low_stock`, `out_of_stock`, `maintenance`, `archived`), and asset photographs/attachments.
- **ASSET-01.5 Lifetime Continuity & Soft Archiving:** Archiving removes an asset from active selection without erasing its identity or historical custody/movement/inspection/maintenance timeline.

### 1.2 Inventory & Stock Movement Ledger (`FR-02`)
- **ASSET-02.1 Traceable Movement Ledger:** Maintain balances by item, variant/size, location, and operational year. **Balances are never overwritten directly**—every change writes a movement (`incoming`, `outgoing`, `transfer`, `issue`, `return`, `adjustment`, `maintenance_out`, `maintenance_in`, `disposal`) with `quantity_delta`, `balance_after`, actor, and reason.
- **ASSET-02.2 Negative-Stock Protection:** Block outgoing issues/transfers that would drive stock negative unless an explicit, audited school policy override exists.
- **ASSET-02.3 Low-Stock & Threshold Alerts:** Automated notifications when stock drops to `<= min_quantity`.

### 1.3 QR Codes & Asset Identification (`FR-03`, PRD §9.1)
- **ASSET-03.1 Opaque / Signed QR Payloads:** QR payloads contain opaque asset tokens or signed references—**never plain-text member PII or sensitive financial data**. Asset QR identifies the *asset*, not the current custodian, so custody changes never require re-labeling.
- **ASSET-03.2 Printable Labels & Bulk Sheets:** Generate customizable single QR labels and bulk A4 PDF label sheets (`chillerlan/php-qrcode` + `Dompdf`) with school branding, asset code, and Amharic/English name.
- **ASSET-03.3 Mobile Scanning & Verification:** Fast camera scan (`mobile_scanner`) with flashlight, scan beep/haptic feedback, duplicate-scan guard, manual code fallback, and clear feedback for malformed, unknown, revoked, or duplicate codes (`GET /api/v1/qa/assets/resolve/{qrCode}`).
- **ASSET-03.4 Label Replacement History:** Reprint/replace damaged QR labels while preserving the underlying asset ID and logging revocation/reprint history.

### 1.4 Asset Assignment, Custody & Drawn/Biometric Handovers (`FR-04`, `FR-05`)
- **ASSET-04.1 Custody Assignments:** Assign assets to departments, locations, staff, or members with assigning operator, timestamp, expected return date, and condition at issue.
- **ASSET-04.2 Handovers & Return Outcomes:** Support complete issue, full return, partial return, transfer, reassignment, lost/missing/damaged reporting, and overdue return reminders.
- **ASSET-04.3 Drawn Signature Evidence:** Capture separate sender and receiver drawn signatures on mobile/web, store PNG/vector evidence privately with SHA-256 hash, signer account, operator-vs-recipient attribution, device ID, and server timestamp (`qa_asset_handovers`), preventing silent substitution.
- **ASSET-04.4 Android Biometric-Protected Transaction Signing (`FR-19`):** Optional/policy-required cryptographic signing of handover/high-value transactions using an Android Keystore key unlocked by `local_auth`, verified server-side against the user's registered public key (see Part 3.3).

### 1.5 Asset Requests & Multi-Stage Approval Workflow (`FR-06`, Handoff §4)
- **ASSET-05.1 Request Submission:** Authorized staff submit requests with item/spec, quantity, department, justification, and needed-by date. Server enforces initial status **`PENDING_DEPARTMENT_HEAD`**.
- **ASSET-05.2 Configurable Approval Hierarchy:**
  - Stage 1: Department Head reviews → **Approve** (`PENDING_DEPUTY_CHAIRPERSON`), **Reject** (`REJECTED`), or **Return for Correction** (`RETURNED_FOR_CORRECTION`).
  - Stage 2: Deputy Chairperson (or configured approver) reviews → Final **Approve** (`APPROVED`) or **Reject** (`REJECTED`) / **Return**.
- **ASSET-05.3 Server Enforcement & Separation of Duty:** Requesters cannot self-approve high-risk requests; every decision logs actor, role, timestamp, and comments, and notifies requester + next responsible user.

### 1.6 Maintenance & Repairs (`FR-08`)
- **ASSET-06.1 Repair Tickets & Preventive Schedules:** Submit maintenance/repair requests and configure recurring preventive service schedules with due/overdue reminders.
- **ASSET-06.2 Execution & Cost Tracking:** Record issue description, priority, technician/service provider, start/completion dates, work performed, parts replaced, service cost, warranty coverage, and supporting invoices/photos.
- **ASSET-06.3 Availability Sync:** Automatically transition asset status (`maintenance`) and update post-repair condition (`good`, `fair`, `beyond_repair`).

### 1.7 Physical Inspection & Audit Campaigns (`FR-09`)
- **ASSET-07.1 Scheduled Inspection Campaigns:** Define campaigns scoped by year, location, department, category, or event and assign inspectors/teams.
- **ASSET-07.2 Field QR Verification:** Scan assets offline or online and mark outcomes: `inspected`, `not_found`, `relocated`, `damaged`, `duplicated`, or `unregistered` with photos and notes.
- **ASSET-07.3 Discrepancy & Findings Resolution:** Generate variance reports, open corrective findings with owner and due date, and require authorized approval for stock/register adjustments.

### 1.8 Asset Disposal & Retirement (`FR-10`)
- **ASSET-08.1 Controlled Retirement Workflow:** Submit disposal requests (`lost`, `stolen`, `destroyed`, `damaged_beyond_repair`, `sold`, `donated`, `retired`) with evidence photos/documents and proceeds (if sold).
- **ASSET-08.2 Guardrails:** Block disposal if unresolved custody or open maintenance/inspection tasks exist (unless an authorized, audited override is recorded). Requires Deputy Chairperson/configured approval before removing the item from active availability while preserving lifetime history.

### 1.9 Member-Level Issuing & Rapid Event Operations (`FR-15`, `FR-16`, PRD §6.2)
- **ASSET-09.1 Member Sizing & Clothing Inventory:** Store member-specific clothing/uniform sizes (`qa_member_asset_profiles` keyed by external `source_system + external_member_id`, **never mutating SSMS**), track sizes/variants, and show each member's current outstanding items and full issue/return history.
- **ASSET-09.2 Reusable Event Templates:** Define templates for celebrations, holidays, and choir/service events with default item packages, required sizes/quantities, expected return dates, and event rules.
- **ASSET-09.3 Participant Preparation:** Select eligible members using combined SSMS directory filters (department, class, section, group, active status), saved filter presets, and manual inclusions/exclusions.
- **ASSET-09.4 Multi-Station Allocation & Preload:** Partition stock quantities across multiple issue/return stations/phones before going offline, download the complete **Event Package**, and verify readiness/completeness before opening stations.
- **ASSET-09.5 High-Speed Issue & Return Modes:**
  - Continuous scanning mode (scan SSMS member QR/card or lookup by name/ID + scan serialized asset QR if applicable) without reopening forms between members.
  - Large touch targets for **one-tap full issue**, **multi-item issue**, **fast return**, **partial return**, and **damaged/missing/unreturned marking**.
  - Live station progress counters, duplicate-issue prevention, offline queue with sync reconciliation, and end-of-event variance/outstanding reports + overdue return reminders.

### 1.10 Year-Based Operational Lifecycle (`FR-17`)
- **ASSET-10.1 Active Year Workspace:** Syncs active school year from SSMS (or standalone fiscal year config); defaults dashboards, requests, events, and movements to the active year.
- **ASSET-10.2 Year-End Close & Carry-Forward:** Carry forward opening stock balances, active assignments, unreturned member items, pending requests, and open maintenance into the new year without duplicating asset IDs.
- **ASSET-10.3 Read-Only Historical Years:** Completed years become read-only by default; reopening requires authorized permission, reason, and an immutable audit entry.

### 1.11 Cross-Department Bridges (Asset Dept ↔ Finance/Procurement)
- **ASSET-11.1 Pre-Procurement Stock Check:** Asset Dept verifies stock availability on incoming purchase requests (`NOT_IN_STOCK`, `PARTIAL_STOCK`, `IN_STOCK_FULFILLABLE`) before procurement proceeds.
- **ASSET-11.2 Goods Receiving & Asset Minting:** Upon PO delivery, Asset Dept inspects quantities/condition, signs Goods Receipt (`GRN`), and automatically mints fixed-asset records (with QR codes) or posts stock-in movements.

---

## PART 2 — Department 2: Finance & Related Department (`finance_dept` — የበጀት፣ የፋይናንስ፣ የሰነድና የግዢ ክፍል)

### 2A. Budget & Finance Management (`FIN_BUDGET`)

#### 2A.1 Annual Budget & Department Allocations (`FIN-01`)
- **FIN-01.1 Fiscal / School Year Budgets:** Define fiscal years (`ec_year`, start/end dates, `draft`/`active`/`closed`).
- **FIN-01.2 Department & Line-Item Allocations:** Allocate approved annual budget amounts by department and expense category (`approved_amount`, `amended_amount`, `committed_amount`, `spent_amount`, `remaining_available`).
- **FIN-01.3 Budget Amendments & Reallocations (`የበጀት ማሻሻያና ዳግም ምደባ`):** Increase, decrease, or transfer budget between lines (`from_budget_id -> to_budget_id`) with requester, justification, approval routing (`finance_head -> deputy_chairperson`), and full history.
- **FIN-01.4 Real-Time Execution View:** Track approved vs. amended vs. encumbered (open POs) vs. spent vs. remaining balance per department and school-wide.

#### 2A.2 Append-Only Income & Expense Ledger (`FIN-02`)
- **FIN-02.1 Categorized Entries:** Record income and expenses with amount (`DECIMAL(14,2)`), currency (`ETB`), category, department, budget line, payment method (`cash`, `bank_transfer`, `mobile_money`, `check`), bank/petty-cash account, voucher/receipt number, Ethiopian & Gregorian dates, payer/payee, and description.
- **FIN-02.2 Receipt & Document Linkage:** Attach receipt photos/PDFs directly into Document Management (`receipt_document_id`).
- **FIN-02.3 Strict No-Delete / Reversal-Only Rule (Amharic Plan §8):** **Confirmed financial transactions can NEVER be deleted or silently edited.** Corrections require posting a linked **Reversal (`REVERSAL`)** and optional **Adjustment (`ADJUSTMENT`)** journal referencing the original transaction ID, with mandatory reason and approver.

#### 2A.3 Bank Accounts & Petty Cash Management (`FIN-03`)
- **FIN-03.1 Accounts Registry:** Manage organization bank accounts and petty-cash drawers/custodians with opening and live running balances.
- **FIN-03.2 Deposits, Withdrawals & Paired Transfers:** Record cash-to-bank deposits, bank-to-petty-cash withdrawals, and inter-account transfers as balanced entries.

#### 2A.4 Bank Statement Reconciliation (`FIN-04`)
- **FIN-04.1 Statement Import & Entry:** Upload CSV/XLSX bank statements or enter statement periods/lines.
- **FIN-04.2 Transaction Matching & Variance Notes:** Match ledger entries against bank lines (`unmatched`, `matched`, `discrepancy`, `resolved`), flag bank fees/unpresented checks/deposits in transit, and require Finance Head sign-off + Auditor visibility.

#### 2A.5 Monthly Member Contributions (`FIN-05`)
- **FIN-05.1 Contribution Plans & Monthly Tracking:** Configure monthly dues/pledges per Ethiopian month (`ec_month` 1–13) and year (`ec_year`).
- **FIN-05.2 Authoritative Member Roster:** Pulls members via `SSMSConnector` (or standalone `qa_payers` directory), tracks `paid` / `partial` / `unpaid` status, issues receipts, and auto-posts collected cash/bank receipts to the Finance Income Ledger.

---

### 2B. Procurement Management (`PROCUREMENT`)

#### 2B.1 Purchase Requisition & Mandatory Dual Gates (`PROC-01`)
- **PROC-01.1 Purchase Request Creation:** Submit itemized requests (fixed assets, consumable stock, or services) with specifications, quantities, estimated prices, target budget line, justification, and needed-by date.
- **PROC-01.2 Gate 1 — Asset Stock Verification (`የንብረት ክምችት ማረጋገጥ`):** Routes to Asset Dept to verify whether existing warehouse stock satisfies part or all of the request before purchasing.
- **PROC-01.3 Gate 2 — Budget Availability Enforcement (`የበጀት ማረጋገጫ`):** Server verifies `remaining_available >= estimated_total`. **Insufficient budget blocks approval routing** until a budget amendment is approved or request amount is adjusted.

#### 2B.2 Suppliers & Quotation Comparison (`PROC-02`)
- **PROC-02.1 Supplier Registry:** Maintain approved vendors (name, contact, phone, TIN/license, category, bank details, status).
- **PROC-02.2 Multi-Vendor Quotations & Matrix (`የዋጋ ማወዳደር`):** Record 1..N supplier quotes per requisition (line prices, total, delivery lead time, warranty, payment terms, attached quote file) and generate a side-by-side comparison matrix with selection justification.

#### 2B.3 Approvals, Purchase Orders & Three-Way Handoff (`PROC-03`, `PROC-04`)
- **PROC-03.1 Hierarchical Approval:** Configurable approval routing (`Department Head -> Finance Verification -> Deputy Chairperson / Chairperson`), recording every decision and notification.
- **PROC-03.2 Purchase Order (`PO-YYYY-NNNNN`):** Encumbers budget (`committed_amount`), generates printable PO PDF, and tracks status (`ISSUED`, `PARTIALLY_RECEIVED`, `RECEIVED`, `PAID`, `CANCELLED`).
- **PROC-03.3 Goods Receiving & Cross-Module Handoff:**
  - **Asset Dept** performs physical inspection (`GRN`), accepts/rejects items with signature, and auto-mints fixed assets or updates consumable stock.
  - **Finance** records supplier payment against PO + invoice + GRN, converting committed budget into actual `spent_amount`.
  - **Document Vault** automatically archives quotations, POs, invoices, payment vouchers, and signed GRNs.

---

### 2C. Document Management (`DOCUMENTS`)

#### 2C.1 Controlled File Vault & Metadata (`DOC-01`)
- **DOC-01.1 Secure Multi-Format Upload:** Accept PDF, Word (`.docx`/`.doc`), Excel (`.xlsx`/`.xls`), and images (`.jpg`/`.png`/`.webp`) with server-side MIME/magic-byte checks, size limits, SHA-256 checksums, and private storage **outside the public web root** (served only through authorized download routes).
- **DOC-01.2 Metadata & Numbering:** Auto-generated `DOC-YYYY-NNNNN`, external letter reference, title (`en`/`am`), type (`letter`, `decision`, `contract`, `receipt`, `invoice`, `quotation`, `purchase_order`, `handover`, `inspection_report`, `maintenance_record`, `audit_report`, `other`), department, fiscal year, author, document date, tags, and linked record (`asset_id`, `tx_id`, `po_id`, `grn_id`, `finding_id`).

#### 2C.2 Organization, Search, Approvals & Versioning (`DOC-02`, `DOC-03`)
- **DOC-02.1 Folders & Full Search:** Browse by year/department/type/folder; search by title, document number, external ref, date range, author, tags, and linked entity.
- **DOC-02.2 Document Approval Workflow (`የሰነድ ማጽደቅ`):** Submit official letters/contracts/decisions (`DRAFT -> PENDING_APPROVAL -> APPROVED / RETURNED`).
- **DOC-02.3 Role Permissions, Versions & Audit History:** Granular permissions (`view`, `download`, `upload`, `edit_meta`, `approve`, `archive`) + confidentiality tiers; non-destructive versioning (`v1`, `v2`, ...); immutable `qa_document_access_logs` tracking every upload, view, download, edit, and archive request; soft-archive protection & backups.

---

## PART 3 — Cross-Cutting Platform Capabilities (`CORE_GOV`)

### 3.1 Mandatory SSMS / Member-System Connector (`FR-14`, PRD §7)
- **CONN-01 Replaceable Adapter Interface:** Isolates core Asset & Finance logic from SSMS internal tables.
- **CONN-02 Stable External Identity Mapping:** Stores `source_system + external_member_id` (and `external_user_id`), never matching records by name.
- **CONN-03 Initial + Incremental Sync & Tombstones:** Paginated bootstrap + monotonic cursor delta sync (`SyncChangeFeedService` in SSMS) with tombstones (`{op: DELETE}`), retry backoff, and reconciliation reports for unmatched/duplicate records.
- **CONN-04 Safe Deactivation/Transfer Handling:** When an SSMS member is renamed, transferred, or deactivated—or when SSMS is temporarily unreachable—historical transactions, custody, and contribution records remain intact and authorized cached views continue within policy limits.
- **CONN-05 Advanced Directory Search & Filtering:** Search by member ID, card/QR reference, Amharic name, English name; filter by status, department, class, section, group, and saved event filters, both server-side and in local offline SQLite.

### 3.2 Mobile-First & Offline-First Architecture (`FR-18`, PRD §8)
- **OFF-01 Complete Mobile Parity:** Every essential Asset Dept workflow (and Finance/Procurement/Document mobile reviews & receipt captures) runs in the Flutter Android app—no essential operational workflow is web-only.
- **OFF-02 Encrypted Local Store (`Drift + SQLite`):** Local database encrypted at rest; tokens/keys stored in Android Keystore / `flutter_secure_storage`; strict per-user isolation on shared phones.
- **OFF-03 Durable Outbox & Sync States:** Queue offline actions with client UUID + `idempotency_key` + local sequence + timestamp (`saved offline -> waiting to sync -> syncing -> synchronized -> needs review -> rejected with reason`), surviving app closure and device restarts.
- **OFF-04 Scoped Event Packages & Signed Offline Leases:** Pre-download complete event/inspection packages with readiness validation; enforce time-limited server-signed offline permission leases (blocking high-risk actions like disposal or role changes offline).
- **OFF-05 Multi-Station Conflict Controls:** Station stock partitioning + server validation on sync; explicit conflict resolution queue (never silently discarding or overwriting records).

### 3.3 Android Biometric-Protected Transaction Signing (`FR-19`, PRD §9.3–§9.4)
- **BIO-01 Zero Biometric Template Storage:** Never capture, transmit, or store raw fingerprint images or biometric templates.
- **BIO-02 Per-User/Device Keystore Keys:** Enroll an asymmetric key pair inside Android Keystore after SSMS/host authentication and admin device approval; register only the public key on the PHP server.
- **BIO-03 Cryptographic Challenge Signing:** Prompt Android `local_auth` to unlock the Keystore private key and sign canonical transaction payload hashes (`tx_id`, `member_id`, `asset_id`, `qty`, `condition`, `nonce`, `timestamp`), verified server-side upon sync.
- **BIO-04 Revocation & Fallbacks:** Instant device/key revocation on lost device, user deactivation, or biometric re-enrollment; PIN/supervisor/drawn-signature fallback per school policy; transparent audit metadata recording the exact verification method without misrepresenting hardware results as a legally qualified e-signature.

### 3.4 Security, Notifications, Audit & Reporting (`FR-12`, `FR-13`, PRD §10–§11)
- **SEC-01 Backend-Enforced Security:** HTTPS everywhere, PDO prepared statements, server-side RBAC + department/location/year scoping on every endpoint, CSRF for browser sessions, rotating JWT/refresh tokens for mobile, out-of-web-root secrets & uploads, private file download proxy.
- **SEC-02 Immutable Audit Logs & Auditor Workbench:** Append-only `qa_audit_logs` (actor, role, impersonated_by, device/key ref, local & server timestamps, before/after summary, evidence hash, outcome) + `qa_audit_findings` corrective-action tracker.
- **SEC-03 Notifications:** In-app notification center (integrated with SSMS `notifications` in integrated mode) + optional server-side Email/SMS/Telegram dispatch for approvals, low stock, overdue returns, maintenance, and reconciliations.
- **SEC-04 Bilingual PDF & Excel (`.xlsx`) Reports:** Year- and Ethiopian/Gregorian date-filtered reports obeying row/field RBAC:
  - Asset Register, Valuation & Depreciation, Custody/Overdue Returns, Low Stock & Movements, Maintenance & Warranty, Physical Inspection Discrepancies.
  - Event Readiness, Multi-Station Reconciliation, Member Clothing Issue/Return & Unreturned Reports.
  - Monthly/Annual Income & Expense, Budget vs. Actual Execution, Bank/Petty Cash & Reconciliation, Member Contribution Paid/Unpaid, Procurement Pipeline, Document Access/Change Audit, and Audit Findings.

### 3.5 Independent Installation, Web Admin & SSMS Super Admin Integration (`FR-20`)
- **INST-01 First-Run Setup & Hosting Fit:** Repeatable standalone installer for Apache/cPanel shared hosting (school branding, logo, bilingual labels, departments, locations, year settings, connector config, Phinx/SQL migrations, health & backup/restore tools).
- **INST-02 Separated Responsive Web Consoles:** Desktop-friendly consoles for **Asset Dept**, **Finance/Procurement/Documents Dept**, **Leadership/Auditor**, and **System Config/Backups**.
- **INST-03 SSMS Super Admin Cockpit:** In SSMS integrated mode, `super-admin.php` and `school_admin.php` provide one-click impersonation (`switchRole('asset_dept')` / `switchRole('finance_dept')` with `#impersonateBar` + full audit trail) and live cross-department financial, procurement, event, and asset analytics.
