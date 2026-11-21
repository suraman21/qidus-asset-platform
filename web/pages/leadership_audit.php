<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\PasswordService;
use QidusAsset\Security\Rbac;

$pdo = Connection::get();
$role = (string)$user['role'];
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
        } catch (\Throwable $ex) {
            $flashErr = $ex->getMessage();
        }
    }
}

$devices = $pdo->query(
    "SELECT d.device_uuid, d.device_label, d.key_algorithm, d.status, u.username, d.created_at
     FROM qa_device_keys d JOIN qa_users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT 20"
)->fetchAll();

$usersList = Rbac::can($role, 'system.admin')
    ? $pdo->query("SELECT id, username, full_name, qa_role, is_active, auth_version FROM qa_users ORDER BY id ASC LIMIT 30")->fetchAll()
    : [];

$deptCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_departments WHERE is_active = 1")->fetchColumn();
$yearCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_operational_years")->fetchColumn();
$userCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_users WHERE is_active = 1")->fetchColumn();
$deviceCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_device_keys WHERE status = 'active'")->fetchColumn();
$pendingDevices = (int)$pdo->query("SELECT COUNT(*) FROM qa_device_keys WHERE status = 'pending'")->fetchColumn();
$memberCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_member_sync_cache WHERE is_tombstoned = 0")->fetchColumn();

$vaultDir = (string)qa_config('storage_path', QA_ROOT . '/storage/vault');
$vaultReady = is_dir($vaultDir) ? is_writable($vaultDir) : @mkdir($vaultDir, 0750, true);
$migCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_schema_migrations")->fetchColumn();

$years = $pdo->query("SELECT ec_year, label_en, label_am, status, start_date, end_date FROM qa_operational_years ORDER BY ec_year DESC")->fetchAll();
$auditRows = $pdo->query(
    "SELECT id, actor_role, action, entity_type, entity_id, outcome, server_at FROM qa_audit_logs ORDER BY id DESC LIMIT 12"
)->fetchAll();

