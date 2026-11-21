<?php
declare(strict_types=1);

use QidusAsset\Connector\ConnectorFactory;
use QidusAsset\Database\Connection;
use QidusAsset\Governance\ExecutiveAndAuditService;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\PasswordService;
use QidusAsset\Security\Rbac;

$pdo = Connection::get();
$role = (string)$user['role'];

if (!empty($_GET['export_audit_csv']) && Rbac::can($role, 'system.audit_read')) {
    $csv = ExecutiveAndAuditService::exportAuditLogsCsv($pdo, $_GET, $user);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $csv['filename'] . '"');
    header('X-Content-Type-Options: nosniff');
    echo $csv['body'];
    exit;
}

$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qaVerifyCsrf($_POST['csrf'] ?? null)) {
        $flashErr = 'Invalid CSRF token.';
    } else {
        try {
            $devAction = (string)($_POST['device_action'] ?? '');
            $devUuid = trim((string)($_POST['device_uuid'] ?? ''));
            if ($devUuid !== '' && in_array($devAction, ['approve', 'revoke'], true) && Rbac::can($role, 'system.device_approve')) {
                if ($devAction === 'approve') {
                    $pdo->prepare("UPDATE qa_device_keys SET status = 'active', approved_by = :by, approved_at = NOW(), revoked_at = NULL WHERE device_uuid = :du")
                        ->execute([':by' => (int)$user['uid'], ':du' => $devUuid]);
                } else {
                    $pdo->prepare("UPDATE qa_device_keys SET status = 'revoked', revoked_by = :by, revoked_at = NOW() WHERE device_uuid = :du")
                        ->execute([':by' => (int)$user['uid'], ':du' => $devUuid]);
                    $pdo->prepare("UPDATE qa_offline_leases SET revoked_at = NOW() WHERE device_uuid = :du AND revoked_at IS NULL")
                        ->execute([':du' => $devUuid]);
                }
                AuditLogger::log($pdo, [
                    'actor_user_id' => (int)$user['uid'],
                    'actor_role' => $role,
                    'device_uuid' => $devUuid,
                    'action' => 'device.' . $devAction,
                    'entity_type' => 'device',
                    'entity_id' => $devUuid,
                ]);
                $flashOk = 'Updated device ' . $devUuid . ' (' . $devAction . ').';
            }

            $userAction = (string)($_POST['user_action'] ?? '');
            if ($userAction === 'create_user' && Rbac::can($role, 'system.admin')) {
                $uname = trim((string)($_POST['username'] ?? ''));
                $fname = trim((string)($_POST['full_name'] ?? ''));
                $newRole = (string)($_POST['qa_role'] ?? 'requester');
                $pass = (string)($_POST['password'] ?? '');
                if ($uname === '' || $fname === '' || strlen($pass) < 10 || !in_array($newRole, Rbac::ALL_ROLES, true)) {
                    throw new \DomainException('Valid username, full_name, role, and 10+ char password required.');
                }
                $pdo->prepare(
                    'INSERT INTO qa_users (username, full_name, qa_role, department_id, password_hash, is_active)
                     VALUES (:u, :fn, :r, :d, :ph, 1)'
                )->execute([
                    ':u' => $uname,
                    ':fn' => $fname,
                    ':r' => $newRole,
                    ':d' => !empty($_POST['department_id']) ? (int)$_POST['department_id'] : 1,
                    ':ph' => PasswordService::hash($pass),
                ]);
                $newUid = (int)$pdo->lastInsertId();
                AuditLogger::log($pdo, [
                    'actor_user_id' => (int)$user['uid'],
                    'actor_role' => $role,
                    'action' => 'user.create',
                    'entity_type' => 'user',
                    'entity_id' => (string)$newUid,
                    'after' => ['username' => $uname, 'qa_role' => $newRole],
                ]);
                $flashOk = 'Created staff user ' . $uname . ' (' . $newRole . ').';
            } elseif ($userAction === 'toggle_user' && Rbac::can($role, 'system.admin')) {
                $targetId = (int)($_POST['target_user_id'] ?? 0);
                if ($targetId === (int)$user['uid']) {
                    throw new \DomainException('Cannot deactivate your own currently active administrator account.');
                }
                $pdo->prepare('UPDATE qa_users SET is_active = IF(is_active = 1, 0, 1), auth_version = auth_version + 1 WHERE id = :id')
                    ->execute([':id' => $targetId]);
                AuditLogger::log($pdo, [
                    'actor_user_id' => (int)$user['uid'],
                    'actor_role' => $role,
                    'action' => 'user.toggle_active',
                    'entity_type' => 'user',
                    'entity_id' => (string)$targetId,
                ]);
                $flashOk = 'Updated staff user #' . $targetId . ' status and rotated auth_version.';
            }

            $govAction = (string)($_POST['gov_action'] ?? '');
            if ($govAction === 'create_finding') {
                if (!Rbac::can($role, 'system.audit_finding_write')) {
                    throw new \DomainException('Only internal auditor role may open audit findings.');
                }
                $f = ExecutiveAndAuditService::createFinding($pdo, $_POST, $user);
                $flashOk = 'Opened audit finding ' . (string)$f['finding_code'] . '.';
            } elseif ($govAction === 'respond_finding') {
                if (!Rbac::can($role, 'asset.write') && !Rbac::can($role, 'finance.budget_manage') && !Rbac::can($role, 'system.admin')) {
                    throw new \DomainException('Department management permission required.');
                }
                $r = ExecutiveAndAuditService::respondToFinding($pdo, (int)($_POST['finding_id'] ?? 0), $_POST, $user);
                $flashOk = 'Finding updated to ' . (string)$r['status'] . '.';
            } elseif ($govAction === 'verify_finding') {
                if (!Rbac::can($role, 'system.audit_finding_write')) {
                    throw new \DomainException('Only internal auditor role may verify resolved findings.');
                }
                $v = ExecutiveAndAuditService::verifyFinding($pdo, (int)($_POST['finding_id'] ?? 0), $user);
                $flashOk = 'Finding verified (' . (string)$v['status'] . ').';
            } elseif ($govAction === 'sync_members') {
                if (!Rbac::can($role, 'members.sync_read')) {
                    throw new \DomainException('Permission denied.');
                }
                $conn = ConnectorFactory::memberConnector($pdo);
                if (!empty($_POST['new_member_name']) && Rbac::can($role, 'system.admin')) {
                    $extId = trim((string)($_POST['new_member_ext_id'] ?? ('ext_' . bin2hex(random_bytes(3)))));
                    $conn->upsertBatch([[
                        'external_member_id' => $extId,
                        'member_code' => strtoupper($extId),
                        'name_en' => trim((string)$_POST['new_member_name']),
                        'section_name' => trim((string)($_POST['new_member_section'] ?? 'General')),
                    ]], $conn->mode());
                    $flashOk = 'Upserted member ' . $extId . '.';
                } else {
                    $syncRes = $conn->syncChanges(null, 500);
                    $flashOk = 'Member sync (' . $conn->mode() . ') refreshed ' . count($syncRes['items']) . ' member(s).';
                }
            }
        } catch (\Throwable $ex) {
            $flashErr = $ex->getMessage();
        }
    }
}

