<?php
declare(strict_types=1);

use QidusAsset\Asset\AssetLifecycleService;
use QidusAsset\Asset\AssetService;
use QidusAsset\Asset\AssetWorkflowService;
use QidusAsset\Database\Connection;
use QidusAsset\Security\Rbac;

$pdo = Connection::get();
$role = (string)$user['role'];
$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qaVerifyCsrf($_POST['csrf'] ?? null)) {
        $flashErr = 'Invalid CSRF token. Please refresh and retry.';
    } else {
        $postAction = (string)($_POST['asset_action'] ?? '');
        try {
            if ($postAction === 'create_asset') {
                if (!Rbac::can($role, 'asset.write')) {
                    throw new \DomainException('Permission denied: asset.write required.');
                }
                $created = AssetService::createAsset($pdo, $_POST, $user);
                $flashOk = 'Registered asset ' . (string)$created['asset_code'] . ' (QR: ' . (string)$created['qr_token'] . ').';
            } elseif ($postAction === 'stock_movement') {
                if (!Rbac::can($role, 'asset.stock_move')) {
                    throw new \DomainException('Permission denied: asset.stock_move required.');
                }
                $res = AssetService::recordMovement($pdo, (int)($_POST['asset_id'] ?? 0), $_POST, $user);
                $flashOk = 'Recorded movement #' . (int)$res['movement_id'] . ' — balance is now ' . (int)$res['balance_after'] . '.';
            } elseif ($postAction === 'create_request') {
                if (!Rbac::can($role, 'asset.request_submit')) {
                    throw new \DomainException('Permission denied.');
                }
                $req = AssetWorkflowService::createRequest($pdo, $_POST, $user);
                $flashOk = 'Submitted asset request ' . (string)$req['request_number'] . '.';
            } elseif ($postAction === 'dept_head_decide') {
                if (!Rbac::can($role, 'asset.request_dept_head')) {
                    throw new \DomainException('Permission denied: asset.request_dept_head required.');
                }
                $res = AssetWorkflowService::departmentHeadDecision(
                    $pdo,
                    (int)($_POST['request_id'] ?? 0),
                    (string)($_POST['decision'] ?? ''),
                    (string)($_POST['note'] ?? ''),
                    $user
                );
                $flashOk = 'Request updated to ' . (string)$res['status'] . '.';
            } elseif ($postAction === 'deputy_decide') {
                if (!Rbac::can($role, 'asset.request_deputy')) {
                    throw new \DomainException('Permission denied: asset.request_deputy required.');
                }
                $res = AssetWorkflowService::deputyDecision(
                    $pdo,
                    (int)($_POST['request_id'] ?? 0),
                    (string)($_POST['decision'] ?? ''),
                    (string)($_POST['note'] ?? ''),
                    $user
                );
                $flashOk = 'Deputy decision recorded: ' . (string)$res['status'] . '.';
            } elseif ($postAction === 'create_maintenance') {
                if (!Rbac::can($role, 'asset.maintenance_write')) {
                    throw new \DomainException('Permission denied: asset.maintenance_write required.');
                }
                $t = AssetLifecycleService::createMaintenanceTicket($pdo, $_POST, $user);
                $flashOk = 'Opened maintenance ticket ' . (string)$t['ticket_number'] . '.';
            }
        } catch (\Throwable $ex) {
            $flashErr = $ex->getMessage();
        }
    }
}

$fixedCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_assets WHERE tracking_mode = 'fixed'")->fetchColumn();
$stockSkuCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_assets WHERE tracking_mode IN ('consumable','reusable_member')")->fetchColumn();
$lowStockCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_assets WHERE quantity <= min_quantity AND status <> 'archived'")->fetchColumn();
$activeHandovers = (int)$pdo->query("SELECT COUNT(*) FROM qa_asset_handovers WHERE handover_type = 'issue'")->fetchColumn();
$openRequests = (int)$pdo->query("SELECT COUNT(*) FROM qa_asset_requests WHERE status IN ('PENDING_DEPARTMENT_HEAD','PENDING_DEPUTY_CHAIRPERSON')")->fetchColumn();
$openMaint = (int)$pdo->query("SELECT COUNT(*) FROM qa_asset_maintenance WHERE status IN ('scheduled','in_progress')")->fetchColumn();

