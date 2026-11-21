<?php
declare(strict_types=1);

use QidusAsset\Asset\AssetAttachmentService;
use QidusAsset\Asset\AssetLifecycleService;
use QidusAsset\Asset\AssetReportService;
use QidusAsset\Asset\AssetService;
use QidusAsset\Asset\AssetWorkflowService;
use QidusAsset\Asset\OperationalYearService;
use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\Rbac;
use QidusAsset\Support\QrLabelService;

$pdo = Connection::get();
$role = (string)$user['role'];
$tab = (string)($_GET['tab'] ?? 'inventory');
if (!in_array($tab, ['inventory', 'handovers', 'requests', 'events', 'maintenance', 'reports'], true)) {
    $tab = 'inventory';
}

// Printable QR Sheet, Attachment Download, or Report Export GET handlers
if (isset($_GET['export']) && $_GET['export'] === 'qr_sheet') {
    $rows = $pdo->query("SELECT id, asset_code, name_en, name_am, qr_token FROM qa_assets WHERE status <> 'archived' ORDER BY id DESC LIMIT 30")->fetchAll();
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: text/html; charset=utf-8');
    echo QrLabelService::renderLabelSheetHtml($rows, (string)qa_config('school_name_en', 'QIDUS ASSET'));
    exit;
}

if (!empty($_GET['download_attachment']) && ctype_digit((string)$_GET['download_attachment'])) {
    if (!Rbac::can($role, 'asset.read')) {
        http_response_code(403);
        exit('Permission denied.');
    }
    $info = AssetAttachmentService::resolveForDownload($pdo, (int)$_GET['download_attachment'], $user);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($info['original_filename'])) ?: 'attachment';
    header('Content-Type: ' . $info['mime_type']);
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($info['full_path']);
    exit;
}

