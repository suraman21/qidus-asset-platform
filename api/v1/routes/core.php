<?php
declare(strict_types=1);

use QidusAsset\Connector\ConnectorFactory;
use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\BiometricVerifier;
use QidusAsset\Security\JwtService;
use QidusAsset\Security\Rbac;
use QidusAsset\Support\Response;

/**
 * @param list<string> $segments
 */
function qaHandleCoreRoute(string $method, array $segments): never
{
    $root = $segments[0] ?? '';

    if ($root === 'users' && ($segments[1] ?? '') === 'me' && $method === 'GET') {
        $auth = qaRequireAuth();
        Response::ok([
            'user' => $auth,
            'capabilities' => Rbac::capabilitiesForRole($auth['role']),
            'offline_capabilities' => Rbac::offlineCapabilitiesForRole($auth['role']),
        ]);
    }

    if ($root === 'departments' && $method === 'GET') {
        qaRequireAuth();
        $pdo = Connection::get();
        $rows = $pdo->query(
            'SELECT id, code, name_en, name_am, parent_dept_code, head_user_id, is_active FROM qa_departments ORDER BY id ASC'
        )->fetchAll();
        Response::ok(['departments' => $rows]);
    }

    if ($root === 'years') {
        if ($method === 'GET') {
            qaRequireAuth();
            $pdo = Connection::get();
            $rows = $pdo->query(
                'SELECT id, ec_year, label_en, label_am, start_date, end_date, status, closed_at
                 FROM qa_operational_years ORDER BY ec_year DESC, id DESC'
            )->fetchAll();
            Response::ok(['years' => $rows]);
        }
        if ($method === 'POST') {
            $auth = qaRequireAuth('system.year_manage');
            $body = Response::jsonBody();
            $ecYear = (int)($body['ec_year'] ?? 0);
            $labelEn = trim((string)($body['label_en'] ?? ''));
            $labelAm = trim((string)($body['label_am'] ?? $labelEn));
            $startDate = trim((string)($body['start_date'] ?? ''));
            $endDate = trim((string)($body['end_date'] ?? ''));
            if ($ecYear < 2010 || $labelEn === '' || $startDate === '' || $endDate === '') {
                Response::err('ec_year, label_en, start_date, and end_date are required.', 422, 'VALIDATION_ERROR');
            }
            $pdo = Connection::get();
            $stmt = $pdo->prepare(
                "INSERT INTO qa_operational_years (ec_year, label_en, label_am, start_date, end_date, status)
                 VALUES (:ey, :le, :la, :s, :e, 'active')"
            );
            $stmt->execute([':ey' => $ecYear, ':le' => $labelEn, ':la' => $labelAm, ':s' => $startDate, ':e' => $endDate]);
            $id = (int)$pdo->lastInsertId();
            AuditLogger::log($pdo, [
                'actor_user_id' => $auth['uid'],
                'actor_role' => $auth['role'],
                'action' => 'year.create',
                'entity_type' => 'operational_year',
                'entity_id' => (string)$id,
                'after' => ['ec_year' => $ecYear, 'label_en' => $labelEn],
            ]);
            Response::ok(['id' => $id, 'ec_year' => $ecYear], 201);
        }
    }

    if ($root === 'devices') {
        qaHandleDevicesRoute($method, array_slice($segments, 1));
    }

    if ($root === 'offline-lease' && $method === 'POST') {
        qaIssueOfflineLease();
    }

    if ($root === 'members') {
        qaHandleMembersRoute($method, array_slice($segments, 1));
    }

    if ($root === 'notifications') {
        qaHandleNotificationsRoute($method, array_slice($segments, 1));
    }

    if ($root === 'audit-logs' && $method === 'GET') {
        qaRequireAuth('system.audit_read');
        [$page, $limit, $offset] = Response::paginationParams();
        $pdo = Connection::get();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM qa_audit_logs')->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT id, actor_user_id, actor_role, impersonated_by_user_id, department_id,
                    device_uuid, action, entity_type, entity_id, outcome, server_at
             FROM qa_audit_logs
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        Response::paginated($stmt->fetchAll(), $total, $page, $limit);
    }

    Response::err('Route not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaHandleDevicesRoute(string $method, array $sub): never
{
    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('system.device_approve');
        $pdo = Connection::get();
        $rows = $pdo->query(
            'SELECT d.id, d.device_uuid, d.user_id, u.username, u.full_name, d.device_label,
                    d.key_algorithm, d.biometric_bound, d.status, d.approved_at, d.last_used_at, d.created_at
             FROM qa_device_keys d
             JOIN qa_users u ON u.id = d.user_id
             ORDER BY d.id DESC
             LIMIT 200'
        )->fetchAll();
        Response::ok(['devices' => $rows]);
    }

    if ($method === 'POST' && ($sub[0] ?? '') === 'register') {
        $auth = qaRequireAuth();
        $body = Response::jsonBody();
        $deviceUuid = trim((string)($body['device_uuid'] ?? ''));
        $deviceLabel = trim((string)($body['device_label'] ?? $body['device_name'] ?? 'Android Device'));
        $publicKeyPem = trim((string)($body['public_key_pem'] ?? ''));
        $algo = in_array(($body['key_algorithm'] ?? 'EC_P256'), ['EC_P256', 'RSA_2048'], true)
            ? (string)$body['key_algorithm'] : 'EC_P256';
        $bioBound = isset($body['biometric_bound']) ? (!empty($body['biometric_bound']) ? 1 : 0) : 1;

        if ($deviceUuid === '' || strlen($deviceUuid) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $deviceUuid)) {
            Response::err('Valid device_uuid is required.', 422, 'VALIDATION_ERROR');
        }
        if ($publicKeyPem === '' || !BiometricVerifier::isValidPublicKeyPem($publicKeyPem)) {
            Response::err('Valid PEM public_key_pem is required.', 422, 'INVALID_PUBLIC_KEY');
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            "INSERT INTO qa_device_keys (
                user_id, device_uuid, device_label, key_algorithm, public_key_pem, biometric_bound, status, last_used_at
             ) VALUES (
                :uid, :du, :dl, :algo, :pk, :bio, 'pending', NOW()
             )
             ON DUPLICATE KEY UPDATE
                device_label = VALUES(device_label),
                key_algorithm = VALUES(key_algorithm),
                public_key_pem = VALUES(public_key_pem),
                biometric_bound = VALUES(biometric_bound),
                last_used_at = NOW()"
        );
        $stmt->execute([
            ':uid' => $auth['uid'],
            ':du' => $deviceUuid,
            ':dl' => substr($deviceLabel, 0, 120),
            ':algo' => $algo,
            ':pk' => $publicKeyPem,
            ':bio' => $bioBound,
        ]);

        AuditLogger::log($pdo, [
            'actor_user_id' => $auth['uid'],
            'actor_role' => $auth['role'],
            'device_uuid' => $deviceUuid,
            'action' => 'device.register',
            'entity_type' => 'device',
            'entity_id' => $deviceUuid,
        ]);

        Response::ok(['device_uuid' => $deviceUuid, 'status' => 'pending'], 201);
    }

    if ($method === 'POST' && count($sub) === 2 && in_array($sub[1], ['approve', 'revoke'], true)) {
        $auth = qaRequireAuth('system.device_approve');
        $deviceUuid = trim($sub[0]);
        $targetAction = $sub[1];
        $pdo = Connection::get();

        if ($targetAction === 'approve') {
            $stmt = $pdo->prepare(
                "UPDATE qa_device_keys
                 SET status = 'active', approved_by = :by, approved_at = NOW(), revoked_at = NULL
                 WHERE device_uuid = :du"
            );
            $stmt->execute([':by' => $auth['uid'], ':du' => $deviceUuid]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE qa_device_keys
                 SET status = 'revoked', revoked_by = :by, revoked_at = NOW()
                 WHERE device_uuid = :du"
            );
            $stmt->execute([':by' => $auth['uid'], ':du' => $deviceUuid]);
            $pdo->prepare('UPDATE qa_offline_leases SET revoked_at = NOW() WHERE device_uuid = :du AND revoked_at IS NULL')
                ->execute([':du' => $deviceUuid]);
        }

        AuditLogger::log($pdo, [
            'actor_user_id' => $auth['uid'],
            'actor_role' => $auth['role'],
            'device_uuid' => $deviceUuid,
            'action' => 'device.' . $targetAction,
            'entity_type' => 'device',
            'entity_id' => $deviceUuid,
        ]);

        Response::ok(['device_uuid' => $deviceUuid, 'status' => $targetAction === 'approve' ? 'active' : 'revoked']);
    }

    Response::err('Device route not found.', 404, 'ROUTE_NOT_FOUND');
}

