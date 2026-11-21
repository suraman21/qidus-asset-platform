# 06 — Phase 1: UX Discovery, Role/Task Matrix & Information Architecture

## 1. Core UX Redesign Principles
1. **Task-First, Not Module-First:** Users open their workspace to act (*Register Asset*, *Scan Member*, *Issue / Return*, *Approve Request*, *Reconcile Bank*) — never to guess database table boundaries or internal enum names.
2. **Safe by Default:** Zero silent pre-selection of members, assets, or events. Member-bound operations require verified QR scan or immutable external ID selection.
3. **Separate Daily Operations from Setup:** Move categories, locations, event templates, fiscal year locks, and supplier setup into dedicated Settings panels rather than crowding daily inventory/ledger screens.
4. **QR-First & Search-First on Mobile and Web:** Server-side search/filters/pagination (`page`, `per_page`, filters) replace bounded `LIMIT 40` tables and `LIMIT 100` `<select>` dropdowns.

---

## 2. Role / Task Matrix (All 14 Roles)

| Role | Default Home Queue | Primary Daily Tasks (Web + Mobile) | Approval / Admin Scope |
|---|---|---|---|
| `system_admin` | System Health, Pending Devices, Role Simulator | Staff provisioning, Android device key approval, member connector sync, operational year controls | Full system config (in Standalone; defers central identity to SSMS in Integrated mode) |
| `chairperson` / `deputy_chairperson` | Pending Final Approvals + Executive KPIs | Stage-2 Asset Requests, Disposal Approvals, Budget Amendments, Stage-3 Procurement, Document Approvals | Final cross-department executive sign-off |
| `auditor` | Open Findings, Inspection Discrepancies, Unmatched Bank Lines | Create/Verify FR-18 Audit Findings, Inspection Scans, Audit Log Explorer + CSV/PDF Export | Read-only financial/asset access; exclusive FR-18 verify |
| `asset_admin` / `asset_head` | Dept-1 Pending Approvals, Low Stock, Upcoming Events | Stage-1 Asset Requests, Pre-Procurement Stock Checks, Event Preparation & Station Allocation, Categories/Locations | Dept 1 setup, year close/reopen, device approval (`asset_admin`) |
| `asset_officer` | Due Returns, Low Stock, Active Inspections | Register/Edit Assets, Movements, Signed Handovers, GRN Receiving, Maintenance, Inspections, Disposal Requests | Daily Asset Dept field & warehouse execution (Web + Mobile) |
| `event_operator` | Assigned Event Station Readiness | Download Event Package, Continuous Member QR Scan, Multi-Item Rapid Issue & Return, Offline Batch Sync | Station-scoped rapid event operations (Mobile-first) |
| `maintenance_officer` | Open/Overdue Maintenance Tickets | Schedule preventive service, record parts/labor costs, complete repairs & update condition | Maintenance execution (Web + Mobile) |
| `finance_head` | Pending Budget Amendments, Unmatched Statements, PR Gate-2 | Create/Lock Budgets, Reverse Ledger Entries, Finalize Reconciliations, Stage-2 Procurement, Approve/Archive Docs | Dept 2 supervisory & financial control |
| `finance_staff` | Daily Cash/Bank Entries & Unpaid Contributions | Post Income/Expense, Match Bank Statement Lines, Record Monthly Member Contributions, Upload Receipts | Append-only ledger & cash operations |
| `procurement_officer` | Open PRs, Pending Quotations, Open POs | Manage Suppliers, Create PRs, Compare Vendor Quotes, Issue POs, Coordinate GRN | End-to-end procurement cycle |
| `document_controller` | Pending Document Filing & Approvals | Organize Folders, Upload Versioned Files, Maintain Metadata & Confidentiality | Document Vault curation & soft-archiving |
| `requester` | My Requests & My Assigned Custody | Submit Asset Requests / PRs, view own issued items & profile | Self-service requests + Profile Management |

---

## 3. Redesigned Information Architecture (Web & Mobile)

### 3.1 Web Workspace Map (Grouped by User Tasks, Not Raw Modules)
- **Global Topbar (All Authenticated Roles):** Global Quick Search (`AST-`, `QR`, `Member ID/Name`, `PR-`, `DOC-`), Notification Bell (unread badge + drawer), **Language Switcher (`አማርኛ` / `EN`, Amharic default)**, Light/Dark Theme Toggle, Active Operational Year Pill (`2019 ዓ.ም.`), Print/Export Shortcut.
- **Department 1 (`?page=asset&ws=...`):**
  1. `home` (**My Work / የእኔ ሥራ**): Role-aware action queue (pending approvals, overdue returns, low stock, maintenance alerts, upcoming events) + 4 quick-action buttons.
  2. `assets` (**Assets / ንብረቶች**): Paginated searchable register + guided mode-adaptive Registration Wizard (`fixed` vs `consumable` vs `reusable_member`) + focused Asset Detail view (Custody, Movements, Maintenance, Attachments, History).
  3. `inventory` (**Stock & Movements / ክምችት**): Receive, Issue, Transfer, Controlled Adjustment, Stocktake.
  4. `custody` (**Issue & Return / ርክክብ**): Member-bound / Staff-bound custody, active & overdue returns, drawn + Keystore receipt history.
  5. `events` (**Events & Stations / ዝግጅቶች**): Event creation from templates, participant filter & size assignment, station stock partitioning, readiness status, closeout reconciliation.
  6. `requests` (**Requests & Approvals / ጥያቄዎች**): Inbox with localized status pills (`በክፍል ኃላፊ በመታየት ላይ` / `Awaiting Dept Head`), self-approval lock explanation, decision history.
  7. `maintenance` (**Maintenance / ጥገና**): Tickets, preventive schedules, costs, warranty.
  8. `inspections` (**Inspections / ቆጠራ እና ፍተሻ**): Campaigns, scan results, discrepancy resolution.
  9. `disposals` (**Disposals / ማስወገጃ**): Eligibility guardrails, approval queue, retired archive.
  10. `reports` (**Reports / ሪፖርቶች**) & `settings` (**Setup / ማዋቀሪያ**: Categories, Locations, Templates, Operational Years).