$exec = ExecutiveAndAuditService::getExecutiveSummary($pdo);
$findings = ExecutiveAndAuditService::listFindings($pdo);
$devices = $pdo->query(
    "SELECT d.device_uuid, d.device_label, d.key_algorithm, d.status, u.username, d.created_at
     FROM qa_device_keys d JOIN qa_users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT 20"
)->fetchAll();

$usersList = Rbac::can($role, 'system.admin')
    ? $pdo->query("SELECT id, username, full_name, qa_role, is_active, auth_version FROM qa_users ORDER BY id ASC LIMIT 30")->fetchAll()
    : [];

$vaultDir = (string)qa_config('storage_path', QA_ROOT . '/storage/vault');
$vaultReady = is_dir($vaultDir) ? is_writable($vaultDir) : @mkdir($vaultDir, 0750, true);
$migCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_schema_migrations")->fetchColumn();
$years = $pdo->query("SELECT ec_year, label_en, label_am, status, start_date, end_date FROM qa_operational_years ORDER BY ec_year DESC")->fetchAll();

$auditFilters = [
    'action' => $_GET['audit_action'] ?? '',
    'outcome' => $_GET['audit_outcome'] ?? '',
];
$auditRows = ExecutiveAndAuditService::searchAuditLogs($pdo, $auditFilters, 25);

