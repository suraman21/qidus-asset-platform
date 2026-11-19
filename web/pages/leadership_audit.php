<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;

$pdo = Connection::get();

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
    $deptCount, $yearCount, $userCount, $deviceCount, $pendingDevices, $memberCount, $years, $auditRows
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
      </div>
    </section>
    <?php
});
