<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\Rbac;

$pdo = Connection::get();
$role = (string)$user['role'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && qaVerifyCsrf($_POST['csrf'] ?? null)) {
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
    }
}

$devices = $pdo->query(
    "SELECT d.device_uuid, d.device_label, d.key_algorithm, d.status, u.username, d.created_at
     FROM qa_device_keys d JOIN qa_users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT 20"
)->fetchAll();

$deptCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_departments WHERE is_active = 1")->fetchColumn();
$yearCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_operational_years")->fetchColumn();
$userCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_users WHERE is_active = 1")->fetchColumn();
$deviceCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_device_keys WHERE status = 'active'")->fetchColumn();
$pendingDevices = (int)$pdo->query("SELECT COUNT(*) FROM qa_device_keys WHERE status = 'pending'")->fetchColumn();
$memberCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_member_sync_cache WHERE is_tombstoned = 0")->fetchColumn();

$years = $pdo->query("SELECT ec_year, label_en, label_am, status, start_date, end_date FROM qa_operational_years ORDER BY ec_year DESC")->fetchAll();
$auditRows = $pdo->query(
    "SELECT id, actor_role, action, entity_type, entity_id, outcome, server_at FROM qa_audit_logs ORDER BY id DESC LIMIT 12"
)->fetchAll();

qaRenderShell('Executive Overview & Audit Console', 'leadership', $user, static function () use (
    $role, $deptCount, $yearCount, $userCount, $deviceCount, $pendingDevices, $memberCount, $years, $auditRows, $devices
): void {
    ?>
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
        <div class="qa-kpi-label">Audit Events</div>
        <div class="qa-kpi-value tabular-nums"><?= count($auditRows) ?>+</div>
        <div class="qa-kpi-meta">Append-only trail</div>
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
