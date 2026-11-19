# QIDUS ASSET Platform

**Every Birr, Every Asset, Every Document — Accounted For.**

*እያንዳንዱ ብር፣ እያንዳንዱ ንብረትና እያንዳንዱ ሰነድ ተመዝግቦ፣ ተጠብቆና ተቆጣጥሮ መቆየት አለበት።*

QIDUS ASSET is an open-source, bilingual (**Amharic & English**), mobile-first and offline-capable platform for Orthodox Sunday Schools covering **two distinct departments** and cross-cutting leadership/audit governance, built from zero on a plain **PHP 8.2+ + MySQL/MariaDB (Apache/cPanel compatible) + Separated Web UI + Flutter Android** stack.

It consolidates all three authoritative product specifications:
1. **`Asset_Management_System_PRD_v1.0.pdf`** (v1.0 baseline — FR-01..FR-20: mobile-first offline-first Asset Management, fixed/stock/reusable member-issued clothing, rapid multi-station event issue & return, QR tags, Android Keystore biometric-protected transaction signing, year workspaces, and replaceable SSMS connector).
2. **`QIDUS_ASSET_Budget_Document_Procurement_Plan.pdf`** (Amharic Plan — Budget & Finance, Bank Reconciliation, Member Contributions, Procurement with stock/budget gates, Document Vault, immutable no-delete financial ledger, PDF/Excel reports).
3. **`QIDUS_ASSET_Backend_Team_Handoff.pdf`** (Integration Contract — 6 governance roles, 2-step approval workflow, drawn-signature handovers, notifications, audit logs, and REST API contract).

---

## Two-Department Architecture & Dual Deployment

| # | Department | Amharic | Primary Scope |
|---|---|---|---|
| **1** | **Asset Department** (`asset_dept`) | **የንብረት ክፍል** | Fixed assets, consumable stock, & reusable member-issued items (clothing/uniforms by size); QR generation & offline scan resolution; rapid multi-station event issue/return; 2-step asset requests; drawn + biometric Keystore signature handovers; maintenance & repairs; physical inspection campaigns; controlled disposal; year-based workspaces; stock-check & goods-receiving procurement bridges. |
| **2** | **Finance & Related Department** (`finance_dept`) | **የበጀት፣ የፋይናንስ፣ የሰነድና የግዢ ክፍል** | **2A. Budget & Finance:** fiscal years, annual budgets, amendments/reallocations, append-only income/expense ledger (adjustment/reversal-only corrections), bank & petty cash, bank statement reconciliation, monthly member contributions.<br>**2B. Procurement:** purchase requisitions, Asset stock gate, Finance budget gate, supplier quotations & comparison, hierarchical approvals, Purchase Orders, Goods Receipt handoff.<br>**2C. Document Management:** private file vault (PDF/Word/Excel/images), metadata/numbering/folders, search, approval routing, version & access logs, soft-archive protection. |

### Deployment Modes
1. **Independent / Standalone Installation:** Each Sunday School installs its own instance on standard Apache/cPanel shared hosting (not multi-tenant SaaS) with its own database, branding, files, and backups, plus a replaceable Member Connector.
2. **SSMS Integrated Mode (`fkss-sunday-school-platform`):** Retires legacy `material_dept` & `finance_dept` from scratch; connects via `SSMSConnector` for authoritative member identity, JWT/session SSO, incremental `sync_changes` tombstones, `notifications`, `activity_logs`, Ethiopian calendar, and **SSMS Super Admin / School Admin department switching (`api_impersonate.php`) + cross-department analytics**.

---

## Documentation Index

| Document | Contents |
|---|---|
| [`docs/01_SYSTEM_ANALYSIS_AND_SSMS_INTEGRATION.md`](docs/01_SYSTEM_ANALYSIS_AND_SSMS_INTEGRATION.md) | Unified analysis of all 3 specs (`PRD v1.0`, `Amharic Plan`, `Handoff PDF`), exact SSMS integration & `SyncChangeFeedService`/JWT findings, legacy cutover strategy, 2-department boundaries, and hosting constraints. |
| [`docs/02_COMPLETE_FEATURE_CATALOG.md`](docs/02_COMPLETE_FEATURE_CATALOG.md) | Complete itemized catalog covering every feature across **Asset Dept (`FR-01`–`FR-20`)**, **Finance & Related Dept (`Budget`, `Procurement`, `Documents`)**, **Cross-Cutting Security/Biometrics/Offline Sync/Audit/Reports**, **Web Consoles**, and **Flutter Android App**. |
| [`docs/03_DATABASE_API_AND_MOBILE_BLUEPRINT.md`](docs/03_DATABASE_API_AND_MOBILE_BLUEPRINT.md) | Clean-slate `qa_*` MariaDB schema (fixed/stock/reusable assets, events/stations, member sizing, biometric device keys, offline leases, budgets, reconciliations, procurement, documents), REST `/api/v1/qa/*` contract, Drift/SQLite mobile blueprint, and phased roadmap. |

---

## Repository Structure

```text
qidus-asset-platform/
├── docs/          # System analysis, feature catalog, DB/API/mobile blueprints
├── database/      # Versioned Phinx/SQL migrations (qa_* clean-slate schema + fixtures)
├── backend/       # Plain PHP 8.2+ services, connectors (SSMS/standalone), PDF/XLSX/QR
├── api/           # REST /api/v1/qa/* endpoints (JWT, CSRF, idempotency, biometric verify)
├── web/           # Separated HTML/CSS/JS administration & reporting consoles
├── mobile/        # Mobile-first, offline-first Flutter Android app (Drift + Keystore)
└── tests/         # RBAC, offline conflict, biometric signature, ledger & integration tests
```
