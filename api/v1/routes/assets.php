<?php
declare(strict_types=1);

use QidusAsset\Asset\AssetLifecycleService;
use QidusAsset\Asset\AssetService;
use QidusAsset\Asset\AssetWorkflowService;
use QidusAsset\Database\Connection;
use QidusAsset\Support\IdempotencyService;
use QidusAsset\Support\Response;

/**
 * Department 1 (Asset Dept) REST v1 Routes.
 *
 * @param list<string> $segments
 */
function qaHandleAssetRoute(string $method, array $segments): never
{
    $root = $segments[0] ?? '';
    $sub = array_slice($segments, 1);

    if ($root === 'asset-categories' && $method === 'GET') {
        qaRequireAuth('asset.read');
        $rows = Connection::get()->query('SELECT * FROM qa_asset_categories WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
        Response::ok(['categories' => $rows]);
    }

    if ($root === 'asset-locations' && $method === 'GET') {
        qaRequireAuth('asset.read');
        $rows = Connection::get()->query('SELECT * FROM qa_asset_locations WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
        Response::ok(['locations' => $rows]);
    }

    if ($root === 'assets') {
        qaRouteAssets($method, $sub);
    }
    if ($root === 'asset-requests') {
        qaRouteAssetRequests($method, $sub);
    }
    if ($root === 'handovers') {
        qaRouteHandovers($method, $sub);
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
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
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

    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'archive') {
        $auth = qaRequireAuth('asset.write');
        $pdo->prepare("UPDATE qa_assets SET status = 'archived' WHERE id = :id")->execute([':id' => (int)$sub[0]]);
        Response::ok(['id' => (int)$sub[0], 'status' => 'archived']);
    }

    Response::err('Asset endpoint not found.', 404, 'ROUTE_NOT_FOUND');
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
