from __future__ import annotations

import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def read_text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def test_required_phase1_files_exist() -> None:
    expected = [
        "composer.json",
        ".htaccess",
        "backend/config/env.example.php",
        "backend/config/bootstrap.php",
        "backend/src/Database/Connection.php",
        "backend/src/Support/Response.php",
        "backend/src/Support/EthiopianCalendar.php",
        "backend/src/Support/IdempotencyService.php",
        "backend/src/Support/QrLabelService.php",
        "backend/src/Security/PasswordService.php",
        "backend/src/Security/JwtService.php",
        "backend/src/Security/Rbac.php",
        "backend/src/Security/BiometricVerifier.php",
        "backend/src/Security/AuditLogger.php",
        "backend/src/Connector/MemberConnectorInterface.php",
        "backend/src/Connector/StandaloneMemberConnector.php",
        "backend/src/Connector/SsmsHttpMemberConnector.php",
        "backend/src/Connector/SsmsDirectMemberConnector.php",
        "backend/src/Connector/ConnectorFactory.php",
        "backend/src/Asset/AssetService.php",
        "backend/src/Asset/AssetWorkflowService.php",
        "backend/src/Asset/AssetLifecycleService.php",
        "backend/src/Asset/OperationalYearService.php",
        "backend/src/Asset/AssetAttachmentService.php",
        "backend/src/Asset/AssetReportService.php",
        "backend/src/Finance/BudgetAndLedgerService.php",
        "backend/src/Finance/ProcurementService.php",
        "backend/src/Finance/DocumentVaultService.php",
        "backend/src/Governance/ExecutiveAndAuditService.php",
        "database/migrations/001_core_identity_departments_years.sql",
        "database/migrations/002_asset_dept_tables.sql",
        "database/migrations/003_finance_procurement_docs_tables.sql",
        "database/seeds/001_default_seed.sql",
        "database/migrate.php",
        "api/v1/index.php",
        "api/v1/core/auth_guard.php",
        "api/v1/routes/auth.php",
        "api/v1/routes/core.php",
        "api/v1/routes/assets.php",
        "api/v1/routes/finance.php",
        "web/index.php",
        "web/core/web_auth.php",
        "web/core/i18n.php",
        "web/layouts/shell.php",
        "web/assets/css/theme.css",
        "web/assets/css/app.css",
        "web/assets/js/theme.js",
        "web/pages/login.php",
        "web/pages/setup.php",
        "web/pages/profile.php",
        "web/pages/asset_dept.php",
        "web/pages/finance_dept.php",
        "web/pages/leadership_audit.php",
        ".github/workflows/ci.yml",
        "mobile/qidus_asset_app/pubspec.yaml",
        "mobile/qidus_asset_app/android/app/src/main/AndroidManifest.xml",
        "mobile/qidus_asset_app/android/app/src/main/kotlin/org/qidus/asset/qidus_asset_app/MainActivity.kt",
        "mobile/qidus_asset_app/lib/main.dart",
        "mobile/qidus_asset_app/lib/core/config/app_config.dart",
        "mobile/qidus_asset_app/lib/core/security/keystore_channel.dart",
        "mobile/qidus_asset_app/lib/core/storage/local_db.dart",
        "mobile/qidus_asset_app/lib/core/api/api_client.dart",
        "mobile/qidus_asset_app/lib/core/sync/sync_engine.dart",
        "mobile/qidus_asset_app/lib/features/auth/login_screen.dart",
        "mobile/qidus_asset_app/lib/features/home/home_shell.dart",
        "mobile/qidus_asset_app/lib/features/scan/qr_scan_screen.dart",
        "mobile/qidus_asset_app/lib/features/handover/handover_screen.dart",
        "mobile/qidus_asset_app/lib/features/events/rapid_event_screen.dart",
        "mobile/qidus_asset_app/lib/features/sync/outbox_screen.dart",
        "mobile/qidus_asset_app/test/outbox_and_keystore_test.dart",
    ]
    missing = [p for p in expected if not (ROOT / p).is_file()]
    assert not missing, f"Missing Phase 1 files: {missing}"


