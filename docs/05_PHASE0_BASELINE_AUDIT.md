# 05 — Phase 0: Complete Source-to-Requirement Baseline Audit

**Audit Date:** 2026-11-21  
**Inspected Branch:** `suraman21/qidus-asset-platform` (`main` @ `22d44dc`)  
**Audit Scope:** Department 1 (`asset_dept`), Department 2 (`finance_dept`: Budget/Ledger, Procurement, Document Vault), Core Governance, SSMS Connector, Web UI, and Flutter Android App (`mobile/qidus_asset_app`).

> **Legend:** `Implemented` = complete & matches spec; `Partial` = backend/core works or partial UI exists, significant UX/security/completeness gap remains; `Missing` = not yet implemented in code.

---

## 1. Department 1 — Asset Management (`asset_dept`)

| Req / Area | Status | Code Evidence | Verified Gap / Finding | Severity |
|---|---|---|---|---|
| 3-mode tracking (`fixed`, `consumable`, `reusable_member`) + variants | **Partial** | `AssetService.php`, `002_asset_dept_tables.sql`, `asset_dept.php` | Backend & schema support all 3 modes + `qa_asset_variants`; Web UI registration form is mixed into one dense Inventory tab and doesn't adapt dynamically by tracking mode. | **P1** |
| Ledger-driven movements (no direct balance overwrite) | **Implemented** | `AssetService::recordMovement`, `qa_asset_movements` | Transactional `FOR UPDATE` locking + negative stock prevention enforced server-side. | — |
| Asset Search, Filters & Pagination | **Partial** | `asset_dept.php:311-315`, `api/v1/routes/assets.php` | Web listing hardcoded `LIMIT 40`, dropdowns `LIMIT 100`; no pagination, saved views, or mobile asset register screen. | **P1** |
| QR Generation, Labels & Resolution | **Partial** | `QrLabelService.php`, `qr_scan_screen.dart` | Printable A4 HTML sheets exist; mobile `qr_scan_screen.dart` stops camera after 1 scan (`_cameraActive = false`) and lacks continuous scan / debounce / duplicate suppression. | **P1** |
| 2-Step Asset Requests & Separation of Duty | **Partial** | `AssetWorkflowService.php`, `Rbac::assertNotSelfApproval`, `asset_dept.php` | Backend 2-step + resubmit workflow is solid; Web exposes raw enum strings (`PENDING_DEPUTY_CHAIRPERSON`); mobile Request/Approval screen is missing. | **P1** |
| Custody Handover & Immutable Member Binding | **Partial** | `AssetWorkflowService.php`, `handover_screen.dart` | Mobile `handover_screen.dart` makes member selection optional and allows free-text recipient alone; signed canonical payload omits `external_member_id`, `variant_id`, `actor_user_id`. | **P0** |
| Rapid Event Preparation & Package Completeness | **Partial** | `AssetLifecycleService::getEventPackage`, `sync_engine.dart`, `local_db.dart` | `getEventPackage()` omits variant size labels & readiness manifest checksum; mobile `sync_engine.dart` only pulls first 5 events, doesn't store `participants` locally, and lacks a readiness check gate. | **P1** |
| Rapid Event Field Mode (Issue & Return) | **Partial** | `rapid_event_screen.dart` | **Auto-selects first cached member** (`_memberCacheId ??= members.first['id']`); uses dropdowns + hardcoded `Issue 1` / `Return 1`; no member QR scan, no preconfigured item bundle, no outstanding-only return view. | **P0** |
| Maintenance, Inspections, Disposals, Year Close | **Partial** | `AssetLifecycleService.php`, `OperationalYearService.php`, `asset_dept.php` | Backend workflows and guardrails are complete; Web UI combines maintenance/events/inspections/disposals into crowded tabs; Flutter Android has **zero** screens for Maintenance, Inspections, Disposals, or Reports. | **P1** |

---

## 2. Department 2 — Finance, Procurement & Document Vault (`finance_dept`)