function qaIssueOfflineLease(): never
{
    $auth = qaRequireAuth();
    $body = Response::jsonBody();
    $deviceUuid = trim((string)($body['device_uuid'] ?? $auth['device_uuid'] ?? ''));
    if ($deviceUuid === '') {
        Response::err('device_uuid is required for offline lease issuance.', 422, 'VALIDATION_ERROR');
    }

    $pdo = Connection::get();
    $devStmt = $pdo->prepare(
        'SELECT id, status FROM qa_device_keys WHERE device_uuid = :du AND user_id = :uid LIMIT 1'
    );
    $devStmt->execute([':du' => $deviceUuid, ':uid' => $auth['uid']]);
    $device = $devStmt->fetch();

    if (!$device || $device['status'] !== 'active') {
        Response::err('Device must be registered and approved before receiving an offline lease.', 403, 'DEVICE_NOT_APPROVED');
    }

    $offlineCaps = Rbac::offlineCapabilitiesForRole($auth['role']);
    $scope = [
        'role' => $auth['role'],
        'auth_version' => $auth['auth_version'],
        'permissions' => $offlineCaps,
        'department_id' => $auth['department_id'],
    ];

    $leaseJti = hash('sha256', random_bytes(32));
    $ttl = (int)qa_config('offline_lease_ttl', 86400);
    $issuedAt = gmdate('Y-m-d H:i:s');
    $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);
    $leaseToken = JwtService::issueOfflineLease($auth['uid'], $deviceUuid, $leaseJti, $scope, $ttl);

    $ins = $pdo->prepare(
        'INSERT INTO qa_offline_leases (user_id, device_uuid, lease_jti, scope_json, issued_at, expires_at)
         VALUES (:u, :d, :j, :s, :iss, :exp)'
    );
    $ins->execute([
        ':u' => $auth['uid'],
        ':d' => $deviceUuid,
        ':j' => $leaseJti,
        ':s' => (string)json_encode($scope, JSON_UNESCAPED_UNICODE),
        ':iss' => $issuedAt,
        ':exp' => $expiresAt,
    ]);

    AuditLogger::log($pdo, [
        'actor_user_id' => $auth['uid'],
        'actor_role' => $auth['role'],
        'device_uuid' => $deviceUuid,
        'action' => 'offline_lease.issue',
        'entity_type' => 'offline_lease',
        'entity_id' => $leaseJti,
    ]);

    Response::ok([
        'lease_token' => $leaseToken,
        'lease_jti' => $leaseJti,
        'expires_in' => $ttl,
        'expires_at_utc' => gmdate('c', time() + $ttl),
        'scope' => $scope,
    ]);
}

