# 04 — UI / UX Design System (Light & Dark Mode)

QIDUS ASSET adopts the modern dual-theme visual language demonstrated in the FKSS/SSMS Admin dashboards (dark obsidian glass + crisp light mode), applied consistently across **both the Web Consoles and the Flutter Android App**, while allowing per-school brand accent overrides (e.g. FKSS gold/maroon, WBSS green/gold, or any independent school's palette).

---

## 1. Dual Theme Architecture (Light & Dark Mode)

- **Default & Instant Switching:** Users can toggle seamlessly between **Dark Mode** (`data-theme="dark"`) and **Light Mode** (`data-theme="light"`) from the topbar sun/moon control (web) or profile/header toggle (mobile).
- **Persistence:** Selected theme is stored in `localStorage` (`qa_theme`) on web and encrypted preferences (`ThemeMode.dark` / `ThemeMode.light` / `ThemeMode.system`) in Flutter, with zero flash-of-wrong-theme on page load (`<html data-theme="...">` applied before first paint).
- **School Brand Independence:** Neutral surfaces (`--bg`, `--sb`, `--card`, `--cb`, `--text`, `--bright`, `--dim`) control light/dark contrast, while `--brand-primary` and `--brand-accent` inject the school's brand color (defaulting to warm gold `#F59E0B` / `#F0C000` accent on active sidebar pills, primary icons, and badges, matching the reference screenshots).

---

## 2. Exact Color & Surface Token Specification

### 2.1 Dark Mode (`[data-theme="dark"]` — Obsidian Slate)
| Token | Value | Usage |
|---|---|---|
| `--bg` | `#07080d` *(with subtle ambient radial glow)* | Page canvas background |
| `--sb` | `#0a0c14` | Fixed left sidebar & modal surface |
| `--card` | `rgba(255, 255, 255, 0.025)` | KPI stat cards, action bars, chart & table containers |
| `--card-hover` | `rgba(255, 255, 255, 0.045)` | Hover elevation |
| `--cb` | `rgba(255, 255, 255, 0.06)` | `1px` hairline card & table dividers |
| `--bright` | `#f0f2f5` | KPI numerals, page headings, card titles |
| `--text` | `#c8cdd5` | Body text, table cells, nav labels |
| `--dim` | `#5e6778` | Subtitles, section headers (`MAIN`, `ADMINISTRATION`), Ethiopian date stamp |
| `--active-nav-bg` | `rgba(245, 158, 11, 0.14)` *(school accent tint)* | Active sidebar pill background |
| `--active-nav-border` | `rgba(245, 158, 11, 0.28)` | Subtle active pill border |

### 2.2 Light Mode (`[data-theme="light"]` — Clean Crisp Daylight)
| Token | Value | Usage |
|---|---|---|
| `--bg` | `#f3f4f6` (`#f8fafc`) | Soft cool gray canvas |
| `--sb` | `#ffffff` | Crisp white sidebar & card surfaces |
| `--card` | `#ffffff` | Elevated cards with subtle border + soft shadow (`0 1px 3px rgba(15,23,42,0.05)`) |
| `--card-hover` | `#f9fafb` | Hover state |
| `--cb` | `rgba(15, 23, 42, 0.08)` | Crisp `1px` card & divider lines |
| `--bright` | `#111827` | High-contrast numerals & headings |
| `--text` | `#374151` | Primary readable text |
| `--dim` | `#6b7280` | Secondary labels & metadata |
| `--active-nav-bg` | `rgba(245, 158, 11, 0.15)` | Warm tinted active sidebar pill |
| `--active-nav-border` | `rgba(245, 158, 11, 0.35)` | Active pill border |

### 2.3 Semantic & Status Palette (Shared Across Both Modes)
| Semantic Role | Accent Color | Soft Pill / Icon Tile Background (Dark / Light) |
|---|---|---|
| **Success / In Stock / Approved / Present** | `#10b981` | `rgba(16,185,129,0.14)` / `#ecfdf5` |
| **Warning / Pending Approval / Low Stock** | `#f59e0b` | `rgba(245,158,11,0.14)` / `#fffbeb` |
| **Danger / Rejected / Out of Stock / Discrepancy** | `#ef4444` | `rgba(239,68,68,0.14)` / `#fef2f2` |
| **Info / Transfers / QR / Categories** | `#06b6d4` | `rgba(6,182,212,0.14)` / `#ecfeff` |
| **Purple / Procurement / Budgets / Custody** | `#8b5cf6` | `rgba(139,92,246,0.14)` / `#f5f3ff` |
| **Pink / Events / Member Contributions** | `#ec4899` | `rgba(236,72,153,0.14)` / `#fdf2f8` |

---

## 3. Typography & Bilingual Localization (Amharic + English)

- **Latin / Numerals:** `DM Sans` (fallback `Inter`, `system-ui`, `sans-serif`) with `font-variant-numeric: tabular-nums` for currency (`ETB`), stock quantities, and KPI numbers.
- **Amharic / Ge'ez Script (`.amharic`, `lang="am"`):** `Noto Sans Ethiopic` / `Noto Serif Ethiopic` with slightly taller line-height (`1.45`) so Ethiopic glyphs render crisply in both dark and light modes.
- **Dual Calendar Subtitle:** Every dashboard header and date badge displays the active Ethiopian date with a live status dot (e.g., `● Hidar 11, 2019`) alongside Gregorian tooltip/export formatting, plus the active operational year pill (`2019 ዓ.ም.`) in the sidebar footer.

---

## 4. Web Console Layout Anatomy (Matching Reference Screenshots)

1. **Fixed Left Sidebar (`250px–260px`, collapses to mobile drawer + bottom nav on `<768px`):**
   - **Top Brand Block:** Rounded-square school logo icon (`40px`), School/Dept title (`QIDUS Asset` / `QIDUS Finance`), and Amharic subtitle (`የንብረት ክፍል` / `የበጀትና ፋይናንስ ክፍል`).
   - **Grouped Navigation Sections:**
     - *Asset Dept:* `MAIN` (`Overview`, `Asset Register`, `QR Labels & Scan`, `Requests & Approvals`, `Handovers & Custody`) • `OPERATIONS` (`Events & Clothing`, `Maintenance`, `Inspections`, `Disposals`) • `SYSTEM` (`Year Workspace`, `Reports & Export`, `My Profile`).
     - *Finance & Related Dept:* `MAIN` (`Overview`, `Budgets & Amendments`, `Income & Expenses`, `Bank & Petty Cash`, `Reconciliation`, `Member Contributions`) • `PROCUREMENT & DOCS` (`Purchase Requests`, `Quotations & POs`, `Document Vault`, `Approval Queue`) • `GOVERNANCE` (`Audit & Findings`, `Reports & Export`, `My Profile`).
   - **Bottom User & Year Footer:** User avatar pill (`SA`, role subtitle, current Ethiopian date), active school-year indicator (`2019 ዓ.ም.`), and `Logout` button.
   - **SSMS Impersonation Banner:** When Super Admin / School Admin opens either department via `switchRole()`, the in-flow amber `#impersonateBar` ("Back to School Admin") sits cleanly at the very top of `<body>` without overlapping the sidebar or header.
2. **Top Header Bar:**
   - Personalized greeting (`Good Morning, ...`) + Ethiopian date live indicator (`● Hidar 11, 2019`).
   - Global quick-search pill (`Search assets, QR, PR, documents...`).
   - Action icon buttons: Notification bell (with unread count badge), **Light/Dark Mode toggle** (sun/moon icon), Print/Export shortcut, and Language toggle (`EN / አማ`).
3. **Dashboard Overview Canvas:**
   - **6-Card KPI Row:** Compact rounded cards (`16px` radius) featuring a `36px` semantic tinted icon tile, large bold tabular number, label, and optional micro-badge (`+3 this week`, `100%`, `Needs Review`).
   - **Quick Actions Strip:** Full-width card containing equal-width touch/click shortcut tiles (`Register Asset`, `Scan QR`, `New Request`, `Event Station`, `Reports` / `Record Expense`, `New PR`, `Upload Document`, `Reconcile`).
   - **2-Column Analytics Row:** Donut breakdown chart (e.g., Asset Condition/Status or Budget Allocation vs. Spent) + Horizontal gradient progress bars (e.g., Stock by Category or Department Budget Execution).
   - **Bottom Recent Activity Table:** Clean sortable/paginated table with subtle row dividers, status pill badges, and one-click detail drawers.

---

## 5. Flutter Mobile App UI/UX Parity (Light & Dark Material 3)

The Flutter Android app (`mobile/qidus_asset_app/`) implements the exact same `ThemeData` token set (`AppTheme.dark` & `AppTheme.light`):
- **Shared Color & Surface Mapping:**
  - Dark `scaffoldBackgroundColor: Color(0xFF07080D)`, `cardColor: Color(0xFF10131C)`, border `Color(0x14FFFFFF)`.
  - Light `scaffoldBackgroundColor: Color(0xFFF3F4F6)`, `cardColor: Colors.white`, border `Color(0x140F172A)`.
  - Brand accent and semantic colors shared identically with the web tokens so switching between mobile and web feels like one unified product.
- **Touch-First Event & Field Ergonomics (`FR-16`, `FR-18`):**
  - Minimum `48dp` touch targets (expanded `56dp+` action buttons in Rapid Event Issue/Return mode).
  - Persistent compact offline/sync status pill in the app bar (`Synced`, `Offline • 4 queued`, `Syncing...`, `1 needs review`) styled with the semantic palette.
  - High-contrast QR viewfinder overlay + crisp signature pad modal that adapts cleanly in both Light and Dark modes (always rendering black-ink signature strokes on a crisp light canvas box for signature clarity).