| Req / Area | Status | Code Evidence | Verified Gap / Finding | Severity |
|---|---|---|---|---|
| Fiscal Budgets, 2-Stage Amendments & Execution | **Partial** | `BudgetAndLedgerService.php`, `finance_dept.php:261,388` | Backend budget lock & 2-stage amendments work; Web budgets tab mixes year setup/amendments/execution in one scroll, limits query to `LIMIT 40`, and exposes raw status tokens in English. | **P1** |
| Append-Only Income/Expense Ledger (`REVERSAL`-only) | **Implemented (Backend) / Partial (UX)** | `BudgetAndLedgerService::postTransaction`, `reverseTransaction`, `finance_dept.php:327` | No-delete invariant is strictly enforced in DB/API/Web; Web ledger table hardcoded `LIMIT 30` with no server-side pagination, date-range filter, or Amharic status translation. | **P1** |
| Bank & Petty Cash Accounts + Reconciliation | **Partial** | `BudgetAndLedgerService.php:574-690`, `finance_dept.php:470` | Matching & finalization work; Web uses manual ID dropdowns (`LIMIT 15/20`) instead of guided bank statement matching workspace. | **P1** |
| Monthly Member Contributions | **Partial** | `BudgetAndLedgerService::recordMemberContribution`, `finance_dept.php:274` | Web member selector loads only `LIMIT 50` into a `<select>` dropdown instead of searchable/filterable SSMS directory with immutable ID confirmation. | **P1** |
| Dual-Gated Procurement (Stock Gate + Budget Gate + PO + GRN) | **Partial** | `ProcurementService.php`, `finance_dept.php:550` | Backend dual gates, quotations, PO encumbrance, and GRN → `AssetService` minting work cleanly; Web packs Suppliers, PRs, Quotations, POs, and GRNs into one long tab (`LIMIT 20` each) with raw enum statuses (`STOCK_CHECK_PENDING`). | **P1** |
| Private Document Vault (Versioning, Confidentiality, Access Logs) | **Partial** | `DocumentVaultService.php`, `finance_dept.php:649` | Out-of-web-root vault, SHA-256 versioning, confidentiality tiers, and audited downloads are solid; Web mixes Document Vault AND Finance Reports into the same `documents` tab (`LIMIT 20`–`40`) with no folder tree or search filters. | **P1** |

---

## 3. Cross-Cutting Platform, Mobile, Security & Design System

| Area | Status | Code Evidence | Verified Gap / Finding | Severity |
|---|---|---|---|---|
| Android Keystore Biometric Signing | **Partial** | `MainActivity.kt:71-80`, `keystore_channel.dart:36-46` | Keystore key is NOT `setUserAuthenticationRequired(true)`; `authenticateBiometric()` returns `true` when hardware/biometrics are unavailable, masking unauthenticated signing as biometric. | **P0** |
| Canonical Signing Payload Completeness | **Partial** | `keystore_channel.dart:51-65`, `AssetWorkflowService.php:273` | Payload omits `external_member_id`, `variant_id`, `event_id`, `station_id`, `actor_user_id`, `lease_jti`, and `timestamp`. | **P0** |
| Offline Storage Encryption | **Missing** | `local_db.dart`, `pubspec.yaml` | Uses plain `sqflite` without encryption at rest (`sqflite_sqlcipher` / key wrapping in Android Keystore missing). | **P1** |
| Cursor-Based Complete Sync & Error Classification | **Partial** | `sync_engine.dart`, `handover_screen.dart:118` | `sync_engine.dart` fetches 1 page (`limit=100` assets, `limit=200` members) without looping cursors; `handover_screen.dart` catches all exceptions and queues 4xx validation/auth errors as "offline". | **P1** |
| Flutter Mobile Feature Coverage | **Partial** | `home_shell.dart`, `mobile/qidus_asset_app/lib/features/` | Only 4 destinations (`Scan`, `Handover`, `Events`, `Outbox`). Missing Role Dashboard, Asset Catalog/Register/Detail, Member Search, Requests/Approvals, Maintenance, Inspections, Disposals, Reports. | **P1** |
| Unified Design System & Token Parity | **Partial** | `docs/04_UI_UX_DESIGN_SYSTEM.md`, `web/assets/css/theme.css`, `app_config.dart` | Three divergent palettes: Docs specify `#07080d` + `#f59e0b`; `theme.css` uses `#0f111a` + `#4cc9f0`; Flutter uses `#0f172a` + `#1E3A8A`. | **P1** |
| Global Web Shell Controls & Task Navigation | **Partial** | `web/layouts/shell.php`, `web/assets/css/app.css` | Added Amharic default, language toggle, profile page, and fixed badge wrapping in `22d44dc`; still lacks global search, notification bell, print/export shortcut, and task-first workspace navigation. | **P1** |
| Full Bilingual Localization (Amharic + English) | **Partial** | `web/core/i18n.php`, `asset_dept.php`, `finance_dept.php`, `app_config.dart` | `i18n.php` covers shell, login, setup, profile, and leadership headers; `asset_dept.php`, `finance_dept.php`, status enums, validation errors, and Flutter `handover`/`events`/`scan`/`outbox` screens are still largely English-only. | **P1** |
| SSMS Connector & Integration Boundary | **Implemented (Backend)** | `SsmsDirectMemberConnector.php`, `SsmsHttpMemberConnector.php`, `ConnectorFactory.php` | Read-only adapter contract, incremental sync cache, and `qa_member_asset_profiles` separation are clean; UI needs searchable/filterable directory backed by `qa_member_sync_cache`. | — |
