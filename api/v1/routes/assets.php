<?php
declare(strict_types=1);

use QidusAsset\Asset\AssetAttachmentService;
use QidusAsset\Asset\AssetLifecycleService;
use QidusAsset\Asset\AssetReportService;
use QidusAsset\Asset\AssetService;
use QidusAsset\Asset\AssetWorkflowService;
use QidusAsset\Database\Connection;
use QidusAsset\Support\IdempotencyService;
use QidusAsset\Support\QrLabelService;
use QidusAsset\Support\Response;

/**
 * Department 1 (Asset Dept) REST v1 Routes — Full PRD v1.0 Coverage.
 *
 * @param list<string> $segments
 */
function qaHandleAssetRoute(string $method, array $segments): never
{
    $root = $segments[0] ?? '';
    $sub = array_slice($segments, 1);

    if ($root === 'asset-categories') {
        if ($method === 'GET') {
            qaRequireAuth('asset.read');
            $rows = Connection::get()->query('SELECT * FROM qa_asset_categories WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
            Response::ok(['categories' => $rows]);
        }
        if ($method === 'POST') {
            $auth = qaRequireAuth('asset.write');
            $body = Response::jsonBody();
            Response::ok(['category' => AssetService::createCategory(Connection::get(), $body, $auth)], 201);
        }
    }

    if ($root === 'asset-locations') {
        if ($method === 'GET') {
            qaRequireAuth('asset.read');
            $rows = Connection::get()->query('SELECT * FROM qa_asset_locations WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
            Response::ok(['locations' => $rows]);
        }
        if ($method === 'POST') {
            $auth = qaRequireAuth('asset.write');
            $body = Response::jsonBody();
            Response::ok(['location' => AssetService::createLocation(Connection::get(), $body, $auth)], 201);
        }
    }

    if ($root === 'assets') {
        qaRouteAssets($method, $sub);
    }
    if ($root === 'attachments') {
        qaRouteAttachments($method, $sub);
    }
    if ($root === 'asset-requests') {
        qaRouteAssetRequests($method, $sub);
    }
    if ($root === 'handovers') {
        qaRouteHandovers($method, $sub);
    }
    if ($root === 'event-templates') {
        qaRouteEventTemplates($method);
    }
    if ($root === 'events') {
        qaRouteEvents($method, $sub);
    }
    if ($root === 'maintenance') {
        qaRouteMaintenance($method, $sub);
    }
    if ($root === 'inspections') {
        qaRouteInspections($method, $sub);
    }
    if ($root === 'disposals') {
        qaRouteDisposals($method, $sub);
    }
    if ($root === 'reports') {
        qaRouteAssetReports($method, $sub);
    }

    Response::err('Asset route not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteAssets(string $method, array $sub): never
{
    $pdo = Connection::get();

    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('asset.read');
        [$page, $limit, $offset] = Response::paginationParams();
        $where = ['1=1'];
        $params = [];

        if (!empty($_GET['tracking_mode'])) {
            $where[] = 'a.tracking_mode = :tm';
            $params[':tm'] = (string)$_GET['tracking_mode'];
        }
        if (!empty($_GET['status'])) {
            $where[] = 'a.status = :st';
            $params[':st'] = (string)$_GET['status'];
        }
        if (!empty($_GET['category_id'])) {
            $where[] = 'a.category_id = :cid';
            $params[':cid'] = (int)$_GET['category_id'];
        }
        if (!empty($_GET['location_id'])) {
            $where[] = 'a.location_id = :lid';
            $params[':lid'] = (int)$_GET['location_id'];
        }
        if (!empty($_GET['q'])) {
            $like = '%' . trim((string)$_GET['q']) . '%';
            $where[] = '(a.asset_code LIKE :q1 OR a.name_en LIKE :q2 OR a.name_am LIKE :q3 OR a.serial_number LIKE :q4)';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
        }
        $whereSql = implode(' AND ', $where);

        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM qa_assets a WHERE {$whereSql}");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT a.*, c.code AS category_code, c.name_en AS category_name_en, l.name_en AS location_name_en
             FROM qa_assets a
             JOIN qa_asset_categories c ON c.id = a.category_id
             LEFT JOIN qa_asset_locations l ON l.id = a.location_id
             WHERE {$whereSql}
             ORDER BY a.id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        Response::paginated($stmt->fetchAll(), $total, $page, $limit);
    }

    if ($method === 'GET' && ($sub[0] ?? '') === 'resolve' && !empty($sub[1])) {
        qaRequireAuth('asset.read');
        $asset = AssetService::resolveByQrToken($pdo, urldecode($sub[1]));
        if (!$asset) {
            Response::err('QR token does not match any active asset.', 404, 'QR_NOT_FOUND');
        }
        Response::ok(['asset' => $asset]);
    }

    if ($method === 'GET' && count($sub) === 1 && ctype_digit($sub[0])) {
        qaRequireAuth('asset.read');
        $asset = AssetService::getById($pdo, (int)$sub[0]);
        if (!$asset) {
            Response::err('Asset not found.', 404, 'ASSET_NOT_FOUND');
        }
        Response::ok(['asset' => $asset]);
    }

    if ($method === 'PATCH' && count($sub) === 1 && ctype_digit($sub[0])) {
        $auth = qaRequireAuth('asset.write');
        $body = Response::jsonBody();
        Response::ok(['asset' => AssetService::updateAsset($pdo, (int)$sub[0], $body, $auth)]);
    }

    if ($method === 'GET' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'qr-label') {
        qaRequireAuth('asset.read');
        $asset = AssetService::getById($pdo, (int)$sub[0]);
        if (!$asset) {
            Response::err('Asset not found.', 404, 'ASSET_NOT_FOUND');
        }
        Response::ok([
            'asset_id' => (int)$asset['id'],
            'asset_code' => $asset['asset_code'],
            'qr_token' => $asset['qr_token'],
            'svg' => QrLabelService::renderQrSvg((string)$asset['qr_token']),
        ]);
    }

    if ($method === 'POST' && ($sub[0] ?? '') === 'qr-sheet') {
        qaRequireAuth('asset.read');
        $body = Response::jsonBody();
        $ids = array_values(array_filter(array_map('intval', (array)($body['asset_ids'] ?? [])), static fn(int $i): bool => $i > 0));
        if ($ids === []) {
            $rows = $pdo->query("SELECT id, asset_code, name_en, name_am, qr_token FROM qa_assets WHERE status <> 'archived' ORDER BY id DESC LIMIT 30")->fetchAll();
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, asset_code, name_en, name_am, qr_token FROM qa_assets WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll();
        }
        $html = QrLabelService::renderLabelSheetHtml($rows, (string)qa_config('school_name_en', 'QIDUS ASSET'));
        Response::ok(['count' => count($rows), 'html' => $html]);
    }

    if ($method === 'GET' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'history') {
        qaRequireAuth('asset.read');
        $stmt = $pdo->prepare(
            'SELECT m.*, u.username AS performed_by_username
             FROM qa_asset_movements m
             LEFT JOIN qa_users u ON u.id = m.performed_by
             WHERE m.asset_id = :aid
             ORDER BY m.id DESC
             LIMIT 200'
        );
        $stmt->execute([':aid' => (int)$sub[0]]);
        Response::ok(['movements' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('asset.write');
        $body = Response::jsonBody();
        $idemKey = IdempotencyService::extractKey();
        if ($idemKey !== null) {
            $check = IdempotencyService::check($pdo, $auth['uid'], $idemKey, 'POST /assets', $body);
            if ($check['conflict']) {
                Response::err('Idempotency key reused with different payload.', 409, 'IDEMPOTENCY_CONFLICT');
            }
            if ($check['hit']) {
                Response::sendJson($check['response'], (int)$check['status_code']);
            }
        }

        $asset = AssetService::createAsset($pdo, $body, $auth);
        $payload = ['status' => 'success', 'data' => ['asset' => $asset]];
        if ($idemKey !== null) {
            IdempotencyService::record($pdo, $auth['uid'], $idemKey, 'POST /assets', $body, 201, $payload);
        }
        Response::sendJson($payload, 201);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'movements') {
        $auth = qaRequireAuth('asset.stock_move');
        $body = Response::jsonBody();
        $res = AssetService::recordMovement($pdo, (int)$sub[0], $body, $auth);
        Response::ok($res, 201);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'qr-reprint') {
        $auth = qaRequireAuth('asset.write');
        $body = Response::jsonBody();
        $res = AssetService::reprintQrToken($pdo, (int)$sub[0], (string)($body['reason'] ?? ''), $auth);
        Response::ok($res);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'attachments') {
        $auth = qaRequireAuth('asset.write');
        $assetId = (int)$sub[0];
        if (!empty($_FILES['file']['tmp_name'])) {
            $res = AssetAttachmentService::storeUploadedFile(
                $pdo,
                (string)$_FILES['file']['tmp_name'],
                (string)$_FILES['file']['name'],
                ['linked_entity_type' => 'asset', 'linked_entity_id' => $assetId, 'title_en' => $_POST['title_en'] ?? $_FILES['file']['name']],
                $auth
            );
            Response::ok($res, 201);
        }
        $body = Response::jsonBody(15728640);
        if (empty($body['content_base64']) || empty($body['filename'])) {
            Response::err('Multipart file or content_base64 + filename required.', 422, 'VALIDATION_ERROR');
        }
        $res = AssetAttachmentService::storeBase64(
            $pdo,
            (string)$body['content_base64'],
            (string)$body['filename'],
            ['linked_entity_type' => 'asset', 'linked_entity_id' => $assetId, 'title_en' => $body['title_en'] ?? $body['filename']],
            $auth
        );
        Response::ok($res, 201);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'archive') {
        $auth = qaRequireAuth('asset.write');
        $assetId = (int)$sub[0];
        $pdo->prepare("UPDATE qa_assets SET status = 'archived' WHERE id = :id")->execute([':id' => $assetId]);
        \QidusAsset\Security\AuditLogger::log($pdo, [
            'actor_user_id' => (int)$auth['uid'],
            'actor_role' => (string)$auth['role'],
            'action' => 'asset.archive',
            'entity_type' => 'asset',
            'entity_id' => (string)$assetId,
            'after' => ['status' => 'archived'],
        ]);
        Response::ok(['id' => $assetId, 'status' => 'archived']);
    }

    Response::err('Asset endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteAttachments(string $method, array $sub): never
{
    if ($method === 'GET' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'download') {
        $auth = qaRequireAuth('asset.read');
        $info = AssetAttachmentService::resolveForDownload(Connection::get(), (int)$sub[0], $auth);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($info['original_filename'])) ?: 'attachment';
        header('Content-Type: ' . $info['mime_type']);
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($info['full_path']);
        exit;
    }
    Response::err('Attachment route not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteAssetRequests(string $method, array $sub): never
{
    $pdo = Connection::get();

    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('asset.read');
        [$page, $limit, $offset] = Response::paginationParams();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM qa_asset_requests')->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT r.*, u.full_name AS requester_name, d.name_en AS department_name
             FROM qa_asset_requests r
             JOIN qa_users u ON u.id = r.requester_id
             JOIN qa_departments d ON d.id = r.department_id
             ORDER BY r.id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        Response::paginated($stmt->fetchAll(), $total, $page, $limit);
    }

    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('asset.request_submit');
        $body = Response::jsonBody();
        $req = AssetWorkflowService::createRequest($pdo, $body, $auth);
        Response::ok(['request' => $req], 201);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'resubmit') {
        $auth = qaRequireAuth('asset.request_submit');
        $body = Response::jsonBody();
        Response::ok(AssetWorkflowService::resubmitRequest($pdo, (int)$sub[0], $body, $auth));
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'department-head-decision') {
        $auth = qaRequireAuth('asset.request_dept_head');
        $body = Response::jsonBody();
        $res = AssetWorkflowService::departmentHeadDecision(
            $pdo,
            (int)$sub[0],
            (string)($body['decision'] ?? ''),
            (string)($body['note'] ?? ''),
            $auth
        );
        Response::ok($res);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'deputy-decision') {
        $auth = qaRequireAuth('asset.request_deputy');
        $body = Response::jsonBody();
        $res = AssetWorkflowService::deputyDecision(
            $pdo,
            (int)$sub[0],
            (string)($body['decision'] ?? ''),
            (string)($body['note'] ?? ''),
            $auth
        );
        Response::ok($res);
    }

    Response::err('Asset request route not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteHandovers(string $method, array $sub): never
{
    $pdo = Connection::get();

    if ($method === 'GET' && ($sub[0] ?? '') === 'overdue') {
        qaRequireAuth('asset.read');
        Response::ok(['overdue' => AssetService::listOverdueHandovers($pdo)]);
    }

    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('asset.read');
        [$page, $limit, $offset] = Response::paginationParams();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM qa_asset_handovers')->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT h.*, a.asset_code, a.name_en AS asset_name
             FROM qa_asset_handovers h
             JOIN qa_assets a ON a.id = h.asset_id
             ORDER BY h.id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        Response::paginated($stmt->fetchAll(), $total, $page, $limit);
    }

    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('asset.handover');
        $body = Response::jsonBody();
        $handover = AssetWorkflowService::createHandover($pdo, $body, $auth);
        Response::ok(['handover' => $handover], !empty($handover['idempotent_replay']) ? 200 : 201);
    }

    Response::err('Handover route not found.', 404, 'ROUTE_NOT_FOUND');
}

function qaRouteEventTemplates(string $method): never
{
    $pdo = Connection::get();
    if ($method === 'GET') {
        qaRequireAuth('asset.read');
        $rows = $pdo->query('SELECT * FROM qa_event_templates ORDER BY id DESC LIMIT 100')->fetchAll();
        Response::ok(['templates' => $rows]);
    }
    if ($method === 'POST') {
        $auth = qaRequireAuth('asset.event_manage');
        $body = Response::jsonBody();
        Response::ok(['template' => AssetLifecycleService::createEventTemplate($pdo, $body, $auth)], 201);
    }
    Response::err('Event template route not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteEvents(string $method, array $sub): never
{
    $pdo = Connection::get();

    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('asset.read');
        $rows = $pdo->query('SELECT * FROM qa_events ORDER BY id DESC LIMIT 100')->fetchAll();
        Response::ok(['events' => $rows]);
    }

    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('asset.event_manage');
        $body = Response::jsonBody();
        $ev = AssetLifecycleService::createEvent($pdo, $body, $auth);
        Response::ok(['event' => $ev], 201);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'stations') {
        qaRequireAuth('asset.event_manage');
        $body = Response::jsonBody();
        $st = AssetLifecycleService::insertStation($pdo, (int)$sub[0], $body);
        Response::ok(['station' => $st], 201);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'allocations') {
        qaRequireAuth('asset.event_manage');
        $body = Response::jsonBody();
        $al = AssetLifecycleService::allocateStationStock(
            $pdo,
            (int)$sub[0],
            (int)($body['station_id'] ?? 0),
            (int)($body['asset_id'] ?? 0),
            (int)($body['allocated_qty'] ?? 0),
            !empty($body['variant_id']) ? (int)$body['variant_id'] : null
        );
        Response::ok(['allocation' => $al], 201);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'prepare-participants') {
        qaRequireAuth('asset.event_manage');
        $body = Response::jsonBody();
        Response::ok(AssetLifecycleService::prepareEventParticipants($pdo, (int)$sub[0], $body));
    }

    if ($method === 'GET' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'package') {
        qaRequireAuth('asset.event_operate');
        Response::ok(AssetLifecycleService::getEventPackage($pdo, (int)$sub[0]));
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'sync-batch') {
        $auth = qaRequireAuth('asset.event_operate');
        $body = Response::jsonBody();
        $txs = is_array($body['transactions'] ?? null) ? $body['transactions'] : [];
        Response::ok(AssetLifecycleService::syncEventBatch($pdo, (int)$sub[0], $txs, $auth));
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'resolve-conflict') {
        $auth = qaRequireAuth('asset.event_manage');
        $body = Response::jsonBody();
        Response::ok(AssetLifecycleService::resolveEventConflict(
            $pdo,
            (int)$sub[0],
            (int)($body['transaction_id'] ?? 0),
            (string)($body['note'] ?? ''),
            $auth
        ));
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'close') {
        $auth = qaRequireAuth('asset.event_manage');
        Response::ok(AssetLifecycleService::closeEvent($pdo, (int)$sub[0], $auth));
    }

    if ($method === 'GET' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'reconcile') {
        qaRequireAuth('asset.event_manage');
        Response::ok(AssetLifecycleService::reconcileEvent($pdo, (int)$sub[0]));
    }

    Response::err('Event route not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteMaintenance(string $method, array $sub): never
{
    $pdo = Connection::get();

    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('asset.read');
        $rows = $pdo->query(
            'SELECT m.*, a.asset_code, a.name_en AS asset_name
             FROM qa_asset_maintenance m
             JOIN qa_assets a ON a.id = m.asset_id
             ORDER BY m.id DESC LIMIT 100'
        )->fetchAll();
        Response::ok(['tickets' => $rows]);
    }

    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('asset.maintenance_write');
        $body = Response::jsonBody();
        Response::ok(['ticket' => AssetLifecycleService::createMaintenanceTicket($pdo, $body, $auth)], 201);
    }

    if (in_array($method, ['PATCH', 'POST'], true) && count($sub) === 1 && ctype_digit($sub[0])) {
        $auth = qaRequireAuth('asset.maintenance_write');
        $body = Response::jsonBody();
        Response::ok(AssetLifecycleService::updateMaintenanceTicket($pdo, (int)$sub[0], $body, $auth));
    }

    Response::err('Maintenance route not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteInspections(string $method, array $sub): never
{
    $pdo = Connection::get();

    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('asset.read');
        $rows = $pdo->query('SELECT * FROM qa_asset_inspections ORDER BY id DESC LIMIT 100')->fetchAll();
        Response::ok(['inspections' => $rows]);
    }

    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('asset.inspection_write');
        $body = Response::jsonBody();
        Response::ok(['inspection' => AssetLifecycleService::createInspection($pdo, $body, $auth)], 201);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'scans') {
        $auth = qaRequireAuth('asset.inspection_write');
        $body = Response::jsonBody();
        $scans = is_array($body['scans'] ?? null) ? $body['scans'] : [];
        Response::ok(AssetLifecycleService::recordInspectionScans($pdo, (int)$sub[0], $scans, $auth), 201);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'close') {
        $auth = qaRequireAuth('asset.inspection_write');
        Response::ok(AssetLifecycleService::closeInspection($pdo, (int)$sub[0], $auth));
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'findings') {
        $auth = qaRequireAuth('asset.inspection_write');
        $body = Response::jsonBody();
        Response::ok(['finding' => AssetLifecycleService::createFindingFromInspection($pdo, (int)$sub[0], $body, $auth)], 201);
    }

    Response::err('Inspection route not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteDisposals(string $method, array $sub): never
{
    $pdo = Connection::get();

    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('asset.read');
        $rows = $pdo->query(
            'SELECT d.*, a.asset_code, a.name_en AS asset_name
             FROM qa_asset_disposals d
             JOIN qa_assets a ON a.id = d.asset_id
             ORDER BY d.id DESC LIMIT 100'
        )->fetchAll();
        Response::ok(['disposals' => $rows]);
    }

    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('asset.disposal_request');
        $body = Response::jsonBody();
        Response::ok(['disposal' => AssetLifecycleService::requestDisposal($pdo, $body, $auth)], 201);
    }

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'decision') {
        $auth = qaRequireAuth('asset.disposal_approve');
        $body = Response::jsonBody();
        Response::ok(AssetLifecycleService::decideDisposal($pdo, (int)$sub[0], (string)($body['decision'] ?? ''), $auth));
    }

    Response::err('Disposal route not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteAssetReports(string $method, array $sub): never
{
    if ($method !== 'GET' || ($sub[0] ?? '') !== 'assets' || empty($sub[1])) {
        Response::err('Report route not found.', 404, 'ROUTE_NOT_FOUND');
    }

    $auth = qaRequireAuth('asset.read');
    $reportKey = $sub[1];
    $filters = [
        'operational_year_id' => $_GET['operational_year_id'] ?? null,
        'category_id' => $_GET['category_id'] ?? null,
        'location_id' => $_GET['location_id'] ?? null,
        'date_from' => $_GET['date_from'] ?? null,
        'date_to' => $_GET['date_to'] ?? null,
    ];

    if (($sub[2] ?? '') === 'export') {
        $format = (string)($_GET['format'] ?? 'csv');
        $file = AssetReportService::export(Connection::get(), $reportKey, $format, $filters, $auth);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: ' . $file['content_type']);
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        header('X-Content-Type-Options: nosniff');
        echo $file['body'];
        exit;
    }

    Response::ok(AssetReportService::generate(Connection::get(), $reportKey, $filters));
}
