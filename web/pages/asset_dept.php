<?php
declare(strict_types=1);

use QidusAsset\Asset\AssetLifecycleService;
use QidusAsset\Asset\AssetReportService;
use QidusAsset\Asset\AssetService;
use QidusAsset\Asset\AssetWorkflowService;
use QidusAsset\Asset\OperationalYearService;
use QidusAsset\Database\Connection;
use QidusAsset\Security\Rbac;
use QidusAsset\Support\QrLabelService;

$pdo = Connection::get();
$role = (string)$user['role'];
$tab = (string)($_GET['tab'] ?? 'inventory');
if (!in_array($tab, ['inventory', 'handovers', 'requests', 'events', 'maintenance', 'reports'], true)) {
    $tab = 'inventory';
}

// Printable QR Sheet or Report Export GET handlers
if (isset($_GET['export']) && $_GET['export'] === 'qr_sheet') {
    $rows = $pdo->query("SELECT id, asset_code, name_en, name_am, qr_token FROM qa_assets WHERE status <> 'archived' ORDER BY id DESC LIMIT 30")->fetchAll();
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: text/html; charset=utf-8');
    echo QrLabelService::renderLabelSheetHtml($rows, (string)qa_config('school_name_en', 'QIDUS ASSET'));
    exit;
}

