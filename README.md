# QIDUS ASSET Platform

**Every Birr, Every Asset, Every Document — Accounted For.**

*እያንዳንዱ ብር፣ እያንዳንዱ ንብረትና እያንዳንዱ ሰነድ ተመዝግቦ፣ ተጠብቆና ተቆጣጥሮ መቆየት አለበት።*

QIDUS ASSET is a bilingual (**Amharic & English**) organizational operations platform for Orthodox Sunday Schools covering **two distinct departments** and cross-cutting leadership/audit governance, built from zero on a clean **PHP 8 + MariaDB/MySQL + Separated Web UI + Flutter Android** stack.

It is architected for **dual-mode deployment**:
1. **Standalone Mode:** Runs independently for any Sunday School or organization with its own users, departments, auth, database, web dashboard, and Flutter app.
2. **SSMS Integrated Mode:** Plugs cleanly into the existing **Sunday School Management System (`fkss-sunday-school-platform`)**—replacing legacy `material_dept` and `finance_dept` implementations from scratch while integrating directly with SSMS `users`, `members`, `notifications`, `activity_logs`, Ethiopian calendar helpers, and **Super Admin / School Admin department switching (`api_impersonate.php`) & executive analytics**.

---

## Two-Department Structure

| # | Department | Amharic | Primary Scope |
|---|---|---|---|
| **1** | **Asset Department** (`asset_dept`) | **የንብረት ክፍል** | Fixed & consumable asset registry, QR/barcode resolution, asset requests & 2-step approval (`Department Head -> Deputy Chairperson`), asset handover with drawn signatures & timestamps, stock verification, maintenance/disposal, goods receiving into inventory, physical stock counts. |
| **2** | **Finance & Related Department** (`finance_dept`) | **የበጀት፣ የፋይናንስ፣ የሰነድና የግዢ ክፍል** | **2A. Budget & Finance:** annual budgets, reallocations, immutable income/expense ledger (adjustment-only corrections), bank & petty-cash accounts, bank reconciliation, monthly member contributions.<br>**2B. Procurement:** requisitions, stock & budget availability gates, supplier quotations & comparison, hierarchical approvals, purchase orders, inspection & asset/doc handoff.<br>**2C. Document Management:** controlled archive (PDF/Word/Excel/images), numbering/folders/search, approval routing, version & access audit trail. |

Cross-cutting oversight roles (**System Admin**, **Chairperson**, **Deputy Chairperson**, **Auditor**, plus SSMS **Super Admin / School Admin** in integrated mode) operate across both departments with strict server-enforced RBAC.

---

## Documentation Index

| Document | Contents |
|---|---|
| [`docs/01_SYSTEM_ANALYSIS_AND_SSMS_INTEGRATION.md`](docs/01_SYSTEM_ANALYSIS_AND_SSMS_INTEGRATION.md) | Deep synthesis of both specification PDFs, current SSMS architecture analysis, clean-slate cutover plan (retiring legacy material/finance), standalone vs. SSMS integration architecture, Super Admin department impersonation & cross-system reporting. |
| [`docs/02_COMPLETE_FEATURE_CATALOG.md`](docs/02_COMPLETE_FEATURE_CATALOG.md) | Complete, itemized feature specification across **Asset Dept**, **Finance & Related Dept** (Budget, Procurement, Document Management), **Cross-Cutting Governance/Audit/Notifications/Reporting**, **Web Dashboards**, and **Flutter Mobile App**. |
| [`docs/03_DATABASE_API_AND_MOBILE_BLUEPRINT.md`](docs/03_DATABASE_API_AND_MOBILE_BLUEPRINT.md) | Normalized MariaDB schema (`qa_*` tables), REST `/api/v1/` integration contract, security & immutability rules, separated Web UI architecture, Flutter offline-first mobile architecture, and 7-phase implementation roadmap. |

---

## Planned Repository Structure

```text
qidus-asset-platform/
├── docs/          # System analysis, feature catalog, DB/API/mobile blueprints
├── database/      # Versioned SQL migrations (qa_* clean-slate schema + seed fixtures)
├── backend/       # Core services (auth, RBAC, ledger, workflow, audit, notifications)
├── api/           # REST /api/v1/ router & endpoint handlers (JWT + session bridge)
├── web/           # Separated HTML/CSS/JS dashboards (Asset Dept, Finance Dept, Exec/Audit)
├── mobile/        # New bilingual Flutter Android application (offline-first + QR + sync)
└── tests/         # Static security/contract guards, RBAC workflow tests, API & Flutter tests
```