def test_sql_migrations_define_all_core_and_dept_tables() -> None:
    sql1 = read_text("database/migrations/001_core_identity_departments_years.sql")
    sql2 = read_text("database/migrations/002_asset_dept_tables.sql")
    sql3 = read_text("database/migrations/003_finance_procurement_docs_tables.sql")
    all_sql = "\n".join([sql1, sql2, sql3])

    required_tables = [
        "qa_schema_migrations",
        "qa_install_config",
        "qa_operational_years",
        "qa_departments",
        "qa_users",
        "qa_auth_refresh_tokens",
        "qa_device_keys",
        "qa_offline_leases",
        "qa_member_sync_cache",
        "qa_member_asset_profiles",
        "qa_notifications",
        "qa_audit_logs",
        "qa_audit_findings",
        "qa_api_idempotency",
        "qa_asset_categories",
        "qa_asset_locations",
        "qa_assets",
        "qa_asset_variants",
        "qa_asset_qr_history",
        "qa_asset_movements",
        "qa_asset_requests",
        "qa_asset_handovers",
        "qa_event_templates",
        "qa_events",
        "qa_event_stations",
        "qa_event_allocations",
        "qa_event_participants",
        "qa_event_transactions",
        "qa_asset_maintenance",
        "qa_asset_inspections",
        "qa_asset_inspection_items",
        "qa_asset_disposals",
        "qa_fin_categories",
        "qa_fin_accounts",
        "qa_fin_budgets",
        "qa_fin_budget_amendments",
        "qa_fin_transactions",
        "qa_fin_bank_reconciliations",
        "qa_fin_bank_statement_lines",
        "qa_fin_contribution_plans",
        "qa_fin_member_contributions",
        "qa_suppliers",
        "qa_procurement_requests",
        "qa_procurement_items",
        "qa_procurement_quotations",
        "qa_procurement_quotation_items",
        "qa_purchase_orders",
        "qa_goods_receipts",
        "qa_goods_receipt_items",
        "qa_doc_folders",
        "qa_documents",
        "qa_document_versions",
        "qa_document_access_logs",
    ]
    for tbl in required_tables:
        assert f"CREATE TABLE IF NOT EXISTS `{tbl}`" in all_sql, f"Missing table DDL: {tbl}"

    # Ensure financial ledger enforces reversal tracking rather than deletes
    assert "reverses_tx_id" in sql3 and "'REVERSAL'" in sql3
    # Ensure no raw biometric template column exists anywhere in SQL
    assert "fingerprint_template" not in all_sql.lower()
    assert "biometric_template" not in all_sql.lower()


def test_php_sql_table_references_match_migrations() -> None:
    sql_all = "\n".join([
        read_text("database/migrations/001_core_identity_departments_years.sql"),
        read_text("database/migrations/002_asset_dept_tables.sql"),
        read_text("database/migrations/003_finance_procurement_docs_tables.sql"),
    ])
    defined_tables = set(re.findall(r"CREATE TABLE IF NOT EXISTS `([a-z0-9_]+)`", sql_all))

    for path in ROOT.rglob("*.php"):
        text = path.read_text(encoding="utf-8")
        for tbl in set(re.findall(r"\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+`?(qa_[a-z0-9_]+)`?", text, re.I)):
            assert tbl in defined_tables, f"{path.relative_to(ROOT)} references unknown table {tbl}"


def test_no_hardcoded_secrets_or_insecure_auth_patterns() -> None:
    php_files = list(ROOT.rglob("*.php"))
    assert php_files, "Expected PHP files"

    for path in php_files:
        text = path.read_text(encoding="utf-8")
        rel = str(path.relative_to(ROOT))
        assert "ghp_" not in text, f"PAT leaked in {rel}"
        if rel.startswith("api/"):
            assert "$_GET['token']" not in text and '$_GET["token"]' not in text, f"Query token bypass in {rel}"
        for bad in ("eval(", "shell_exec(", "passthru(", "system("):
            assert bad not in text, f"Disallowed call {bad} in {rel}"


def test_htaccess_blocks_internal_directories_and_env_files() -> None:
    htaccess = read_text(".htaccess")
    assert "^(backend|database|storage|tests|docs|vendor|mobile|\\.git)/" in htaccess
    assert "_env\\.php" in htaccess
    assert "HTTP_AUTHORIZATION" in htaccess
    assert "Permissions-Policy" in htaccess