$reportFilters = [
    'category_id' => $_GET['category_id'] ?? '',
    'location_id' => $_GET['location_id'] ?? '',
    'operational_year_id' => $_GET['operational_year_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
];

if (!empty($_GET['export_report'])) {
    $repKey = (string)$_GET['export_report'];
    $fmt = (string)($_GET['format'] ?? 'csv');
    $file = AssetReportService::export($pdo, $repKey, $fmt, $reportFilters, $user);
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
            } elseif ($postAction === 'update_asset') {
                if (!Rbac::can($role, 'asset.write')) {
                    throw new \DomainException('Permission denied: asset.write required.');
                }
                $updated = AssetService::updateAsset($pdo, (int)($_POST['asset_id'] ?? 0), $_POST, $user);
                $flashOk = 'Updated asset ' . (string)($updated['asset_code'] ?? '') . '.';
            } elseif ($postAction === 'create_category') {
                if (!Rbac::can($role, 'asset.write')) {
                    throw new \DomainException('Permission denied.');
                }
                $cat = AssetService::createCategory($pdo, $_POST, $user);
                $flashOk = 'Created category ' . (string)$cat['code'] . '.';
            } elseif ($postAction === 'create_location') {
                if (!Rbac::can($role, 'asset.write')) {
                    throw new \DomainException('Permission denied.');
                }
                $loc = AssetService::createLocation($pdo, $_POST, $user);
                $flashOk = 'Created location ' . (string)$loc['code'] . '.';
            } elseif ($postAction === 'upload_attachment') {
                if (!Rbac::can($role, 'asset.write')) {
                    throw new \DomainException('Permission denied.');
                }
                if (empty($_FILES['attachment_file']['tmp_name'])) {
                    throw new \DomainException('Please choose a file (JPG, PNG, WEBP, or PDF) to upload.');
                }
                $up = AssetAttachmentService::storeUploadedFile(
                    $pdo,
                    (string)$_FILES['attachment_file']['tmp_name'],
                    (string)$_FILES['attachment_file']['name'],
                    [
                        'linked_entity_type' => 'asset',
                        'linked_entity_id' => (int)($_POST['asset_id'] ?? 0),
                        'doc_type' => (string)($_POST['doc_type'] ?? 'other'),
                        'title_en' => (string)($_POST['title_en'] ?? $_FILES['attachment_file']['name']),
                    ],
                    $user
                );
                $flashOk = 'Uploaded private attachment ' . (string)$up['doc_number'] . '.';
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
                $hndPayload = $_POST;
                $hndPayload['require_member_binding'] = empty($_POST['non_member_custody']);
                $hnd = AssetWorkflowService::createHandover($pdo, $hndPayload, $user);
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
            } elseif ($postAction === 'resubmit_request') {
                $req = AssetWorkflowService::resubmitRequest($pdo, (int)($_POST['request_id'] ?? 0), $_POST, $user);
                $flashOk = 'Resubmitted request #' . (int)$req['id'] . ' for department head review.';
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
            } elseif ($postAction === 'add_station_alloc') {
                if (!Rbac::can($role, 'asset.event_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $eid = (int)($_POST['event_id'] ?? 0);
                $sid = (int)($_POST['station_id'] ?? 0);
                if ($sid <= 0 && !empty($_POST['station_name'])) {
                    $st = AssetLifecycleService::insertStation($pdo, $eid, ['station_name' => (string)$_POST['station_name']]);
                    $sid = (int)$st['id'];
                }
                if ($sid > 0 && !empty($_POST['asset_id']) && !empty($_POST['allocated_qty'])) {
                    AssetLifecycleService::allocateStationStock($pdo, $eid, $sid, (int)$_POST['asset_id'], (int)$_POST['allocated_qty']);
                    $flashOk = 'Allocated station stock for event #' . $eid . '.';
                } else {
                    $flashOk = 'Updated event station.';
                }
            } elseif ($postAction === 'close_event') {
                if (!Rbac::can($role, 'asset.event_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $eid = (int)($_POST['event_id'] ?? 0);
                $pdo->prepare("UPDATE qa_events SET status = 'closed' WHERE id = :id")->execute([':id' => $eid]);
                AuditLogger::log($pdo, ['actor_user_id' => (int)$user['uid'], 'actor_role' => $role, 'action' => 'event.close', 'entity_type' => 'event', 'entity_id' => (string)$eid]);
                $flashOk = 'Event #' . $eid . ' closed.';
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
            } elseif ($postAction === 'record_inspection_scan') {
                if (!Rbac::can($role, 'asset.inspection_write')) {
                    throw new \DomainException('Permission denied.');
                }
                $res = AssetLifecycleService::recordInspectionScans($pdo, (int)($_POST['inspection_id'] ?? 0), [[
                    'asset_id' => !empty($_POST['asset_id']) ? (int)$_POST['asset_id'] : null,
                    'outcome' => (string)($_POST['outcome'] ?? 'inspected'),
                    'counted_qty' => (int)($_POST['counted_qty'] ?? 0),
                    'note' => (string)($_POST['note'] ?? ''),
                ]], $user);
                $flashOk = 'Recorded ' . (int)$res['recorded_scans'] . ' inspection scan(s).';
            } elseif ($postAction === 'create_finding') {
                if (!Rbac::can($role, 'asset.inspection_write')) {
                    throw new \DomainException('Permission denied.');
                }
                $f = AssetLifecycleService::createFindingFromInspection($pdo, (int)($_POST['inspection_id'] ?? 0), $_POST, $user);
                $flashOk = 'Logged audit finding ' . (string)$f['finding_code'] . '.';
            } elseif ($postAction === 'close_inspection') {
                if (!Rbac::can($role, 'asset.inspection_write')) {
                    throw new \DomainException('Permission denied.');
                }
                AssetLifecycleService::closeInspection($pdo, (int)($_POST['inspection_id'] ?? 0), $user);
                $flashOk = 'Closed inspection campaign.';
            } elseif ($postAction === 'close_year') {
                if (!Rbac::can($role, 'system.year_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $res = OperationalYearService::closeYear($pdo, (int)($_POST['year_id'] ?? 0), null, $user);
                $flashOk = 'Year closed & carried forward to ' . (int)$res['next_ec_year'] . ' E.C.';
            } elseif ($postAction === 'reopen_year') {
                if (!Rbac::can($role, 'system.year_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $res = OperationalYearService::reopenYear($pdo, (int)($_POST['year_id'] ?? 0), (string)($_POST['reason'] ?? ''), $user);
                $flashOk = 'Reopened operational year ' . (int)$res['ec_year'] . ' E.C.';
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

// Search, Filter & Server-Side Pagination for Inventory
$searchQ = trim((string)($_GET['q'] ?? ''));
$filterCat = !empty($_GET['filter_cat']) ? (int)$_GET['filter_cat'] : null;
$filterLoc = !empty($_GET['filter_loc']) ? (int)$_GET['filter_loc'] : null;
$filterStatus = trim((string)($_GET['filter_status'] ?? ''));
$invPage = max(1, (int)($_GET['pg'] ?? 1));
$invPerPage = 25;
$invOffset = ($invPage - 1) * $invPerPage;

$invWhere = ['1=1'];
$invParams = [];
if ($searchQ !== '') {
    $invWhere[] = '(a.asset_code LIKE :q1 OR a.name_en LIKE :q2 OR a.name_am LIKE :q3 OR a.qr_token LIKE :q4 OR a.serial_number LIKE :q5)';
    $invParams[':q1'] = '%' . $searchQ . '%';
    $invParams[':q2'] = '%' . $searchQ . '%';
    $invParams[':q3'] = '%' . $searchQ . '%';
    $invParams[':q4'] = '%' . $searchQ . '%';
    $invParams[':q5'] = '%' . $searchQ . '%';
}
if ($filterCat) {
    $invWhere[] = 'a.category_id = :fc';
    $invParams[':fc'] = $filterCat;
}
if ($filterLoc) {
    $invWhere[] = 'a.location_id = :fl';
    $invParams[':fl'] = $filterLoc;
}
if ($filterStatus !== '') {
    $invWhere[] = 'a.status = :fs';
    $invParams[':fs'] = $filterStatus;
}

$invWhereSql = implode(' AND ', $invWhere);
$invCountSql = 'SELECT COUNT(*) FROM qa_assets a WHERE ' . $invWhereSql;
$invCountStmt = $pdo->prepare($invCountSql);
$invCountStmt->execute($invParams);
$invTotal = (int)$invCountStmt->fetchColumn();
$invTotalPages = max(1, (int)ceil($invTotal / $invPerPage));

$invSql = 'SELECT a.*, c.name_en AS category_name, l.name_en AS location_name
     FROM qa_assets a
     JOIN qa_asset_categories c ON c.id = a.category_id
     LEFT JOIN qa_asset_locations l ON l.id = a.location_id
     WHERE ' . $invWhereSql . ' ORDER BY a.id DESC LIMIT ' . $invPerPage . ' OFFSET ' . $invOffset;
$invStmt = $pdo->prepare($invSql);
$invStmt->execute($invParams);
$assets = $invStmt->fetchAll();

$allAssets = $pdo->query("SELECT id, asset_code, name_en, quantity FROM qa_assets WHERE status <> 'archived' ORDER BY id DESC LIMIT 200")->fetchAll();
$attachments = $pdo->query(
    "SELECT d.id, d.doc_number, d.title_en, d.linked_entity_id, a.asset_code
     FROM qa_documents d
     LEFT JOIN qa_assets a ON a.id = d.linked_entity_id
     WHERE d.linked_entity_type = 'asset'
     ORDER BY d.id DESC LIMIT 15"
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
$stations = $pdo->query("SELECT s.*, e.event_code FROM qa_event_stations s JOIN qa_events e ON e.id = s.event_id ORDER BY s.id DESC LIMIT 30")->fetchAll();
$eventConflicts = $pdo->query(
    "SELECT t.client_tx_uuid, e.event_code, a.asset_code, t.tx_type, t.quantity, t.device_tx_at
     FROM qa_event_transactions t
     JOIN qa_events e ON e.id = t.event_id
     JOIN qa_assets a ON a.id = t.asset_id
     WHERE t.conflict_status = 'needs_review' ORDER BY t.id DESC LIMIT 15"
)->fetchAll();
$inspections = $pdo->query("SELECT * FROM qa_asset_inspections ORDER BY id DESC LIMIT 15")->fetchAll();
$findings = $pdo->query("SELECT * FROM qa_audit_findings ORDER BY id DESC LIMIT 10")->fetchAll();
$disposals = $pdo->query(
    "SELECT d.*, a.asset_code, a.name_en AS asset_name FROM qa_asset_disposals d JOIN qa_assets a ON a.id = d.asset_id ORDER BY d.id DESC LIMIT 15"
)->fetchAll();
$years = $pdo->query("SELECT * FROM qa_operational_years ORDER BY ec_year DESC")->fetchAll();
$memberQ = trim((string)($_GET['member_q'] ?? ''));
if ($memberQ !== '') {
    $mLike = '%' . $memberQ . '%';
    $mStmt = $pdo->prepare(
        "SELECT m.external_member_id, m.member_code, m.name_en, m.name_am, m.section_name, p.clothing_size, p.shoe_size
         FROM qa_member_sync_cache m LEFT JOIN qa_member_asset_profiles p ON p.member_cache_id = m.id
         WHERE m.is_tombstoned = 0 AND (m.name_en LIKE :mq1 OR m.name_am LIKE :mq2 OR m.member_code LIKE :mq3 OR m.external_member_id LIKE :mq4)
         ORDER BY m.name_en ASC LIMIT 50"
    );
    $mStmt->execute([':mq1' => $mLike, ':mq2' => $mLike, ':mq3' => $mLike, ':mq4' => $mLike]);
    $members = $mStmt->fetchAll();
} else {
    $members = $pdo->query(
        "SELECT m.external_member_id, m.member_code, m.name_en, m.name_am, m.section_name, p.clothing_size, p.shoe_size
         FROM qa_member_sync_cache m LEFT JOIN qa_member_asset_profiles p ON p.member_cache_id = m.id
         WHERE m.is_tombstoned = 0 ORDER BY m.name_en ASC LIMIT 50"
    )->fetchAll();
}
$memberTotal = (int)$pdo->query("SELECT COUNT(*) FROM qa_member_sync_cache WHERE is_tombstoned = 0")->fetchColumn();

$lookupMemberExtId = trim((string)($_GET['member_ext_id'] ?? ''));
$memberSummary = $lookupMemberExtId !== '' ? AssetService::getMemberAssetSummary($pdo, $lookupMemberExtId) : null;

$selectedReport = (string)($_GET['report_key'] ?? 'inventory_register');
if (!in_array($selectedReport, AssetReportService::SUPPORTED_REPORTS, true)) {
    $selectedReport = 'inventory_register';
}
$reportData = $tab === 'reports' ? AssetReportService::generate($pdo, $selectedReport, $reportFilters) : null;

qaRenderShell(qaT('page.asset'), 'asset', $user, static function () use (
    $user, $role, $tab, $flashOk, $flashErr,
    $fixedCount, $stockSkuCount, $lowStockCount, $activeHandovers, $openRequests, $openMaint,
    $categories, $locations, $assets, $invPage, $invTotal, $invTotalPages, $allAssets, $attachments, $searchQ, $filterCat, $filterLoc, $filterStatus,
    $requests, $handovers, $overdue, $tickets, $events, $stations, $eventConflicts, $inspections, $findings, $disposals, $years, $members, $memberQ, $memberTotal,
    $lookupMemberExtId, $memberSummary, $selectedReport, $reportData, $reportFilters
): void {
    $isAm = qaLang() === 'am';
    ?>
    <?php if ($flashOk !== ''): ?>
      <div class="qa-card" style="border-color:rgba(16,185,129,0.5);margin-bottom:16px;color:var(--accent-success);">
        <strong><?= e($isAm ? 'ተሳክቷል፦' : 'Success:') ?></strong> <?= e($flashOk) ?>
      </div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="qa-card" style="border-color:rgba(239,68,68,0.5);margin-bottom:16px;color:var(--accent-danger);">
        <strong><?= e($isAm ? 'ስህተት፦' : 'Error:') ?></strong> <?= e($flashErr) ?>
      </div>
    <?php endif; ?>

    <section class="qa-kpi-grid" aria-label="Asset Department KPIs">
      <div class="qa-kpi-card"><div class="qa-kpi-label"><?= e($isAm ? 'ቋሚ ንብረቶች' : 'Fixed Assets') ?></div><div class="qa-kpi-value tabular-nums"><?= $fixedCount ?></div><div class="qa-kpi-meta"><?= e($isAm ? 'QR የተሰጣቸው ንብረቶች' : 'Serialized QR inventory') ?></div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label"><?= e($isAm ? 'አላቂ እና ተመላሽ ዕቃዎች' : 'Stock & Reusable') ?></div><div class="qa-kpi-value tabular-nums"><?= $stockSkuCount ?></div><div class="qa-kpi-meta"><?= e($isAm ? 'አላቂ እና የአባላት ልብሶች' : 'Consumables & uniforms') ?></div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label"><?= e($isAm ? 'ዝቅተኛ ክምችት' : 'Low Stock Alerts') ?></div><div class="qa-kpi-value tabular-nums" style="color:var(--accent-warning);"><?= $lowStockCount ?></div><div class="qa-kpi-meta"><?= e($isAm ? 'ከገደብ በታች የሆኑ' : 'At or below minimum') ?></div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label"><?= e($isAm ? 'የተሰጡ ርክክቦች' : 'Issued Handovers') ?></div><div class="qa-kpi-value tabular-nums"><?= $activeHandovers ?></div><div class="qa-kpi-meta"><?= count($overdue) ?> <?= e($isAm ? 'ጊዜያቸው ያለፈ' : 'overdue') ?></div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label"><?= e($isAm ? 'ክፍት ጥያቄዎች' : 'Open Requests') ?></div><div class="qa-kpi-value tabular-nums" style="color:var(--accent-primary);"><?= $openRequests ?></div><div class="qa-kpi-meta"><?= e($isAm ? '2-ደረጃ ማጽደቂያ' : '2-step approval queue') ?></div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label"><?= e($isAm ? 'የጥገና ዝርዝር' : 'Maintenance Queue') ?></div><div class="qa-kpi-value tabular-nums"><?= $openMaint ?></div><div class="qa-kpi-meta"><?= e($isAm ? 'ክፍት የጥገና ትኬቶች' : 'Open repair tickets') ?></div></div>
    </section>

    <nav class="qa-tab-strip" aria-label="Asset Department Tabs">
      <?php foreach ([
          'inventory' => qaT('task.asset_register'),
          'handovers' => qaT('task.custody'),
          'requests' => qaT('task.requests'),
          'events' => qaT('task.events'),
          'maintenance' => qaT('task.maintenance'),
          'reports' => qaT('task.reports'),
      ] as $k => $label): ?>
        <a href="index.php?page=asset&amp;tab=<?= e($k) ?>" class="qa-btn <?= $tab === $k ? 'qa-btn-primary' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <a href="index.php?page=asset&amp;export=qr_sheet" target="_blank" class="qa-btn">🖨️ <?= e($isAm ? 'QR መለያ አትም' : 'Print QR Sheet') ?></a>
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

        <?php if (Rbac::can($role, 'asset.stock_move') && $allAssets !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Record Stock Movement</h2><span class="qa-badge info">Ledger</span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="stock_movement">
            <div class="qa-field"><label>Asset</label><select name="asset_id" class="qa-select" style="width:100%;"><?php foreach ($allAssets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> — <?= e($a['name_en']) ?> (qty: <?= (int)$a['quantity'] ?>)</option><?php endforeach; ?></select></div>
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

      <?php if (Rbac::can($role, 'asset.write')): ?>
      <section class="qa-panel-grid" style="margin-top:16px;">
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Create Category / Location</h2><span class="qa-badge">Setup</span></div>
          <form method="post" style="margin-bottom:14px;">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_category">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:8px;">
              <input name="code" class="qa-input" placeholder="Code (CHOIR)" required>
              <input name="name_en" class="qa-input" placeholder="Category Name (EN)" required>
              <select name="tracking_mode" class="qa-select"><option value="fixed">Fixed</option><option value="reusable_member">Reusable</option><option value="consumable">Consumable</option></select>
            </div>
            <button type="submit" class="qa-btn">Add Category</button>
          </form>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_location">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 2fr;gap:8px;">
              <input name="code" class="qa-input" placeholder="Loc Code (ROOM_2)" required>
              <input name="name_en" class="qa-input" placeholder="Location Name (EN)" required>
            </div>
            <button type="submit" class="qa-btn">Add Location</button>
          </form>
        </div>

        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Private Vault Attachment</h2><span class="qa-badge info">SHA-256</span></div>
          <?php if ($allAssets !== []): ?>
          <form method="post" enctype="multipart/form-data" style="margin-bottom:12px;">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="upload_attachment">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <select name="asset_id" class="qa-select"><?php foreach ($allAssets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> — <?= e($a['name_en']) ?></option><?php endforeach; ?></select>
              <select name="doc_type" class="qa-select"><option value="receipt">Receipt</option><option value="invoice">Invoice</option><option value="handover">Handover</option><option value="other">Photo / Other</option></select>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <input name="title_en" class="qa-input" placeholder="Document title">
              <input type="file" name="attachment_file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="qa-input" required>
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Upload to Private Vault</button>
          </form>
          <?php endif; ?>
          <?php if ($attachments !== []): ?>
            <div style="font-size:12px;color:var(--text-secondary);">Recent Files:
              <?php foreach ($attachments as $att): ?>
                <a href="index.php?page=asset&amp;download_attachment=<?= (int)$att['id'] ?>" class="qa-badge info" style="margin:2px;display:inline-block;text-decoration:none;"><?= e($att['doc_number']) ?> (<?= e($att['asset_code'] ?? 'asset') ?>)</a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <section class="qa-card" style="margin-top:16px;">
        <div class="qa-card-header" style="flex-wrap:wrap;gap:10px;">
          <h2 class="qa-card-title">Inventory &amp; QR Registry</h2>
          <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;">
            <input type="hidden" name="page" value="asset"><input type="hidden" name="tab" value="inventory">
            <input name="q" value="<?= e($searchQ) ?>" class="qa-input" placeholder="Search code / name / QR" style="width:180px;">
            <select name="filter_cat" class="qa-select"><option value="">All Categories</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name_en']) ?></option><?php endforeach; ?></select>
            <select name="filter_loc" class="qa-select"><option value="">All Locations</option><?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $filterLoc === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name_en']) ?></option><?php endforeach; ?></select>
            <select name="filter_status" class="qa-select"><option value="">All Status</option><?php foreach (['in_stock','assigned','low_stock','out_of_stock','maintenance','retired'] as $st): ?><option value="<?= e($st) ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select>
            <button type="submit" class="qa-btn">Filter</button>
          </form>
        </div>
        <?php if ($assets === []): ?>
          <p style="color:var(--text-secondary);margin:0;">No assets match the current filter.</p>
        <?php else: ?>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Location</th><th>Qty</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($assets as $a): ?>
              <tr>
                <td><code><?= e($a['asset_code']) ?></code></td>
                <td><?= e($a['name_en']) ?></td>
                <td><?= e($a['category_name']) ?></td>
                <td><?= e($a['location_name'] ?? '—') ?></td>
                <td class="tabular-nums"><?= (int)$a['quantity'] ?></td>
                <td><?= qaStatusBadge((string)$a['status']) ?></td>
                <td>
                  <?php if (Rbac::can($role, 'asset.write')): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
                    <input type="hidden" name="asset_action" value="reprint_qr">
                    <input type="hidden" name="asset_id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="qa-btn" style="padding:4px 8px;">Rotate QR</button>
                  </form>
                  <details style="display:inline-block;margin-left:4px;">
                    <summary class="qa-btn" style="padding:4px 8px;display:inline-block;cursor:pointer;">Edit</summary>
                    <form method="post" style="margin-top:6px;padding:8px;border:1px solid var(--border-subtle);border-radius:8px;background:var(--bg-surface);display:grid;gap:6px;min-width:220px;">
                      <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
                      <input type="hidden" name="asset_action" value="update_asset">
                      <input type="hidden" name="asset_id" value="<?= (int)$a['id'] ?>">
                      <input name="name_en" value="<?= e($a['name_en']) ?>" class="qa-input" required>
                      <select name="condition_status" class="qa-select"><?php foreach (['new','good','fair','poor','damaged'] as $cs): ?><option value="<?= e($cs) ?>" <?= $a['condition_status'] === $cs ? 'selected' : '' ?>><?= e($cs) ?></option><?php endforeach; ?></select>
                      <input name="min_quantity" type="number" min="0" value="<?= (int)$a['min_quantity'] ?>" class="qa-input" title="Min Quantity">
                      <button type="submit" class="qa-btn qa-btn-primary" style="padding:4px 8px;">Save</button>
                    </form>
                  </details>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php if ($invTotalPages > 1 || $invTotal > 0): ?>
            <div class="qa-pagination">
              <span><?= e(qaT('common.showing')) ?> <?= count($assets) ?> / <?= $invTotal ?> (<?= $invPage ?> / <?= $invTotalPages ?>)</span>
              <div style="display:flex;gap:6px;">
                <?php if ($invPage > 1): ?>
                  <a class="qa-btn" href="index.php?page=asset&amp;tab=inventory&amp;pg=<?= $invPage - 1 ?>&amp;q=<?= urlencode($searchQ) ?>&amp;filter_cat=<?= (int)$filterCat ?>&amp;filter_loc=<?= (int)$filterLoc ?>&amp;filter_status=<?= urlencode($filterStatus) ?>"><?= e(qaT('common.prev')) ?></a>
                <?php endif; ?>
                <?php if ($invPage < $invTotalPages): ?>
                  <a class="qa-btn" href="index.php?page=asset&amp;tab=inventory&amp;pg=<?= $invPage + 1 ?>&amp;q=<?= urlencode($searchQ) ?>&amp;filter_cat=<?= (int)$filterCat ?>&amp;filter_loc=<?= (int)$filterLoc ?>&amp;filter_status=<?= urlencode($filterStatus) ?>"><?= e(qaT('common.next')) ?></a>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>

    <?php elseif ($tab === 'handovers'): ?>
      <section class="qa-panel-grid">
        <?php if (Rbac::can($role, 'asset.handover') && $allAssets !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Custody Handover / Return (Drawn Signature)</h2><span class="qa-badge success">Evidence</span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_handover">
            <input type="hidden" name="verification_method" value="drawn_signature">
            <input type="hidden" id="qaSenderSigInput" name="sender_sig_b64">
            <input type="hidden" id="qaReceiverSigInput" name="receiver_sig_b64">
            <div class="qa-field"><label>Asset</label><select name="asset_id" class="qa-select" style="width:100%;"><?php foreach ($allAssets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> — <?= e($a['name_en']) ?> (qty: <?= (int)$a['quantity'] ?>)</option><?php endforeach; ?></select></div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div><label>Type</label><select name="handover_type" class="qa-select" style="width:100%;"><option value="issue">Issue</option><option value="return">Return</option><option value="partial_return">Partial Return</option><option value="transfer">Transfer</option></select></div>
              <div><label>Quantity</label><input name="quantity" type="number" min="1" value="1" class="qa-input"></div>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div><label>Recipient Display Name</label><input name="receiver_display_name" class="qa-input" required placeholder="Recipient name"></div>
              <div><label>Expected Return Date</label><input name="expected_return_date" type="date" class="qa-input"></div>
            </div>
            <?php if ($members !== []): ?>
            <div class="qa-field">
              <label><?= e($isAm ? 'የአባል ማረጋገጫ (SSMS — ምንም ነባሪ ምርጫ የለም)' : 'Verified Member (SSMS — Never Preselected)') ?></label>
              <select name="receiver_member_external_id" class="qa-select" style="width:100%;">
                <option value=""><?= e($isAm ? '— የSSMS አባል ይምረጡ (ወይም ከታች አባል ያልሆነ ምልክት ያድርጉ) —' : '— Select verified SSMS member (or check non-member below) —') ?></option>
                <?php foreach ($members as $m): ?>
                  <option value="<?= e($m['external_member_id']) ?>"><?= e($m['name_en']) ?> (<?= e($m['member_code'] ?? $m['external_member_id']) ?>)</option>
                <?php endforeach; ?>
              </select>
              <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-weight:500;">
                <input type="checkbox" name="non_member_custody" value="1">
                <span><?= e($isAm ? 'አባል ያልሆነ / የሰራተኛ ወይም የውጭ ርክክብ' : 'Explicit non-member / staff / external custody') ?></span>
              </label>
            </div>
            <?php endif; ?>
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
          <div class="qa-card-header"><h2 class="qa-card-title"><?= e($isAm ? 'የአባላት ልብስ መጠን እና በእጅ ያሉ ንብረቶች' : 'Member Clothing Sizing & Holdings') ?></h2><span class="qa-badge info"><?= $memberTotal ?> <?= e($isAm ? 'አባላት' : 'synced') ?></span></div>
          <form method="get" style="display:flex;gap:8px;margin-bottom:10px;">
            <input type="hidden" name="page" value="asset"><input type="hidden" name="tab" value="handovers">
            <input name="member_q" value="<?= e($memberQ) ?>" class="qa-input" style="flex:1;" placeholder="<?= e($isAm ? 'አባል በስም ወይም በመታወቂያ ፈልግ...' : 'Filter SSMS members by name or ID...') ?>">
            <button type="submit" class="qa-btn"><?= e(qaT('common.search')) ?></button>
          </form>
          <?php if ($members !== []): ?>
            <form method="post" style="margin-bottom:10px;">
              <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
              <input type="hidden" name="asset_action" value="upsert_member_profile">
              <div class="qa-field" style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;">
                <select name="external_member_id" class="qa-select" required><option value=""><?= e($isAm ? '— አባል ይምረጡ —' : '— Select member —') ?></option><?php foreach ($members as $m): ?><option value="<?= e($m['external_member_id']) ?>"><?= e($m['name_en']) ?> (<?= e($m['clothing_size'] ?? 'No size') ?>)</option><?php endforeach; ?></select>
                <input name="clothing_size" class="qa-input" placeholder="Size (M/L)">
                <input name="shoe_size" class="qa-input" placeholder="Shoe">
              </div>
              <button type="submit" class="qa-btn">Save Member Size</button>
            </form>
            <form method="get" style="display:flex;gap:8px;margin-bottom:12px;">
              <input type="hidden" name="page" value="asset"><input type="hidden" name="tab" value="handovers">
              <select name="member_ext_id" class="qa-select" style="flex:1;"><option value="">Inspect member holdings...</option><?php foreach ($members as $m): ?><option value="<?= e($m['external_member_id']) ?>" <?= $lookupMemberExtId === $m['external_member_id'] ? 'selected' : '' ?>><?= e($m['name_en']) ?></option><?php endforeach; ?></select>
              <button type="submit" class="qa-btn">Lookup</button>
            </form>
            <?php if ($memberSummary !== null): ?>
              <div style="padding:10px;border:1px solid var(--border-subtle);border-radius:8px;margin-bottom:12px;font-size:13px;">
                <strong><?= e($memberSummary['member']['name_en'] ?? $lookupMemberExtId) ?></strong> — Size: <?= e($memberSummary['profile']['clothing_size'] ?? 'N/A') ?> · Handovers: <?= count($memberSummary['handovers']) ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Handover</th><th>Asset</th><th>Recipient</th><th>Method</th></tr></thead><tbody>
            <?php foreach ($handovers as $h): ?>
              <tr><td><code><?= e($h['handover_number']) ?></code></td><td><?= e($h['asset_code']) ?></td><td><?= e($h['receiver_display_name']) ?> (<?= (int)$h['quantity'] ?>)</td><td><span class="qa-badge success"><?= e($h['verification_method']) ?></span></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

      <?php if ($overdue !== []): ?>
      <section class="qa-card" style="margin-top:16px;">
        <div class="qa-card-header"><h2 class="qa-card-title">Overdue Custody Returns</h2><span class="qa-badge danger"><?= count($overdue) ?> overdue</span></div>
        <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Handover</th><th>Asset</th><th>Recipient</th><th>Qty</th><th>Due Date</th></tr></thead><tbody>
          <?php foreach ($overdue as $ov): ?>
            <tr><td><code><?= e($ov['handover_number']) ?></code></td><td><?= e($ov['asset_code']) ?> — <?= e($ov['name_en']) ?></td><td><?= e($ov['receiver_display_name']) ?></td><td class="tabular-nums"><?= (int)$ov['quantity'] ?></td><td class="tabular-nums" style="color:var(--accent-danger);"><?= e($ov['expected_return_date']) ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>
      <?php endif; ?>

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

        <?php if (Rbac::can($role, 'asset.disposal_request') && $allAssets !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Request Asset Disposal / Retirement</h2><span class="qa-badge danger">Guarded</span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="request_disposal">
            <div class="qa-field"><label>Asset</label><select name="asset_id" class="qa-select" style="width:100%;"><?php foreach ($allAssets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> — <?= e($a['name_en']) ?></option><?php endforeach; ?></select></div>
            <div class="qa-field"><label>Reason Type</label><select name="reason_type" class="qa-select" style="width:100%;"><option value="retired">Retired</option><option value="damaged_beyond_repair">Damaged Beyond Repair</option><option value="lost">Lost</option><option value="stolen">Stolen</option><option value="sold">Sold</option><option value="donated">Donated</option></select></div>
            <div class="qa-field"><label>Reason Detail</label><input name="reason_detail" class="qa-input" required></div>
            <button type="submit" class="qa-btn">Submit Disposal Request</button>
          </form>
        </div>
        <?php endif; ?>
      </section>

      <section class="qa-panel-grid" style="margin-top:16px;">
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Asset Requests Queue</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Req #</th><th>Item</th><th>Requester</th><th>Status</th><th>Decision</th></tr></thead><tbody>
            <?php foreach ($requests as $r): ?>
              <tr>
                <td><code><?= e($r['request_number']) ?></code></td><td><?= e($r['requested_item_name']) ?> (<?= (int)$r['quantity'] ?>)</td><td><?= e($r['requester_name']) ?></td><td><?= qaStatusBadge((string)$r['status']) ?></td>
                <td>
                  <?php if ($r['status'] === 'PENDING_DEPARTMENT_HEAD' && Rbac::can($role, 'asset.request_dept_head')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;flex-wrap:wrap;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="dept_head_decide"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input name="note" class="qa-input" placeholder="Note" style="width:90px;padding:3px 6px;"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button><button name="decision" value="return" class="qa-btn" style="padding:4px 8px;">Return</button><button name="decision" value="reject" class="qa-btn" style="padding:4px 8px;">Reject</button></form>
                  <?php elseif ($r['status'] === 'PENDING_DEPUTY_CHAIRPERSON' && Rbac::can($role, 'asset.request_deputy')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;flex-wrap:wrap;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="deputy_decide"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input name="note" class="qa-input" placeholder="Note" style="width:90px;padding:3px 6px;"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button><button name="decision" value="return" class="qa-btn" style="padding:4px 8px;">Return</button><button name="decision" value="reject" class="qa-btn" style="padding:4px 8px;">Reject</button></form>
                  <?php elseif ($r['status'] === 'RETURNED_FOR_CORRECTION' && ((int)$r['requester_id'] === (int)$user['uid'] || $role === 'system_admin')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="resubmit_request"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input name="quantity" type="number" min="1" value="<?= (int)$r['quantity'] ?>" class="qa-input" style="width:65px;padding:3px 6px;"><button type="submit" class="qa-btn qa-btn-primary" style="padding:4px 8px;">Resubmit</button></form>
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
                <td><code><?= e($d['disposal_number']) ?></code></td><td><?= e($d['asset_code']) ?></td><td><?= e($d['reason_type']) ?></td><td><?= qaStatusBadge((string)$d['status']) ?></td>
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
          <form method="post" style="margin-bottom:14px;">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_event">
            <div class="qa-field"><label>Event Name</label><input name="name_en" class="qa-input" required placeholder="e.g. Meskel Choir Kit Issue"></div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div><label>Event Date</label><input name="event_date" type="date" value="<?= e(gmdate('Y-m-d')) ?>" class="qa-input"></div>
              <div><label>Initial Station Name</label><input name="default_station_name" class="qa-input" placeholder="Main Hall Desk"></div>
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Create Event</button>
          </form>

          <?php if ($events !== [] && $allAssets !== []): ?>
          <hr style="border:none;border-top:1px solid var(--border-subtle);margin:14px 0;">
          <h3 style="margin:0 0 8px;font-size:14px;">Allocate Station Stock</h3>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="add_station_alloc">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <select name="event_id" class="qa-select"><?php foreach ($events as $ev): ?><option value="<?= (int)$ev['id'] ?>"><?= e($ev['event_code']) ?> — <?= e($ev['name_en']) ?></option><?php endforeach; ?></select>
              <select name="station_id" class="qa-select"><option value="">New station below...</option><?php foreach ($stations as $st): ?><option value="<?= (int)$st['id'] ?>"><?= e($st['event_code']) ?> / <?= e($st['station_name']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
              <input name="station_name" class="qa-input" placeholder="New station name">
              <select name="asset_id" class="qa-select"><?php foreach ($allAssets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> (<?= (int)$a['quantity'] ?>)</option><?php endforeach; ?></select>
              <input name="allocated_qty" type="number" min="1" value="1" class="qa-input" placeholder="Qty">
            </div>
            <button type="submit" class="qa-btn">Save Station &amp; Allocation</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Scheduled Multi-Station Events</h2><span class="qa-badge warning"><?= count($eventConflicts) ?> conflicts</span></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Code</th><th>Event</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($events as $ev): ?>
              <tr>
                <td><code><?= e($ev['event_code']) ?></code></td><td><?= e($ev['name_en']) ?></td><td class="tabular-nums"><?= e($ev['event_date']) ?></td><td><span class="qa-badge"><?= e($ev['status']) ?></span></td>
                <td>
                  <?php if ($ev['status'] !== 'closed' && Rbac::can($role, 'asset.event_manage')): ?>
                    <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="close_event"><input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>"><button type="submit" class="qa-btn" style="padding:4px 8px;">Close Event</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

    <?php elseif ($tab === 'maintenance'): ?>
      <section class="qa-panel-grid">
        <?php if (Rbac::can($role, 'asset.maintenance_write') && $allAssets !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Open Maintenance Ticket</h2></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_maintenance">
            <div class="qa-field"><label>Asset</label><select name="asset_id" class="qa-select" style="width:100%;"><?php foreach ($allAssets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> — <?= e($a['name_en']) ?></option><?php endforeach; ?></select></div>
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
          <div class="qa-card-header"><h2 class="qa-card-title">Physical Inspection &amp; Audit Findings</h2></div>
          <form method="post" style="margin-bottom:12px;">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="create_inspection">
            <div class="qa-field"><label>New Campaign Title</label><input name="title" class="qa-input" required placeholder="e.g. Q1 Main Store Physical Audit"></div>
            <button type="submit" class="qa-btn qa-btn-primary">Start Inspection</button>
          </form>
          <?php if ($inspections !== [] && $allAssets !== []): ?>
          <form method="post" style="margin-bottom:12px;">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="asset_action" value="record_inspection_scan">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <select name="inspection_id" class="qa-select"><?php foreach ($inspections as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['campaign_code']) ?></option><?php endforeach; ?></select>
              <select name="asset_id" class="qa-select"><?php foreach ($allAssets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:8px;">
              <select name="outcome" class="qa-select"><option value="inspected">Inspected</option><option value="not_found">Not Found</option><option value="relocated">Relocated</option><option value="damaged">Damaged</option></select>
              <input name="counted_qty" type="number" min="0" value="1" class="qa-input" placeholder="Counted">
              <input name="note" class="qa-input" placeholder="Scan note">
            </div>
            <button type="submit" class="qa-btn">Log Scan Result</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </section>

      <section class="qa-panel-grid" style="margin-top:16px;">
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Maintenance Tickets</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Ticket</th><th>Asset</th><th>Status</th><th>Update</th></tr></thead><tbody>
            <?php foreach ($tickets as $t): ?>
              <tr>
                <td><code><?= e($t['ticket_number']) ?></code></td><td><?= e($t['asset_code']) ?></td><td><span class="qa-badge warning"><?= e($t['status']) ?></span></td>
                <td>
                  <?php if (in_array($t['status'], ['scheduled', 'in_progress'], true) && Rbac::can($role, 'asset.maintenance_write')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="update_maintenance"><input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>"><?php if ($t['status'] === 'scheduled'): ?><button name="status" value="in_progress" class="qa-btn" style="padding:4px 8px;">Start</button><?php endif; ?><button name="status" value="completed" class="qa-btn" style="padding:4px 8px;">Complete</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Inspection Campaigns (<?= count($findings) ?> findings)</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Code</th><th>Title</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($inspections as $i): ?>
              <tr>
                <td><code><?= e($i['campaign_code']) ?></code></td><td><?= e($i['title']) ?></td><td><span class="qa-badge"><?= e($i['status']) ?></span></td>
                <td>
                  <?php if ($i['status'] !== 'closed' && Rbac::can($role, 'asset.inspection_write')): ?>
                    <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="close_inspection"><input type="hidden" name="inspection_id" value="<?= (int)$i['id'] ?>"><button type="submit" class="qa-btn" style="padding:4px 8px;">Close</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

    <?php elseif ($tab === 'reports'): ?>
      <section class="qa-card" style="margin-bottom:20px;">
        <div class="qa-card-header" style="flex-wrap:wrap;gap:10px;">
          <h2 class="qa-card-title"><?= e((string)($reportData['title'] ?? 'Reports')) ?></h2>
          <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;">
            <input type="hidden" name="page" value="asset"><input type="hidden" name="tab" value="reports">
            <select name="report_key" class="qa-select">
              <?php foreach (AssetReportService::SUPPORTED_REPORTS as $rk): ?>
                <option value="<?= e($rk) ?>" <?= $rk === $selectedReport ? 'selected' : '' ?>><?= e($rk) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="category_id" class="qa-select"><option value="">All Categories</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$reportFilters['category_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name_en']) ?></option><?php endforeach; ?></select>
            <select name="location_id" class="qa-select"><option value="">All Locations</option><?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (string)$reportFilters['location_id'] === (string)$l['id'] ? 'selected' : '' ?>><?= e($l['name_en']) ?></option><?php endforeach; ?></select>
            <button type="submit" class="qa-btn">Run</button>
          </form>
          <div style="display:flex;gap:6px;">
            <?php $qs = http_build_query(array_filter(array_merge(['page' => 'asset', 'tab' => 'reports', 'export_report' => $selectedReport], $reportFilters))); ?>
            <a class="qa-btn qa-btn-primary" href="index.php?<?= e($qs) ?>&amp;format=csv">CSV</a>
            <a class="qa-btn" href="index.php?<?= e($qs) ?>&amp;format=xlsx">XLSX</a>
            <a class="qa-btn" target="_blank" href="index.php?<?= e($qs) ?>&amp;format=html">Printable</a>
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
                <?php elseif ($yr['status'] === 'closed' && Rbac::can($role, 'system.year_manage')): ?>
                  <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="asset_action" value="reopen_year"><input type="hidden" name="year_id" value="<?= (int)$yr['id'] ?>"><input name="reason" class="qa-input" required placeholder="Reopen reason" style="width:140px;padding:3px 6px;"><button type="submit" class="qa-btn" style="padding:4px 8px;">Reopen</button></form>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>
    <?php endif; ?>
    <?php
});
