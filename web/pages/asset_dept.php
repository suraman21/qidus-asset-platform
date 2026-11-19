<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;

$pdo = Connection::get();

$fixedCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_assets WHERE tracking_mode = 'fixed'")->fetchColumn();
$stockSkuCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_assets WHERE tracking_mode IN ('consumable','reusable_member')")->fetchColumn();
$lowStockCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_assets WHERE quantity <= min_quantity AND status <> 'archived'")->fetchColumn();
$activeHandovers = (int)$pdo->query("SELECT COUNT(*) FROM qa_asset_handovers WHERE handover_type = 'issue'")->fetchColumn();
$openRequests = (int)$pdo->query("SELECT COUNT(*) FROM qa_asset_requests WHERE status IN ('PENDING_DEPARTMENT_HEAD','PENDING_DEPUTY_CHAIRPERSON')")->fetchColumn();
$openMaint = (int)$pdo->query("SELECT COUNT(*) FROM qa_asset_maintenance WHERE status IN ('scheduled','in_progress')")->fetchColumn();

$recentEvents = $pdo->query(
    "SELECT id, event_code, name_en, status, event_date FROM qa_events ORDER BY id DESC LIMIT 6"
)->fetchAll();

$categories = $pdo->query(
    "SELECT code, name_en, name_am, tracking_mode FROM qa_asset_categories WHERE is_active = 1 ORDER BY id ASC LIMIT 12"
)->fetchAll();

qaRenderShell('Asset Department', 'asset', $user, static function () use (
    $fixedCount, $stockSkuCount, $lowStockCount, $activeHandovers, $openRequests, $openMaint, $recentEvents, $categories
): void {
    ?>
    <section class="qa-kpi-grid" aria-label="Asset Department KPIs">
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Fixed Assets</div>
        <div class="qa-kpi-value tabular-nums"><?= $fixedCount ?></div>
        <div class="qa-kpi-meta">Serialized QR inventory</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Stock &amp; Reusable</div>
        <div class="qa-kpi-value tabular-nums"><?= $stockSkuCount ?></div>
        <div class="qa-kpi-meta">Consumables &amp; uniforms</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Low Stock Alerts</div>
        <div class="qa-kpi-value tabular-nums" style="color:var(--accent-warning);"><?= $lowStockCount ?></div>
        <div class="qa-kpi-meta">At or below minimum</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Issued Handovers</div>
        <div class="qa-kpi-value tabular-nums"><?= $activeHandovers ?></div>
        <div class="qa-kpi-meta">Signed custody records</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Open Requests</div>
        <div class="qa-kpi-value tabular-nums" style="color:var(--accent-primary);"><?= $openRequests ?></div>
        <div class="qa-kpi-meta">2-step approval queue</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Maintenance Queue</div>
        <div class="qa-kpi-value tabular-nums"><?= $openMaint ?></div>
        <div class="qa-kpi-meta">Open repair tickets</div>
      </div>
    </section>

    <section class="qa-panel-grid">
      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title">Seeded Asset Categories</h2>
          <span class="qa-badge success">Dept 1 Ready</span>
        </div>
        <div class="qa-table-wrap">
          <table class="qa-table">
            <thead>
              <tr><th>Code</th><th>Category</th><th>Amharic</th><th>Tracking</th></tr>
            </thead>
            <tbody>
              <?php foreach ($categories as $cat): ?>
                <tr>
                  <td><code><?= e($cat['code']) ?></code></td>
                  <td><?= e($cat['name_en']) ?></td>
                  <td><?= e($cat['name_am']) ?></td>
                  <td><span class="qa-badge"><?= e($cat['tracking_mode']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title">Recent Multi-Station Events</h2>
          <span class="qa-badge info">Phase 2 Endpoint Queue</span>
        </div>
        <?php if ($recentEvents === []): ?>
          <p style="color:var(--text-secondary);margin:0;">No rapid-issue events scheduled yet. Event distribution stations &amp; offline sync endpoints unlock in Phase 2.</p>
        <?php else: ?>
          <div class="qa-table-wrap">
            <table class="qa-table">
              <thead><tr><th>Code</th><th>Name</th><th>Date</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($recentEvents as $ev): ?>
                  <tr>
                    <td><code><?= e($ev['event_code']) ?></code></td>
                    <td><?= e($ev['name_en']) ?></td>
                    <td class="tabular-nums"><?= e($ev['event_date']) ?></td>
                    <td><span class="qa-badge"><?= e($ev['status']) ?></span></td>
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