/**
 * @param list<string> $sub
 */
function qaHandleMembersRoute(string $method, array $sub): never
{
    qaRequireAuth('members.sync_read');
    $pdo = Connection::get();
    $connector = ConnectorFactory::memberConnector($pdo);

    if ($method === 'GET' && ($sub[0] ?? '') === 'search') {
        [$page, $limit] = Response::paginationParams();
        $q = (string)($_GET['q'] ?? '');
        $includeInactive = !empty($_GET['include_inactive']);
        $res = $connector->search($q, $page, $limit, $includeInactive);
        Response::paginated($res['items'], $res['total'], $page, $limit);
    }

    if ($method === 'GET' && ($sub[0] ?? '') === 'sync') {
        $cursor = isset($_GET['since']) ? (string)$_GET['since'] : null;
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 200)));
        $res = $connector->syncChanges($cursor, $limit);
        Response::ok([
            'connector_mode' => $connector->mode(),
            'items' => $res['items'],
            'tombstones' => $res['tombstones'],
            'next_cursor' => $res['next_cursor'],
            'has_more' => $res['has_more'],
        ]);
    }

    if ($method === 'GET' && count($sub) === 1 && $sub[0] !== '') {
        $member = $connector->findByExternalId($sub[0]);
        if (!$member) {
            Response::err('Member not found.', 404, 'MEMBER_NOT_FOUND');
        }
        Response::ok(['member' => $member]);
    }

    Response::err('Members route not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaHandleNotificationsRoute(string $method, array $sub): never
{
    $auth = qaRequireAuth();
    $pdo = Connection::get();

    if ($method === 'GET' && $sub === []) {
        [$page, $limit, $offset] = Response::paginationParams();
        $cStmt = $pdo->prepare('SELECT COUNT(*) FROM qa_notifications WHERE recipient_user_id = :u');
        $cStmt->execute([':u' => $auth['uid']]);
        $total = (int)$cStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id, type, title, message, entity_type, entity_id, is_read, read_at, created_at
             FROM qa_notifications
             WHERE recipient_user_id = :u
             ORDER BY is_read ASC, id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':u', $auth['uid'], PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        Response::paginated($stmt->fetchAll(), $total, $page, $limit);
    }

    if ($method === 'POST' && count($sub) === 2 && $sub[1] === 'read') {
        $id = (int)$sub[0];
        $stmt = $pdo->prepare(
            'UPDATE qa_notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND recipient_user_id = :u'
        );
        $stmt->execute([':id' => $id, ':u' => $auth['uid']]);
        Response::ok(['id' => $id, 'is_read' => true]);
    }

    Response::err('Notifications route not found.', 404, 'ROUTE_NOT_FOUND');
}