- **Department 2 (`?page=finance&ws=...`):**
  1. `home` (**Finance Work Queue**), `ledger` (**Income & Expenses**), `budgets` (**Budgets & Amendments**), `reconcile` (**Bank & Contributions** — searchable member directory, no 50-row dropdown), `procurement` (**Procurement Workflow**: PRs → Stock Check → Budget Gate → Quotes → PO → GRN → Payment), `documents` (**Document Vault** with folder tree & search), `reports` (**Finance Reports**), `settings` (**Accounts, Categories, Suppliers**).
- **Governance & Account (`?page=leadership`, `?page=profile`):**
  - Cross-Department Executive Overview, FR-18 Audit Findings Inbox, Device Keystore Approvals, Staff & Member Directory, Audit Trail Explorer, and **My Profile (`?page=profile`)**.

### 3.2 Flutter Android Navigation Map (Phone-Optimized)
- **Bottom Navigation Bar (5 Focused Destinations):**
  1. **Home (`መነሻ`):** Role task queue, offline lease badge (`Ready Offline` / `Lease Expired` / `Syncing` / `Needs Attention`), active year, quick actions.
  2. **Scan (`ስካን`):** Mode selector: *Asset Lookup*, *Continuous Inspection Scan*, *Event Member Station Scan*.
  3. **Custody (`ርክክብ`):** Member-bound Issue/Return (QR/ID search, **never preselected**), non-member custody tab, drawn + auth-bound Keystore signing.
  4. **Events (`ዝግጅት`):** Event Readiness Gate (package completeness check) → Dedicated Full-Screen **Station Field Mode** (continuous member scan → preconfigured bundle → multi-item confirm / outstanding-only return → haptic/audio feedback → auto scan-ready).
  5. **More / Outbox (`ተጨማሪ`):** Drawer/Menu giving full mobile parity for **Assets (Search/Register/Detail)**, **Stock Movements**, **Requests & Approvals**, **Maintenance**, **Inspections**, **Disposals**, **Reports**, **Sync Outbox & Conflict Resolution**, and **Profile/Settings**.

---

## 4. Priority End-to-End User Journeys

### Journey 1 — Rapid Event Station Mode (Highest Priority, P0)
1. **Pre-Event:** Coordinator creates event from template → selects participants via searchable/filterable Member Directory → assigns clothing sizes → partitions stock across stations (`Station A: 50 M, Station B: 50 M`).
2. **Offline Package Download & Readiness Gate:** Mobile downloads **all** cursor pages (`assets`, `members`, `variants`, `participants`, `allocations`, `manifest_sha256`) + valid offline lease. Package shows **"Ready Offline"** only when `downloaded_count == manifest_count`.
3. **Field Issuing:** Operator taps **Start Station** → camera opens in continuous **Scan Member** mode (no member preselected). Scanning member QR displays verified card (`Name AM/EN · Member Code · Dept/Class/Section · Active Status`) + preconfigured items/sizes. Operator confirms multiple items in 1 tap (or adjusts qty/variant with override reason) → optimistic local save in encrypted DB + durable outbox → visual green pulse + beep/vibration → **auto-returns to Scan Member**.
4. **Field Returning:** Operator switches toggle to **Return Mode** → scans member QR → sees **only outstanding items** for that member → marks All Returned, Partial Return (qty), or Damaged/Missing → updates balance immediately.
5. **Sync & Conflict Handling:** When online, `SyncEngine` batches with `client_tx_uuid` idempotency. `2xx` marks `synced`; `4xx` validation/auth moves to `rejected` with clear reason; stock conflict moves to `needs_review` without silent overwrite.

### Journey 2 — Controlled Handover & Authentication-Bound Signing (P0)
1. Operator scans Asset QR + Member QR (or selects via searchable Member Directory; non-member staff/external custody uses separate explicit toggle).
2. Reviews asset condition, variant, custody status, and expected return date.
3. Captures drawn signature (validated non-empty) and/or unlocks **auth-bound** Android Keystore key (`setUserAuthenticationRequired(true)`) signing canonical payload v2 (`school_id`, `actor_user_id`, `device_uuid`, `external_member_id`, `asset_id`, `variant_id`, `event_id`, `station_id`, `handover_type`, `quantity`, `client_tx_uuid`, `lease_jti`, `device_tx_at`).
4. Network timeouts queue offline cleanly; `400/403/422` business/auth errors surface immediately and are **never** queued as offline success.