def test_mobile_api_route_parity() -> None:
    api_client = read_text("mobile/qidus_asset_app/lib/core/api/api_client.dart")
    assert "'/devices/register'" in api_client
    assert "'/devices/enroll'" not in api_client
    assert "res['lease_token']" in api_client
    assert "offline_lease_token" not in api_client

    sync_engine = read_text("mobile/qidus_asset_app/lib/core/sync/sync_engine.dart")
    assert "memResp['items']" in sync_engine
    assert "memResp['next_cursor']" in sync_engine

    qr_scan = read_text("mobile/qidus_asset_app/lib/features/scan/qr_scan_screen.dart")
    assert "'/assets/resolve/" in qr_scan
    assert "/assets/by-qr/" not in qr_scan


def test_migrate_script_is_cli_only() -> None:
    migrate = read_text("database/migrate.php")
    assert "PHP_SAPI !== 'cli'" in migrate
    assert "http_response_code(404)" in migrate


def test_web_ui_light_dark_tokens_and_js_syntax() -> None:
    theme_css = read_text("web/assets/css/theme.css")
    assert '[data-theme="dark"]' in theme_css
    assert '[data-theme="light"]' in theme_css
    assert "--school-primary" in theme_css
    assert "--school-accent" in theme_css

    res = subprocess.run(["node", "--check", str(ROOT / "web/assets/js/theme.js")], capture_output=True, text=True)
    assert res.returncode == 0, f"node --check failed: {res.stderr}"


def test_rbac_two_departments_and_offline_bounds() -> None:
    rbac = read_text("backend/src/Security/Rbac.php")
    m = re.search(r"OFFLINE_ELIGIBLE_PERMISSIONS\s*=\s*\[(.*?)\];", rbac, re.S)
    assert m, "OFFLINE_ELIGIBLE_PERMISSIONS missing"
    offline_block = m.group(1)
    for forbidden_offline in ("system.admin", "system.device_approve", "asset.disposal_approve", "finance.ledger_adjust"):
        assert forbidden_offline not in offline_block


def test_no_duplicate_named_pdo_parameters_in_queries() -> None:
    for path in ROOT.rglob("*.php"):
        txt = path.read_text(encoding="utf-8")
        assert "in_array((" not in txt, f"Unsafe ternary/null-coalescing in_array(( in {path.relative_to(ROOT)}"
        for m in re.finditer(r"(\$[a-zA-Z0-9_]+)->lastInsertId\s*\(", txt):
            assert m.group(1) in ("$pdo", "$tx", "$db"), f"lastInsertId called on non-PDO var {m.group(1)} in {path.relative_to(ROOT)}"
        for m in re.finditer(r"->prepare\s*\(\s*(['\"])(.*?)\1\s*\)", txt, re.S):
            sql = m.group(2)
            params = re.findall(r":([a-zA-Z0-9_]+)", sql)
            dups = [x for x in set(params) if params.count(x) > 1]
            assert not dups, f"Duplicate PDO named params {dups} in {path.relative_to(ROOT)}"


def test_mobile_android_keystore_and_offline_contracts() -> None:
    manifest = read_text("mobile/qidus_asset_app/android/app/src/main/AndroidManifest.xml")
    assert 'usesCleartextTraffic="false"' in manifest
    assert "USE_BIOMETRIC" in manifest

    kt = read_text("mobile/qidus_asset_app/android/app/src/main/kotlin/org/qidus/asset/qidus_asset_app/MainActivity.kt")
    assert "FlutterFragmentActivity" in kt
    assert "AndroidKeyStore" in kt
    assert "SHA256withECDSA" in kt
    assert "secp256r1" in kt

    app_gradle = read_text("mobile/qidus_asset_app/android/app/build.gradle")
    assert 'namespace = "org.qidus.asset.qidus_asset_app"' in app_gradle
    assert (ROOT / "mobile/qidus_asset_app/android/settings.gradle").is_file()
    assert (ROOT / "mobile/qidus_asset_app/android/build.gradle").is_file()

    db_dart = read_text("mobile/qidus_asset_app/lib/core/storage/local_db.dart")
    assert "local_outbox" in db_dart
    assert "client_tx_uuid" in db_dart
    assert "local_event_allocations" in db_dart
