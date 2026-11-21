# 07 — Phase 2: Unified Design System, Localization & Safe Migration Plan

## 1. Single-Source-of-Truth Design Tokens (Web CSS + Flutter Material 3)

Reconciles the divergence found in Phase 0 across `docs/04_UI_UX_DESIGN_SYSTEM.md`, `web/assets/css/theme.css`, and `mobile/qidus_asset_app/lib/core/config/app_config.dart` into **one canonical token table**:

| Token | Dark Mode (`#07080D` Obsidian) | Light Mode (`#F3F4F6` Daylight) | Usage |
|---|---|---|---|
| `bg.canvas` | `#07080D` | `#F3F4F6` | Page / Scaffold background |
| `bg.surface` | `#10131C` | `#FFFFFF` | Sidebar, AppBar, Modal, BottomNav |
| `bg.card` | `#161B28` | `#FFFFFF` | Cards, KPI tiles, Table containers |
| `bg.input` | `#1D2333` | `#F8FAFC` | Form inputs, search bars, pills |
| `border.subtle` | `rgba(255, 255, 255, 0.09)` | `#E2E8F0` | 1px card & row hairlines |
| `text.primary` | `#F8FAFC` | `#0F172A` | Headings, KPI numerals, primary copy |
| `text.secondary` | `#94A3B8` | `#475569` | Labels, subtitles, secondary metadata |
| `brand.primary` | `#1E3A8A` (Light) / `#3B82F6` (Dark) | `#1E3A8A` | Primary actions, active focus rings |
| `brand.accent` | `#F59E0B` | `#D97706` | Active nav accent, school brand highlight |
| `semantic.success` | `#10B981` | `#059669` | Synced, Approved, In Stock, Active |
| `semantic.warning` | `#F59E0B` | `#D97706` | Pending Approval, Low Stock, Offline Queued |
| `semantic.danger` | `#EF4444` | `#DC2626` | Rejected, Conflict, Overdue, Out of Stock |
| `semantic.info` | `#06B6D4` | `#0284C7` | Syncing, QR/Info, Transfers |

- **Sidebar Width:** `260px` fixed on desktop (`>= 960px`); collapses to off-canvas drawer + mobile bottom action bar below `960px`.
- **Typography:** `'DM Sans', 'Noto Sans Ethiopic', sans-serif` (bundled locally in `web/assets/fonts/` and Flutter `assets/fonts/` so Ethiopic renders cleanly offline and in Dompdf PDF exports).

---

## 2. Human-Readable Bilingual Status Semantics (No Raw Enum Exposure)

| Internal DB Enum | Amharic Label (`am`) | English Label (`en`) | Semantic Tone |
|---|---|---|---|
| `PENDING_DEPARTMENT_HEAD` | በክፍል ኃላፊ በመታየት ላይ | Awaiting Dept Head | `warning` |
| `PENDING_DEPUTY_CHAIRPERSON` | በምክትል ሰብሳቢ በመታየት ላይ | Awaiting Deputy Approval | `warning` |
| `RETURNED_FOR_CORRECTION` | ለማስተካከል ተመልሷል | Returned for Correction | `warning` |
| `APPROVED` | ጸድቋል | Approved | `success` |
| `REJECTED` | ውድቅ ተደርጓል | Rejected | `danger` |
| `STOCK_CHECK_PENDING` | የንብረት ክምችት ማረጋገጫ ላይ | Stock Check Pending | `warning` |
| `PENDING_FINANCE_REVIEW` | በፋይናንስ ግምገማ ላይ | Awaiting Finance Review | `warning` |
| `in_stock` / `low_stock` / `out_of_stock` | ክምችት አለ / ዝቅተኛ ክምችት / አልቋል | In Stock / Low Stock / Out of Stock | `success`/`warning`/`danger` |
| `needs_review` (Sync) | ግጭት — ክለሳ ይፈልጋል | Conflict — Needs Review | `danger` |

---

## 3. Safe Database, API & Mobile Remediation Plan (Zero Data Loss)

### 3.1 Additive Backend & API Enhancements (Backward-Compatible)
1. **Cursor Pagination & Search on Core Lists:**
   - `GET /api/v1/assets`: add `after_id` cursor + `page`/`per_page` + `q` (code, `name_en`, `name_am`, `serial_number`, `qr_token`), returning `pagination: { total, page, per_page, next_cursor, has_more }`.
   - `GET /api/v1/members/sync` & `/members/search`: support `department`, `class`, `section`, `status`, `clothing_size`, `has_more`, `next_cursor` so mobile loops until `has_more === false`.
2. **Event Package Completeness & Outstanding Balances (`GET /api/v1/events/{id}/package`):**
   - Include `variants` (`size_label`, `variant_code`), `participant_items` (preconfigured items + sizes), `outstanding_by_member` (per-member issued minus returned), and `manifest: { version, station_count, allocation_count, participant_count, sha256 }` so mobile validates completeness before unlocking **Ready Offline**.
3. **Authentication-Bound Keystore & Canonical Payload v2:**
   - `MainActivity.kt`: add `.setUserAuthenticationRequired(true)` and `.setUserAuthenticationParameters(0, KeyProperties.AUTH_BIOMETRIC_STRONG or KeyProperties.AUTH_DEVICE_CREDENTIAL)` (API 30+, with `setUserAuthenticationValidityDurationSeconds(-1)` on API 24–29) and `.setInvalidatedByBiometricEnrollment(true)`.
   - `keystore_channel.dart`: remove silent `return true` when biometrics are unavailable; enforce explicit school/server policy (`biometric_only` vs `device_credential_fallback`), never marking unauthenticated signing as `biometric_keystore`.
   - Canonical payload v2 adds `school_instance`, `actor_user_id`, `external_member_id`, `variant_id`, `event_id`, `station_id`, `lease_jti`, `device_tx_at`; `BiometricVerifier` validates both signature and payload field equality against the submitted transaction.
4. **Encrypted Local SQLite (`sqflite_sqlcipher`):**
   - Replace `sqflite` with `sqflite_sqlcipher`, generating a 256-bit DB passphrase stored inside `flutter_secure_storage` (Android Keystore-backed `EncryptedSharedPreferences`), plus safe migration of existing schema + `local_event_participants` and `local_member_outstanding` tables.
5. **Safe Error Classification in Mobile Outbox:**
   - Only `SocketException` / `TimeoutException` / HTTP `502`/`503`/`504` enqueue into `local_outbox`.
   - HTTP `400`/`401`/`403`/`409`/`422` surface immediate field/banner errors and are **never** disguised as offline queued successes.

### 3.2 Rollback & Release Safety
- All schema/API additions are strictly **additive** (no column drops, no destructive table renames, old v1 clients continue working during rollout).
- `web/` refactoring extracts reusable PHP view partials (`web/components/`) behind existing `index.php?page=...` routes so bookmarks and cPanel `.htaccess` rules remain unbroken.