qaRenderShell(qaT('page.leadership'), 'leadership', $user, static function () use (
    $role, $flashOk, $flashErr, $exec, $findings, $vaultReady, $migCount, $years, $auditRows, $auditFilters, $devices, $usersList
): void {
    ?>
    <?php if ($flashOk !== ''): ?>
      <div class="qa-card" style="border-color:rgba(16,185,129,0.5);margin-bottom:16px;color:var(--accent-success);"><strong>Success:</strong> <?= e($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="qa-card" style="border-color:rgba(239,68,68,0.5);margin-bottom:16px;color:var(--accent-danger);"><strong>Error:</strong> <?= e($flashErr) ?></div>
    <?php endif; ?>

    <section class="qa-kpi-grid" aria-label="Cross-Department Executive KPIs">
      <div class="qa-kpi-card">
        <div class="qa-kpi-label"><?= e(qaT('exec.kpi_asset_val')) ?></div>
        <div class="qa-kpi-value tabular-nums"><?= number_format((float)$exec['asset_dept']['total_valuation_etb'], 2) ?></div>
        <div class="qa-kpi-meta"><?= (int)$exec['asset_dept']['total_assets'] ?> assets · <?= (int)$exec['asset_dept']['low_stock_alerts'] ?> low stock</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label"><?= e(qaT('exec.kpi_cash')) ?></div>
        <div class="qa-kpi-value tabular-nums" style="color:var(--accent-success);"><?= number_format((float)$exec['finance_dept']['net_cash_balance_etb'], 2) ?></div>
        <div class="qa-kpi-meta">ETB across active accounts</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label"><?= e(qaT('exec.kpi_budget')) ?></div>
        <div class="qa-kpi-value tabular-nums" style="color:var(--accent-primary);"><?= number_format((float)$exec['finance_dept']['budget_utilization_pct'], 1) ?>%</div>
        <div class="qa-kpi-meta"><?= number_format((float)$exec['finance_dept']['budget_allocated_etb'], 2) ?> ETB allocated</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label"><?= e(qaT('exec.kpi_pending')) ?></div>
        <div class="qa-kpi-value tabular-nums" style="color:var(--accent-warning);"><?= (int)$exec['asset_dept']['pending_requests'] + (int)$exec['asset_dept']['pending_disposals'] + (int)$exec['finance_dept']['pending_procurement_requests'] ?></div>
        <div class="qa-kpi-meta">Requests + Disposals + PRs</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label"><?= e(qaT('exec.kpi_findings')) ?></div>
        <div class="qa-kpi-value tabular-nums"><?= (int)$exec['governance']['open_findings'] ?></div>
        <div class="qa-kpi-meta"><?= (int)$exec['governance']['high_critical_findings'] ?> high/critical open</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label"><?= e(qaT('exec.kpi_health')) ?></div>
        <div class="qa-kpi-value tabular-nums" style="color:<?= $vaultReady ? 'var(--accent-success)' : 'var(--accent-danger)' ?>;"><?= $vaultReady ? 'OK' : 'WARN' ?></div>
        <div class="qa-kpi-meta"><?= $migCount ?> migrations · <?= e((string)qa_config('integration_mode', 'standalone')) ?></div>
      </div>
    </section>

    <section class="qa-panel-grid">
      <div class="qa-card">
        <div class="qa-card-header"><h2 class="qa-card-title"><?= e(qaT('exec.findings_title')) ?></h2><span class="qa-badge warning"><?= count($findings) ?> logged</span></div>
        <?php if (Rbac::can($role, 'system.audit_finding_write')): ?>
          <form method="post" style="margin-bottom:14px;">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="gov_action" value="create_finding">
            <div class="qa-field" style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;">
              <input name="title" class="qa-input" placeholder="Finding Title" required>
              <select name="severity" class="qa-select"><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option><option value="low">Low</option><option value="info">Info</option></select>
              <select name="department_id" class="qa-select"><option value="1">Asset Dept</option><option value="2">Finance Dept</option></select>
            </div>
            <div class="qa-field"><input name="description" class="qa-input" placeholder="Finding details & recommendation" required></div>
            <button type="submit" class="qa-btn qa-btn-primary">Open Audit Finding</button>
          </form>
        <?php endif; ?>
        <?php if ($findings === []): ?>
          <p style="color:var(--text-secondary);margin:0;">No internal audit findings recorded.</p>
        <?php else: ?>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Code</th><th>Severity</th><th>Title</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($findings as $f): ?>
              <tr>
                <td><code><?= e($f['finding_code']) ?></code></td>
                <td><span class="qa-badge <?= in_array($f['severity'], ['high','critical'], true) ? 'danger' : 'warning' ?>"><?= e($f['severity']) ?></span></td>
                <td><?= e($f['title']) ?></td>
                <td><span class="qa-badge <?= $f['status'] === 'verified' ? 'success' : '' ?>"><?= e($f['status']) ?></span></td>
                <td>
                  <?php if (in_array($f['status'], ['open', 'in_progress'], true) && (Rbac::can($role, 'asset.write') || Rbac::can($role, 'finance.budget_manage') || Rbac::can($role, 'system.admin'))): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="gov_action" value="respond_finding"><input type="hidden" name="finding_id" value="<?= (int)$f['id'] ?>"><input name="management_response" class="qa-input" placeholder="Corrective action" required style="width:120px;padding:3px 6px;"><button type="submit" class="qa-btn" style="padding:4px 8px;">Resolve</button></form>
                  <?php elseif ($f['status'] === 'resolved' && Rbac::can($role, 'system.audit_finding_write')): ?>
                    <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="gov_action" value="verify_finding"><input type="hidden" name="finding_id" value="<?= (int)$f['id'] ?>"><button type="submit" class="qa-btn qa-btn-primary" style="padding:4px 8px;">Verify</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>

      <div class="qa-card">
        <div class="qa-card-header"><h2 class="qa-card-title"><?= e(qaT('exec.members_years_title')) ?></h2><span class="qa-badge info"><?= (int)$exec['governance']['active_members'] ?> active</span></div>
        <?php if (Rbac::can($role, 'members.sync_read')): ?>
          <form method="post" style="margin-bottom:14px;">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="gov_action" value="sync_members">
            <?php if (Rbac::can($role, 'system.admin')): ?>
              <div class="qa-field" style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:8px;">
                <input name="new_member_ext_id" class="qa-input" placeholder="Ext ID (optional)">
                <input name="new_member_name" class="qa-input" placeholder="Standalone Member Full Name">
                <input name="new_member_section" class="qa-input" placeholder="Section">
              </div>
            <?php endif; ?>
            <button type="submit" class="qa-btn">Run Connector Sync / Save Member</button>
          </form>
        <?php endif; ?>
        <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>E.C. Year</th><th>Label</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($years as $yr): ?>
            <tr><td class="tabular-nums"><strong><?= (int)$yr['ec_year'] ?></strong></td><td><?= e($yr['label_en']) ?></td><td><span class="qa-badge <?= $yr['status'] === 'active' ? 'success' : '' ?>"><?= e($yr['status']) ?></span></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </div>
    </section>

    <section class="qa-panel-grid" style="margin-top:18px;">
      <div class="qa-card">
        <div class="qa-card-header"><h2 class="qa-card-title"><?= e(qaT('exec.devices_title')) ?></h2><span class="qa-badge info"><?= (int)$exec['governance']['active_devices'] ?> active / <?= (int)$exec['governance']['pending_devices'] ?> pending</span></div>
        <?php if ($devices === []): ?>
          <p style="color:var(--text-secondary);margin:0;">No mobile device keys enrolled yet.</p>
        <?php else: ?>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Device</th><th>User</th><th>Alg</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($devices as $dev): ?>
              <tr>
                <td><code><?= e($dev['device_uuid']) ?></code> (<?= e($dev['device_label']) ?>)</td><td><?= e($dev['username']) ?></td><td><?= e($dev['key_algorithm']) ?></td>
                <td><span class="qa-badge <?= $dev['status'] === 'active' ? 'success' : ($dev['status'] === 'revoked' ? 'danger' : 'warning') ?>"><?= e($dev['status']) ?></span></td>
                <td>
                  <?php if (Rbac::can($role, 'system.device_approve')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;">
                      <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="device_uuid" value="<?= e($dev['device_uuid']) ?>">
                      <?php if ($dev['status'] !== 'active'): ?><button name="device_action" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button><?php endif; ?>
                      <?php if ($dev['status'] !== 'revoked'): ?><button name="device_action" value="revoke" class="qa-btn" style="padding:4px 8px;">Revoke</button><?php endif; ?>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>

      <?php if (Rbac::can($role, 'system.admin')): ?>
      <div class="qa-card">
        <div class="qa-card-header"><h2 class="qa-card-title"><?= e(qaT('exec.staff_title')) ?></h2><span class="qa-badge">RBAC</span></div>
        <form method="post" style="margin-bottom:12px;">
          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
          <input type="hidden" name="user_action" value="create_user">
          <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <input name="username" class="qa-input" placeholder="Username" required>
            <input name="full_name" class="qa-input" placeholder="Full Name" required>
          </div>
          <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
            <select name="qa_role" class="qa-select"><?php foreach (Rbac::ALL_ROLES as $rk): ?><option value="<?= e($rk) ?>"><?= e($rk) ?></option><?php endforeach; ?></select>
            <select name="department_id" class="qa-select"><option value="1">Asset Dept</option><option value="2">Finance Dept</option></select>
            <input name="password" type="password" minlength="10" class="qa-input" placeholder="Password (10+)" required>
          </div>
          <button type="submit" class="qa-btn qa-btn-primary">Create Staff Account</button>
        </form>
        <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>User</th><th>Role</th><th>Ver</th><th>Status</th><th>Action</th></tr></thead><tbody>
          <?php foreach ($usersList as $uRow): ?>
            <tr>
              <td><code><?= e($uRow['username']) ?></code></td><td><?= e($uRow['qa_role']) ?></td><td class="tabular-nums">v<?= (int)$uRow['auth_version'] ?></td>
              <td><span class="qa-badge <?= (int)$uRow['is_active'] === 1 ? 'success' : 'danger' ?>"><?= (int)$uRow['is_active'] === 1 ? 'active' : 'disabled' ?></span></td>
              <td>
                <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="user_action" value="toggle_user"><input type="hidden" name="target_user_id" value="<?= (int)$uRow['id'] ?>"><button type="submit" class="qa-btn" style="padding:4px 8px;"><?= (int)$uRow['is_active'] === 1 ? 'Disable' : 'Enable' ?></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </div>
      <?php endif; ?>
    </section>

    <section class="qa-card" style="margin-top:18px;">
      <div class="qa-card-header" style="flex-wrap:wrap;gap:10px;">
        <h2 class="qa-card-title"><?= e(qaT('exec.audit_title')) ?></h2>
        <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;">
          <input type="hidden" name="page" value="leadership">
          <input name="audit_action" value="<?= e((string)$auditFilters['action']) ?>" class="qa-input" placeholder="Filter action (e.g. login)" style="width:180px;">
          <select name="audit_outcome" class="qa-select"><option value="">All Outcomes</option><?php foreach (['accepted','rejected','conflict','correction','failed'] as $oc): ?><option value="<?= e($oc) ?>" <?= $auditFilters['outcome'] === $oc ? 'selected' : '' ?>><?= e($oc) ?></option><?php endforeach; ?></select>
          <button type="submit" class="qa-btn">Filter</button>
          <?php if (Rbac::can($role, 'system.audit_read')): ?>
            <a class="qa-btn qa-btn-primary" href="index.php?page=leadership&amp;export_audit_csv=1&amp;action=<?= e(urlencode((string)$auditFilters['action'])) ?>&amp;outcome=<?= e(urlencode((string)$auditFilters['outcome'])) ?>">Export Audit CSV</a>
          <?php endif; ?>
        </form>
      </div>
      <?php if ($auditRows === []): ?>
        <p style="color:var(--text-secondary);margin:0;">No audit events match the filter.</p>
      <?php else: ?>
        <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Action</th><th>Role</th><th>Entity</th><th>Outcome</th><th>UTC Time</th></tr></thead><tbody>
          <?php foreach ($auditRows as $row): ?>
            <tr>
              <td><code><?= e($row['action']) ?></code></td><td><?= e($row['actor_role']) ?></td><td><?= e($row['entity_type']) ?>:<?= e($row['entity_id']) ?></td>
              <td><span class="qa-badge <?= $row['outcome'] === 'accepted' ? 'success' : 'danger' ?>"><?= e($row['outcome']) ?></span></td>
              <td class="tabular-nums"><?= e($row['server_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </section>
    <?php
});