qaRenderShell('Executive Overview & Audit Console', 'leadership', $user, static function () use (
    $role, $flashOk, $flashErr, $deptCount, $yearCount, $userCount, $deviceCount, $pendingDevices, $memberCount,
    $vaultReady, $migCount, $years, $auditRows, $devices, $usersList
): void {
    ?>
    <?php if ($flashOk !== ''): ?>
      <div class="qa-card" style="border-color:rgba(16,185,129,0.5);margin-bottom:16px;color:var(--accent-success);"><strong>Success:</strong> <?= e($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="qa-card" style="border-color:rgba(239,68,68,0.5);margin-bottom:16px;color:var(--accent-danger);"><strong>Error:</strong> <?= e($flashErr) ?></div>
    <?php endif; ?>

    <section class="qa-kpi-grid" aria-label="Platform Governance KPIs">
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Active Departments</div>
        <div class="qa-kpi-value tabular-nums"><?= $deptCount ?></div>
        <div class="qa-kpi-meta">Asset + Finance &amp; Related</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Operational Years</div>
        <div class="qa-kpi-value tabular-nums"><?= $yearCount ?></div>
        <div class="qa-kpi-meta">Ethiopian fiscal cycles</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Active Staff Profiles</div>
        <div class="qa-kpi-value tabular-nums"><?= $userCount ?></div>
        <div class="qa-kpi-meta">RBAC-governed accounts</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Approved Devices</div>
        <div class="qa-kpi-value tabular-nums" style="color:var(--accent-success);"><?= $deviceCount ?></div>
        <div class="qa-kpi-meta"><?= $pendingDevices ?> pending approval</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Synced Members</div>
        <div class="qa-kpi-value tabular-nums" style="color:var(--accent-primary);"><?= $memberCount ?></div>
        <div class="qa-kpi-meta">Connector directory cache</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">System Health</div>
        <div class="qa-kpi-value tabular-nums" style="color:<?= $vaultReady ? 'var(--accent-success)' : 'var(--accent-danger)' ?>;"><?= $vaultReady ? 'OK' : 'WARN' ?></div>
        <div class="qa-kpi-meta"><?= $migCount ?> migrations · <?= e((string)qa_config('integration_mode', 'standalone')) ?></div>
      </div>
    </section>

    <section class="qa-panel-grid">
      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title">Operational / Fiscal Years</h2>
          <span class="qa-badge info">Lifecycle</span>
        </div>
        <div class="qa-table-wrap">
          <table class="qa-table">
            <thead><tr><th>E.C. Year</th><th>Label</th><th>Amharic</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($years as $yr): ?>
                <tr>
                  <td class="tabular-nums"><strong><?= (int)$yr['ec_year'] ?></strong></td>
                  <td><?= e($yr['label_en']) ?></td>
                  <td><?= e($yr['label_am']) ?></td>
                  <td><span class="qa-badge <?= $yr['status'] === 'active' ? 'success' : '' ?>"><?= e($yr['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title">Registered Android Devices (Keystore)</h2>
          <span class="qa-badge info">FR-19</span>
        </div>
        <?php if ($devices === []): ?>
          <p style="color:var(--text-secondary);margin:0;">No mobile device keys enrolled yet.</p>
        <?php else: ?>
          <div class="qa-table-wrap">
            <table class="qa-table">
              <thead><tr><th>Device</th><th>User</th><th>Alg</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($devices as $dev): ?>
                  <tr>
                    <td><code><?= e($dev['device_uuid']) ?></code> (<?= e($dev['device_label']) ?>)</td>
                    <td><?= e($dev['username']) ?></td>
                    <td><?= e($dev['key_algorithm']) ?></td>
                    <td><span class="qa-badge <?= $dev['status'] === 'active' ? 'success' : ($dev['status'] === 'revoked' ? 'danger' : 'warning') ?>"><?= e($dev['status']) ?></span></td>
                    <td>
                      <?php if (Rbac::can($role, 'system.device_approve')): ?>
                        <form method="post" style="display:inline-flex;gap:4px;">
                          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
                          <input type="hidden" name="device_uuid" value="<?= e($dev['device_uuid']) ?>">
                          <?php if ($dev['status'] !== 'active'): ?><button name="device_action" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button><?php endif; ?>
                          <?php if ($dev['status'] !== 'revoked'): ?><button name="device_action" value="revoke" class="qa-btn" style="padding:4px 8px;">Revoke</button><?php endif; ?>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <?php if (Rbac::can($role, 'system.admin')): ?>
    <section class="qa-panel-grid" style="margin-top:18px;">
      <div class="qa-card">
        <div class="qa-card-header"><h2 class="qa-card-title">Provision Staff User Account</h2><span class="qa-badge">RBAC</span></div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
          <input type="hidden" name="user_action" value="create_user">
          <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div><label>Username</label><input name="username" class="qa-input" required></div>
            <div><label>Full Name</label><input name="full_name" class="qa-input" required></div>
          </div>
          <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
            <div><label>Role</label><select name="qa_role" class="qa-select" style="width:100%;"><?php foreach (Rbac::ALL_ROLES as $rk): ?><option value="<?= e($rk) ?>"><?= e($rk) ?></option><?php endforeach; ?></select></div>
            <div><label>Department</label><select name="department_id" class="qa-select" style="width:100%;"><option value="1">Asset Dept</option><option value="2">Finance Dept</option></select></div>
            <div><label>Password (10+)</label><input name="password" type="password" minlength="10" class="qa-input" required></div>
          </div>
          <button type="submit" class="qa-btn qa-btn-primary">Create Staff Account</button>
        </form>
      </div>

      <div class="qa-card">
        <div class="qa-card-header"><h2 class="qa-card-title">Staff Directory &amp; Session Control</h2></div>
        <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>User</th><th>Role</th><th>Ver</th><th>Status</th><th>Action</th></tr></thead><tbody>
          <?php foreach ($usersList as $uRow): ?>
            <tr>
              <td><code><?= e($uRow['username']) ?></code> (<?= e($uRow['full_name']) ?>)</td>
              <td><?= e($uRow['qa_role']) ?></td>
              <td class="tabular-nums">v<?= (int)$uRow['auth_version'] ?></td>
              <td><span class="qa-badge <?= (int)$uRow['is_active'] === 1 ? 'success' : 'danger' ?>"><?= (int)$uRow['is_active'] === 1 ? 'active' : 'disabled' ?></span></td>
              <td>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
                  <input type="hidden" name="user_action" value="toggle_user">
                  <input type="hidden" name="target_user_id" value="<?= (int)$uRow['id'] ?>">
                  <button type="submit" class="qa-btn" style="padding:4px 8px;"><?= (int)$uRow['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </div>
    </section>
    <?php endif; ?>

    <section class="qa-card" style="margin-top:18px;">
      <div class="qa-card-header">
        <h2 class="qa-card-title">Recent Audit Trail</h2>
        <span class="qa-badge">Immutable</span>
      </div>
        <?php if ($auditRows === []): ?>
          <p style="color:var(--text-secondary);margin:0;">No audit events recorded yet.</p>
        <?php else: ?>
          <div class="qa-table-wrap">
            <table class="qa-table">
              <thead><tr><th>Action</th><th>Role</th><th>Entity</th><th>Outcome</th><th>UTC Time</th></tr></thead>
              <tbody>
                <?php foreach ($auditRows as $row): ?>
                  <tr>
                    <td><code><?= e($row['action']) ?></code></td>
                    <td><?= e($row['actor_role']) ?></td>
                    <td><?= e($row['entity_type']) ?>:<?= e($row['entity_id']) ?></td>
                    <td><span class="qa-badge <?= $row['outcome'] === 'accepted' ? 'success' : 'danger' ?>"><?= e($row['outcome']) ?></span></td>
                    <td class="tabular-nums"><?= e($row['server_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
    </section>
    <?php
});