$categories = $pdo->query("SELECT id, code, name_en, name_am, tracking_mode FROM qa_asset_categories WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$locations = $pdo->query("SELECT id, code, name_en FROM qa_asset_locations WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$assets = $pdo->query(
    "SELECT a.id, a.asset_code, a.qr_token, a.name_en, a.tracking_mode, a.quantity, a.min_quantity, a.status, a.condition_status, c.name_en AS category_name
     FROM qa_assets a JOIN qa_asset_categories c ON c.id = a.category_id
     ORDER BY a.id DESC LIMIT 20"
)->fetchAll();
$requests = $pdo->query(
    "SELECT r.*, u.full_name AS requester_name
     FROM qa_asset_requests r JOIN qa_users u ON u.id = r.requester_id
     ORDER BY r.id DESC LIMIT 12"
)->fetchAll();
$handovers = $pdo->query(
    "SELECT h.handover_number, h.handover_type, h.receiver_display_name, h.quantity, h.verification_method, h.server_received_at, a.asset_code
     FROM qa_asset_handovers h JOIN qa_assets a ON a.id = h.asset_id
     ORDER BY h.id DESC LIMIT 10"
)->fetchAll();
$tickets = $pdo->query(
    "SELECT m.ticket_number, m.maintenance_type, m.priority, m.status, a.asset_code, a.name_en AS asset_name
     FROM qa_asset_maintenance m JOIN qa_assets a ON a.id = m.asset_id
     ORDER BY m.id DESC LIMIT 10"
)->fetchAll();

qaRenderShell('Asset Department', 'asset', $user, static function () use (
    $role, $flashOk, $flashErr,
    $fixedCount, $stockSkuCount, $lowStockCount, $activeHandovers, $openRequests, $openMaint,
    $categories, $locations, $assets, $requests, $handovers, $tickets
): void {
    ?>
    <?php if ($flashOk !== ''): ?>
      <div class="qa-card" style="border-color:rgba(16,185,129,0.5);margin-bottom:16px;color:var(--accent-success);">
        <strong>Success:</strong> <?= e($flashOk) ?>
      </div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="qa-card" style="border-color:rgba(239,68,68,0.5);margin-bottom:16px;color:var(--accent-danger);">
        <strong>Error:</strong> <?= e($flashErr) ?>
      </div>
    <?php endif; ?>

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
      <?php if (Rbac::can($role, 'asset.write')): ?>
      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title">Register Asset / Stock Item</h2>
          <span class="qa-badge success">Auto QR</span>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
          <input type="hidden" name="asset_action" value="create_asset">
          <div class="qa-field">
            <label for="qaAssetName">Asset / Item Name (English)</label>
            <input id="qaAssetName" name="name_en" class="qa-input" required placeholder="e.g. Yamaha Mixer MG12XU">
          </div>
          <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div>
              <label for="qaAssetCat">Category</label>
              <select id="qaAssetCat" name="category_id" class="qa-select" style="width:100%;">
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name_en']) ?> (<?= e($cat['tracking_mode']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="qaAssetLoc">Location</label>
              <select id="qaAssetLoc" name="location_id" class="qa-select" style="width:100%;">
                <?php foreach ($locations as $loc): ?>
                  <option value="<?= (int)$loc['id'] ?>"><?= e($loc['name_en']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div>
              <label for="qaAssetQty">Opening Quantity</label>
              <input id="qaAssetQty" name="quantity" type="number" min="0" value="1" class="qa-input">
            </div>
            <div>
              <label for="qaAssetMin">Minimum Alert Qty</label>
              <input id="qaAssetMin" name="min_quantity" type="number" min="0" value="0" class="qa-input">
            </div>
          </div>
          <button type="submit" class="qa-btn qa-btn-primary">Register Asset</button>
        </form>
      </div>
      <?php endif; ?>

      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title">Submit Asset Request (2-Step)</h2>
          <span class="qa-badge info">Workflow</span>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
          <input type="hidden" name="asset_action" value="create_request">
          <div class="qa-field">
            <label for="qaReqItem">Requested Item</label>
            <input id="qaReqItem" name="requested_item_name" class="qa-input" required placeholder="e.g. Choir Uniforms Size M">
          </div>
          <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div>
              <label for="qaReqQty">Quantity</label>
              <input id="qaReqQty" name="quantity" type="number" min="1" value="1" class="qa-input">
            </div>
            <div>
              <label for="qaReqUrg">Urgency</label>
              <select id="qaReqUrg" name="urgency" class="qa-select" style="width:100%;">
                <option value="normal">Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
                <option value="low">Low</option>
              </select>
            </div>
          </div>
          <div class="qa-field">
            <label for="qaReqReason">Justification</label>
            <input id="qaReqReason" name="reason" class="qa-input" required placeholder="Purpose and activity">
          </div>
          <button type="submit" class="qa-btn qa-btn-primary">Submit Request</button>
        </form>
      </div>
    </section>

    <section class="qa-card" style="margin-bottom:20px;">
      <div class="qa-card-header">
        <h2 class="qa-card-title">Inventory &amp; QR Registry</h2>
        <span class="qa-badge success"><?= count($assets) ?> recent items</span>
      </div>
      <?php if ($assets === []): ?>
        <p style="color:var(--text-secondary);margin:0;">No assets registered yet. Use the form above or <code>POST /api/v1/assets</code>.</p>
      <?php else: ?>
        <div class="qa-table-wrap">
          <table class="qa-table">
            <thead>
              <tr><th>Code</th><th>Name</th><th>Category</th><th>Mode</th><th>Qty</th><th>Condition</th><th>Status</th><th>QR Token</th></tr>
            </thead>
            <tbody>
              <?php foreach ($assets as $a): ?>
                <tr>
                  <td><code><?= e($a['asset_code']) ?></code></td>
                  <td><?= e($a['name_en']) ?></td>
                  <td><?= e($a['category_name']) ?></td>
                  <td><span class="qa-badge"><?= e($a['tracking_mode']) ?></span></td>
                  <td class="tabular-nums"><?= (int)$a['quantity'] ?></td>
                  <td><?= e($a['condition_status']) ?></td>
                  <td><span class="qa-badge <?= $a['status'] === 'in_stock' ? 'success' : ($a['status'] === 'low_stock' ? 'warning' : 'info') ?>"><?= e($a['status']) ?></span></td>
                  <td><code><?= e(substr((string)$a['qr_token'], 0, 14)) ?>…</code></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="qa-panel-grid">
      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title">2-Step Asset Request Queue</h2>
          <span class="qa-badge info">Dept Head → Deputy</span>
        </div>
        <?php if ($requests === []): ?>
          <p style="color:var(--text-secondary);margin:0;">No asset requests submitted yet.</p>
        <?php else: ?>
          <div class="qa-table-wrap">
            <table class="qa-table">
              <thead><tr><th>Req #</th><th>Item</th><th>Qty</th><th>Requester</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($requests as $r): ?>
                  <tr>
                    <td><code><?= e($r['request_number']) ?></code></td>
                    <td><?= e($r['requested_item_name']) ?></td>
                    <td class="tabular-nums"><?= (int)$r['quantity'] ?></td>
                    <td><?= e($r['requester_name']) ?></td>
                    <td><span class="qa-badge"><?= e($r['status']) ?></span></td>
                    <td>
                      <?php if ($r['status'] === 'PENDING_DEPARTMENT_HEAD' && Rbac::can($role, 'asset.request_dept_head')): ?>
                        <form method="post" style="display:inline-flex;gap:4px;">
                          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
                          <input type="hidden" name="asset_action" value="dept_head_decide">
                          <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                          <button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button>
                          <button name="decision" value="reject" class="qa-btn" style="padding:4px 8px;">Reject</button>
                        </form>
                      <?php elseif ($r['status'] === 'PENDING_DEPUTY_CHAIRPERSON' && Rbac::can($role, 'asset.request_deputy')): ?>
                        <form method="post" style="display:inline-flex;gap:4px;">
                          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
                          <input type="hidden" name="asset_action" value="deputy_decide">
                          <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                          <button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button>
                          <button name="decision" value="reject" class="qa-btn" style="padding:4px 8px;">Reject</button>
                        </form>
                      <?php else: ?>
                        <span style="color:var(--text-muted);">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title">Signed Handovers &amp; Maintenance</h2>
          <span class="qa-badge">Custody</span>
        </div>
        <?php if ($handovers === [] && $tickets === []): ?>
          <p style="color:var(--text-secondary);margin:0;">No custody handovers or maintenance tickets recorded yet.</p>
        <?php else: ?>
          <div class="qa-table-wrap">
            <table class="qa-table">
              <thead><tr><th>Ref #</th><th>Asset</th><th>Recipient / Type</th><th>Method / Status</th></tr></thead>
              <tbody>
                <?php foreach ($handovers as $h): ?>
                  <tr>
                    <td><code><?= e($h['handover_number']) ?></code></td>
                    <td><?= e($h['asset_code']) ?></td>
                    <td><?= e($h['receiver_display_name']) ?> (<?= (int)$h['quantity'] ?>)</td>
                    <td><span class="qa-badge success"><?= e($h['verification_method']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php foreach ($tickets as $t): ?>
                  <tr>
                    <td><code><?= e($t['ticket_number']) ?></code></td>
                    <td><?= e($t['asset_code']) ?></td>
                    <td><?= e($t['maintenance_type']) ?> · <?= e($t['priority']) ?></td>
                    <td><span class="qa-badge warning"><?= e($t['status']) ?></span></td>
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