if (!empty($_GET['export_report'])) {
    $repKey = (string)$_GET['export_report'];
    $fmt = (string)($_GET['format'] ?? 'csv');
    $file = AssetReportService::export($pdo, $repKey, $fmt, [], $user);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: ' . $file['content_type']);
    header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
    header('X-Content-Type-Options: nosniff');
    echo $file['body'];
    exit;
}

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
            } elseif ($postAction === 'reprint_qr') {
                if (!Rbac::can($role, 'asset.write')) {
                    throw new \DomainException('Permission denied.');
                }
                $res = AssetService::reprintQrToken($pdo, (int)($_POST['asset_id'] ?? 0), (string)($_POST['reason'] ?? 'Label replacement'), $user);
                $flashOk = 'Rotated QR token: ' . (string)$res['qr_token'];
            } elseif ($postAction === 'create_handover') {
                if (!Rbac::can($role, 'asset.handover')) {
                    throw new \DomainException('Permission denied: asset.handover required.');
                }
                $hnd = AssetWorkflowService::createHandover($pdo, $_POST, $user);
                $flashOk = 'Recorded handover ' . (string)$hnd['handover_number'] . '.';
            } elseif ($postAction === 'upsert_member_profile') {
                if (!Rbac::can($role, 'asset.write')) {
                    throw new \DomainException('Permission denied.');
                }
                AssetService::upsertMemberAssetProfile($pdo, (string)($_POST['external_member_id'] ?? ''), $_POST, $user);
                $flashOk = 'Updated member sizing profile.';
            } elseif ($postAction === 'create_request') {
                $req = AssetWorkflowService::createRequest($pdo, $_POST, $user);
                $flashOk = 'Submitted asset request ' . (string)$req['request_number'] . '.';
            } elseif ($postAction === 'dept_head_decide') {
                if (!Rbac::can($role, 'asset.request_dept_head')) {
                    throw new \DomainException('Permission denied: asset.request_dept_head required.');
                }
                $res = AssetWorkflowService::departmentHeadDecision($pdo, (int)($_POST['request_id'] ?? 0), (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''), $user);
                $flashOk = 'Request updated to ' . (string)$res['status'] . '.';
            } elseif ($postAction === 'deputy_decide') {
                if (!Rbac::can($role, 'asset.request_deputy')) {
                    throw new \DomainException('Permission denied: asset.request_deputy required.');
                }
                $res = AssetWorkflowService::deputyDecision($pdo, (int)($_POST['request_id'] ?? 0), (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''), $user);
                $flashOk = 'Deputy decision recorded: ' . (string)$res['status'] . '.';
            } elseif ($postAction === 'request_disposal') {
                if (!Rbac::can($role, 'asset.disposal_request')) {
                    throw new \DomainException('Permission denied.');
                }
                $d = AssetLifecycleService::requestDisposal($pdo, $_POST, $user);
                $flashOk = 'Disposal requested: ' . (string)$d['disposal_number'] . '.';
            } elseif ($postAction === 'decide_disposal') {
                if (!Rbac::can($role, 'asset.disposal_approve')) {
                    throw new \DomainException('Permission denied.');
                }
                $d = AssetLifecycleService::decideDisposal($pdo, (int)($_POST['disposal_id'] ?? 0), (string)($_POST['decision'] ?? ''), $user);
                $flashOk = 'Disposal updated: ' . (string)$d['status'] . '.';
            } elseif ($postAction === 'create_event') {
                if (!Rbac::can($role, 'asset.event_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $ev = AssetLifecycleService::createEvent($pdo, $_POST, $user);
                if (!empty($_POST['default_station_name'])) {
                    AssetLifecycleService::insertStation($pdo, (int)$ev['id'], ['station_name' => (string)$_POST['default_station_name']]);
                }
                $flashOk = 'Created event ' . (string)$ev['event_code'] . '.';
            } elseif ($postAction === 'create_maintenance') {
                if (!Rbac::can($role, 'asset.maintenance_write')) {
                    throw new \DomainException('Permission denied: asset.maintenance_write required.');
                }
                $t = AssetLifecycleService::createMaintenanceTicket($pdo, $_POST, $user);
                $flashOk = 'Opened maintenance ticket ' . (string)$t['ticket_number'] . '.';
            } elseif ($postAction === 'update_maintenance') {
                if (!Rbac::can($role, 'asset.maintenance_write')) {
                    throw new \DomainException('Permission denied.');
                }
                $t = AssetLifecycleService::updateMaintenanceTicket($pdo, (int)($_POST['ticket_id'] ?? 0), $_POST, $user);
                $flashOk = 'Maintenance ticket updated to ' . (string)$t['status'] . '.';
            } elseif ($postAction === 'create_inspection') {
                if (!Rbac::can($role, 'asset.inspection_write')) {
                    throw new \DomainException('Permission denied.');
                }
                $insp = AssetLifecycleService::createInspection($pdo, $_POST, $user);
                $flashOk = 'Started inspection campaign ' . (string)$insp['campaign_code'] . '.';
            } elseif ($postAction === 'close_year') {
                if (!Rbac::can($role, 'system.year_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $res = OperationalYearService::closeYear($pdo, (int)($_POST['year_id'] ?? 0), null, $user);
                $flashOk = 'Year closed & carried forward to ' . (int)$res['next_ec_year'] . ' E.C.';
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
    "SELECT a.*, c.name_en AS category_name, l.name_en AS location_name
     FROM qa_assets a
     JOIN qa_asset_categories c ON c.id = a.category_id
     LEFT JOIN qa_asset_locations l ON l.id = a.location_id
     ORDER BY a.id DESC LIMIT 30"
)->fetchAll();
$requests = $pdo->query(
    "SELECT r.*, u.full_name AS requester_name FROM qa_asset_requests r JOIN qa_users u ON u.id = r.requester_id ORDER BY r.id DESC LIMIT 20"
)->fetchAll();
$handovers = $pdo->query(
    "SELECT h.*, a.asset_code, a.name_en AS asset_name FROM qa_asset_handovers h JOIN qa_assets a ON a.id = h.asset_id ORDER BY h.id DESC LIMIT 20"
)->fetchAll();
$overdue = AssetService::listOverdueHandovers($pdo);
$tickets = $pdo->query(
    "SELECT m.*, a.asset_code, a.name_en AS asset_name FROM qa_asset_maintenance m JOIN qa_assets a ON a.id = m.asset_id ORDER BY m.id DESC LIMIT 20"
)->fetchAll();
$events = $pdo->query("SELECT * FROM qa_events ORDER BY id DESC LIMIT 15")->fetchAll();
$inspections = $pdo->query("SELECT * FROM qa_asset_inspections ORDER BY id DESC LIMIT 15")->fetchAll();
$disposals = $pdo->query(
    "SELECT d.*, a.asset_code, a.name_en AS asset_name FROM qa_asset_disposals d JOIN qa_assets a ON a.id = d.asset_id ORDER BY d.id DESC LIMIT 15"
)->fetchAll();
$years = $pdo->query("SELECT * FROM qa_operational_years ORDER BY ec_year DESC")->fetchAll();
$members = $pdo->query(
    "SELECT m.external_member_id, m.member_code, m.name_en, m.section_name, p.clothing_size, p.shoe_size
     FROM qa_member_sync_cache m LEFT JOIN qa_member_asset_profiles p ON p.member_cache_id = m.id
     WHERE m.is_tombstoned = 0 ORDER BY m.name_en ASC LIMIT 30"
)->fetchAll();

$selectedReport = (string)($_GET['report_key'] ?? 'inventory_register');
if (!in_array($selectedReport, AssetReportService::SUPPORTED_REPORTS, true)) {
    $selectedReport = 'inventory_register';
}
$reportData = $tab === 'reports' ? AssetReportService::generate($pdo, $selectedReport) : null;

qaRenderShell('Asset Department', 'asset', $user, static function () use (
    $role, $tab, $flashOk, $flashErr,
    $fixedCount, $stockSkuCount, $lowStockCount, $activeHandovers, $openRequests, $openMaint,
    $categories, $locations, $assets, $requests, $handovers, $overdue, $tickets, $events, $inspections, $disposals, $years, $members,
    $selectedReport, $reportData
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
      <div class="qa-kpi-card"><div class="qa-kpi-label">Fixed Assets</div><div class="qa-kpi-value tabular-nums"><?= $fixedCount ?></div><div class="qa-kpi-meta">Serialized QR inventory</div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label">Stock &amp; Reusable</div><div class="qa-kpi-value tabular-nums"><?= $stockSkuCount ?></div><div class="qa-kpi-meta">Consumables &amp; uniforms</div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label">Low Stock Alerts</div><div class="qa-kpi-value tabular-nums" style="color:var(--accent-warning);"><?= $lowStockCount ?></div><div class="qa-kpi-meta">At or below minimum</div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label">Issued Handovers</div><div class="qa-kpi-value tabular-nums"><?= $activeHandovers ?></div><div class="qa-kpi-meta"><?= count($overdue) ?> overdue</div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label">Open Requests</div><div class="qa-kpi-value tabular-nums" style="color:var(--accent-primary);"><?= $openRequests ?></div><div class="qa-kpi-meta">2-step approval queue</div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label">Maintenance Queue</div><div class="qa-kpi-value tabular-nums"><?= $openMaint ?></div><div class="qa-kpi-meta">Open repair tickets</div></div>
    </section>

    <nav style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;" aria-label="Asset Department Tabs">
      <?php foreach ([
          'inventory' => 'Inventory & QR',
          'handovers' => 'Handovers & Clothing',
          'requests' => 'Requests & Disposals',
          'events' => 'Rapid Events',
          'maintenance' => 'Maintenance & Inspections',
          'reports' => 'Reports & Years',
      ] as $k => $label): ?>
        <a href="index.php?page=asset&amp;tab=<?= e($k) ?>" class="qa-btn <?= $tab === $k ? 'qa-btn-primary' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <a href="index.php?page=asset&amp;export=qr_sheet" target="_blank" class="qa-btn">Print QR Sheet</a>
    </nav>

    <?php if ($tab === 'inventory'): ?>
      <section class="qa-panel-grid">
        <?php if (Rbac::can($role, 'asset.write')): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Register Asset / Stock</h2><span class="qa-badge success">Auto QR</span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_asset">
            <div class="qa-field"><label>Name (EN)</label><input name="name_en" class="qa-input" required></div>
            <div class="qa-field"><label>Name (AM)</label><input name="name_am" class="qa-input"></div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div><label>Category</label><select name="category_id" class="qa-select" style="width:100%;"><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name_en']) ?> (<?= e($c['tracking_mode']) ?>)</option><?php endforeach; ?></select></div>
              <div><label>Location</label><select name="location_id" class="qa-select" style="width:100%;"><?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name_en']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
              <div><label>Qty</label><input name="quantity" type="number" min="0" value="1" class="qa-input"></div>
              <div><label>Min Qty</label><input name="min_quantity" type="number" min="0" value="0" class="qa-input"></div>
              <div><label>Unit Cost (ETB)</label><input name="unit_cost" type="number" step="0.01" min="0" class="qa-input"></div>
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Register Asset</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if (Rbac::can($role, 'asset.stock_move') && $assets !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Record Stock Movement</h2><span class="qa-badge info">Ledger</span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="stock_movement">
            <div class="qa-field"><label>Asset</label><select name="asset_id" class="qa-select" style="width:100%;"><?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> — <?= e($a['name_en']) ?> (qty: <?= (int)$a['quantity'] ?>)</option><?php endforeach; ?></select></div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div><label>Type</label><select name="movement_type" class="qa-select" style="width:100%;"><option value="incoming">incoming (+)</option><option value="outgoing">outgoing (-)</option><option value="transfer">transfer</option><option value="adjustment">adjustment</option></select></div>
              <div><label>Quantity Delta (+/-)</label><input name="quantity_delta" type="number" required value="1" class="qa-input"></div>
            </div>
            <div class="qa-field"><label>Reason</label><input name="reason" class="qa-input" required placeholder="Audit reason"></div>
            <button type="submit" class="qa-btn qa-btn-primary">Post Movement</button>
          </form>
        </div>
        <?php endif; ?>
      </section>

      <section class="qa-card">
        <div class="qa-card-header"><h2 class="qa-card-title">Inventory &amp; QR Registry</h2><span class="qa-badge success"><?= count($assets) ?> items</span></div>
        <?php if ($assets === []): ?>
          <p style="color:var(--text-secondary);margin:0;">No assets registered yet.</p>
        <?php else: ?>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Mode</th><th>Qty</th><th>Status</th><th>QR Action</th></tr></thead><tbody>
            <?php foreach ($assets as $a): ?>
              <tr>
                <td><code><?= e($a['asset_code']) ?></code></td>
                <td><?= e($a['name_en']) ?></td>
                <td><?= e($a['category_name']) ?></td>
                <td><span class="qa-badge"><?= e($a['tracking_mode']) ?></span></td>
                <td class="tabular-nums"><?= (int)$a['quantity'] ?></td>
                <td><span class="qa-badge <?= $a['status'] === 'in_stock' ? 'success' : ($a['status'] === 'low_stock' ? 'warning' : 'info') ?>"><?= e($a['status']) ?></span></td>
                <td>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
                    <input type="hidden" name="asset_action" value="reprint_qr">
                    <input type="hidden" name="asset_id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="qa-btn" style="padding:4px 8px;">Rotate QR</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </section>

    <?php elseif ($tab === 'handovers'): ?>
      <section class="qa-panel-grid">
        <?php if (Rbac::can($role, 'asset.handover') && $assets !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Custody Handover / Return (Drawn Signature)</h2><span class="qa-badge success">Evidence</span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_handover">
            <input type="hidden" name="verification_method" value="drawn_signature">
            <input type="hidden" id="qaSenderSigInput" name="sender_sig_b64">
            <input type="hidden" id="qaReceiverSigInput" name="receiver_sig_b64">
            <div class="qa-field"><label>Asset</label><select name="asset_id" class="qa-select" style="width:100%;"><?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> — <?= e($a['name_en']) ?> (qty: <?= (int)$a['quantity'] ?>)</option><?php endforeach; ?></select></div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div><label>Type</label><select name="handover_type" class="qa-select" style="width:100%;"><option value="issue">Issue</option><option value="return">Return</option><option value="partial_return">Partial Return</option><option value="transfer">Transfer</option></select></div>
              <div><label>Quantity</label><input name="quantity" type="number" min="1" value="1" class="qa-input"></div>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div><label>Recipient Display Name</label><input name="receiver_display_name" class="qa-input" required placeholder="Recipient name"></div>
              <div><label>Expected Return Date</label><input name="expected_return_date" type="date" class="qa-input"></div>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div>
                <label>Sender Signature <button type="button" data-sig-clear="qaSenderSigInput" class="qa-btn" style="padding:2px 6px;font-size:11px;">Clear</button></label>
                <canvas data-sig-pad="qaSenderSigInput" width="240" height="90" style="width:100%;border:1px solid var(--border-subtle);border-radius:8px;background:#fff;"></canvas>
              </div>
              <div>
                <label>Receiver Signature <button type="button" data-sig-clear="qaReceiverSigInput" class="qa-btn" style="padding:2px 6px;font-size:11px;">Clear</button></label>
                <canvas data-sig-pad="qaReceiverSigInput" width="240" height="90" style="width:100%;border:1px solid var(--border-subtle);border-radius:8px;background:#fff;"></canvas>
              </div>
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Confirm Handover</button>
          </form>
        </div>
        <?php endif; ?>

        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Member Clothing Sizing &amp; Overdue Returns</h2><span class="qa-badge warning"><?= count($overdue) ?> overdue</span></div>
          <?php if ($members !== []): ?>
            <form method="post" style="margin-bottom:14px;">
              <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
              <input type="hidden" name="asset_action" value="upsert_member_profile">
              <div class="qa-field" style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;">
                <select name="external_member_id" class="qa-select"><?php foreach ($members as $m): ?><option value="<?= e($m['external_member_id']) ?>"><?= e($m['name_en']) ?> (<?= e($m['clothing_size'] ?? 'No size') ?>)</option><?php endforeach; ?></select>
                <input name="clothing_size" class="qa-input" placeholder="Size (M/L)">
                <input name="shoe_size" class="qa-input" placeholder="Shoe">
              </div>
              <button type="submit" class="qa-btn">Save Member Size</button>
            </form>
          <?php endif; ?>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Handover</th><th>Asset</th><th>Recipient</th><th>Method</th></tr></thead><tbody>
            <?php foreach ($handovers as $h): ?>
              <tr><td><code><?= e($h['handover_number']) ?></code></td><td><?= e($h['asset_code']) ?></td><td><?= e($h['receiver_display_name']) ?> (<?= (int)$h['quantity'] ?>)</td><td><span class="qa-badge success"><?= e($h['verification_method']) ?></span></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

    <?php elseif ($tab === 'requests'): ?>
      <section class="qa-panel-grid">
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Submit Asset Request (2-Step)</h2><span class="qa-badge info">Workflow</span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_request">
            <div class="qa-field"><label>Item Name</label><input name="requested_item_name" class="qa-input" required></div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div><label>Quantity</label><input name="quantity" type="number" min="1" value="1" class="qa-input"></div>
              <div><label>Urgency</label><select name="urgency" class="qa-select" style="width:100%;"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
            </div>
            <div class="qa-field"><label>Reason</label><input name="reason" class="qa-input" required></div>
            <button type="submit" class="qa-btn qa-btn-primary">Submit Request</button>
          </form>
        </div>

        <?php if (Rbac::can($role, 'asset.disposal_request') && $assets !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Request Asset Disposal / Retirement</h2><span class="qa-badge danger">Guarded</span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="request_disposal">
            <div class="qa-field"><label>Asset</label><select name="asset_id" class="qa-select" style="width:100%;"><?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> — <?= e($a['name_en']) ?></option><?php endforeach; ?></select></div>
            <div class="qa-field"><label>Reason Type</label><select name="reason_type" class="qa-select" style="width:100%;"><option value="retired">Retired</option><option value="damaged_beyond_repair">Damaged Beyond Repair</option><option value="lost">Lost</option><option value="stolen">Stolen</option><option value="sold">Sold</option><option value="donated">Donated</option></select></div>
            <div class="qa-field"><label>Reason Detail</label><input name="reason_detail" class="qa-input" required></div>
            <button type="submit" class="qa-btn">Submit Disposal Request</button>
          </form>
        </div>
        <?php endif; ?>
      </section>

      <section class="qa-panel-grid">
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Asset Requests Queue</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Req #</th><th>Item</th><th>Requester</th><th>Status</th><th>Decision</th></tr></thead><tbody>
            <?php foreach ($requests as $r): ?>
              <tr>
                <td><code><?= e($r['request_number']) ?></code></td><td><?= e($r['requested_item_name']) ?> (<?= (int)$r['quantity'] ?>)</td><td><?= e($r['requester_name']) ?></td><td><span class="qa-badge"><?= e($r['status']) ?></span></td>
                <td>
                  <?php if ($r['status'] === 'PENDING_DEPARTMENT_HEAD' && Rbac::can($role, 'asset.request_dept_head')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="dept_head_decide"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button><button name="decision" value="reject" class="qa-btn" style="padding:4px 8px;">Reject</button></form>
                  <?php elseif ($r['status'] === 'PENDING_DEPUTY_CHAIRPERSON' && Rbac::can($role, 'asset.request_deputy')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="deputy_decide"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button><button name="decision" value="reject" class="qa-btn" style="padding:4px 8px;">Reject</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>

        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Disposal Approvals</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Disp #</th><th>Asset</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($disposals as $d): ?>
              <tr>
                <td><code><?= e($d['disposal_number']) ?></code></td><td><?= e($d['asset_code']) ?></td><td><?= e($d['reason_type']) ?></td><td><span class="qa-badge"><?= e($d['status']) ?></span></td>
                <td>
                  <?php if ($d['status'] === 'PENDING_APPROVAL' && Rbac::can($role, 'asset.disposal_approve')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="decide_disposal"><input type="hidden" name="disposal_id" value="<?= (int)$d['id'] ?>"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button><button name="decision" value="reject" class="qa-btn" style="padding:4px 8px;">Reject</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

    <?php elseif ($tab === 'events'): ?>
      <section class="qa-panel-grid">
        <?php if (Rbac::can($role, 'asset.event_manage')): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Create Rapid Distribution Event</h2></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_event">
            <div class="qa-field"><label>Event Name</label><input name="name_en" class="qa-input" required placeholder="e.g. Meskel Choir Kit Issue"></div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div><label>Event Date</label><input name="event_date" type="date" value="<?= e(gmdate('Y-m-d')) ?>" class="qa-input"></div>
              <div><label>Initial Station Name</label><input name="default_station_name" class="qa-input" placeholder="Main Hall Desk"></div>
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Create Event</button>
          </form>
        </div>
        <?php endif; ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Scheduled Multi-Station Events</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Code</th><th>Event</th><th>Date</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($events as $ev): ?>
              <tr><td><code><?= e($ev['event_code']) ?></code></td><td><?= e($ev['name_en']) ?></td><td class="tabular-nums"><?= e($ev['event_date']) ?></td><td><span class="qa-badge"><?= e($ev['status']) ?></span></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

    <?php elseif ($tab === 'maintenance'): ?>
      <section class="qa-panel-grid">
        <?php if (Rbac::can($role, 'asset.maintenance_write') && $assets !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Open Maintenance Ticket</h2></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_maintenance">
            <div class="qa-field"><label>Asset</label><select name="asset_id" class="qa-select" style="width:100%;"><?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> — <?= e($a['name_en']) ?></option><?php endforeach; ?></select></div>
            <div class="qa-field"><label>Issue Description</label><input name="issue_description" class="qa-input" required></div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div><label>Type</label><select name="maintenance_type" class="qa-select" style="width:100%;"><option value="corrective">Corrective</option><option value="preventive">Preventive</option></select></div>
              <div><label>Initial Status</label><select name="status" class="qa-select" style="width:100%;"><option value="scheduled">Scheduled</option><option value="in_progress">In Progress</option></select></div>
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Create Ticket</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if (Rbac::can($role, 'asset.inspection_write')): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Start Physical Inspection Campaign</h2></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_inspection">
            <div class="qa-field"><label>Campaign Title</label><input name="title" class="qa-input" required placeholder="e.g. Q1 Main Store Physical Audit"></div>
            <button type="submit" class="qa-btn qa-btn-primary">Start Inspection</button>
          </form>
        </div>
        <?php endif; ?>
      </section>

      <section class="qa-panel-grid">
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Maintenance Tickets</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Ticket</th><th>Asset</th><th>Status</th><th>Update</th></tr></thead><tbody>
            <?php foreach ($tickets as $t): ?>
              <tr>
                <td><code><?= e($t['ticket_number']) ?></code></td><td><?= e($t['asset_code']) ?></td><td><span class="qa-badge warning"><?= e($t['status']) ?></span></td>
                <td>
                  <?php if (in_array($t['status'], ['scheduled', 'in_progress'], true) && Rbac::can($role, 'asset.maintenance_write')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="update_maintenance"><input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>"><button name="status" value="completed" class="qa-btn" style="padding:4px 8px;">Complete</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Inspection Campaigns</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Code</th><th>Title</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($inspections as $i): ?>
              <tr><td><code><?= e($i['campaign_code']) ?></code></td><td><?= e($i['title']) ?></td><td><span class="qa-badge"><?= e($i['status']) ?></span></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

    <?php elseif ($tab === 'reports'): ?>
      <section class="qa-card" style="margin-bottom:20px;">
        <div class="qa-card-header">
          <h2 class="qa-card-title"><?= e((string)($reportData['title'] ?? 'Reports')) ?></h2>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="get" style="display:flex;gap:6px;">
              <input type="hidden" name="page" value="asset"><input type="hidden" name="tab" value="reports">
              <select name="report_key" class="qa-select">
                <?php foreach (AssetReportService::SUPPORTED_REPORTS as $rk): ?>
                  <option value="<?= e($rk) ?>" <?= $rk === $selectedReport ? 'selected' : '' ?>><?= e($rk) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="qa-btn">Run</button>
            </form>
            <a class="qa-btn qa-btn-primary" href="index.php?page=asset&amp;tab=reports&amp;export_report=<?= e($selectedReport) ?>&amp;format=csv">Export CSV</a>
            <a class="qa-btn" target="_blank" href="index.php?page=asset&amp;tab=reports&amp;export_report=<?= e($selectedReport) ?>&amp;format=html">Printable View</a>
          </div>
        </div>
        <?php if ($reportData !== null): ?>
          <div class="qa-table-wrap"><table class="qa-table">
            <thead><tr><?php foreach ($reportData['columns'] as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
              <?php foreach ($reportData['rows'] as $row): ?>
                <tr><?php foreach ($reportData['columns'] as $col): ?><td><?= e((string)($row[$col] ?? '')) ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </section>

      <section class="qa-card">
        <div class="qa-card-header"><h2 class="qa-card-title">Operational Years (Year-End Close &amp; Carry-Forward)</h2></div>
        <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>E.C.</th><th>Label</th><th>Status</th><th>Action</th></tr></thead><tbody>
          <?php foreach ($years as $yr): ?>
            <tr>
              <td class="tabular-nums"><?= (int)$yr['ec_year'] ?></td><td><?= e($yr['label_en']) ?></td><td><span class="qa-badge"><?= e($yr['status']) ?></span></td>
              <td>
                <?php if ($yr['status'] !== 'closed' && Rbac::can($role, 'system.year_manage')): ?>
                  <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="close_year"><input type="hidden" name="year_id" value="<?= (int)$yr['id'] ?>"><button type="submit" class="qa-btn" style="padding:4px 8px;">Close &amp; Carry Forward</button></form>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>
    <?php endif; ?>
    <?php
});
