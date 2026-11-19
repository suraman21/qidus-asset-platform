<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;
use QidusAsset\Security\JwtService;
use QidusAsset\Security\Rbac;
use QidusAsset\Support\Response;

/**
 * Bearer-only API authentication + fail-closed authorization scope revalidation.
 *
 * @return array{
 *   uid:int,
 *   username:string,
 *   full_name:string,
 *   role:string,
 *   base_role:string,
 *   department_id:?int,
 *   auth_version:int,
 *   device_uuid:?string,
 *   impersonated_by:?int
 * }
 */
function qaRequireAuth(?string $requiredPermission = null): array
{
    $token = JwtService::extractBearerToken();
    if ($token === '') {
        Response::err('Authentication required.', 401, 'AUTH_REQUIRED');
    }

    $verified = JwtService::verify($token);
    if ($verified['state'] === 'expired') {
        Response::err('Access token expired.', 401, 'TOKEN_EXPIRED');
    }
    if ($verified['state'] !== 'valid' || !isset($verified['payload'])) {
        Response::err('Invalid access token.', 401, 'INVALID_TOKEN');
    }

    $claims = $verified['payload'];
    if (($claims['typ'] ?? '') !== 'access') {
        Response::err('Invalid token type.', 401, 'INVALID_TOKEN_TYPE');
    }

    $pdo = Connection::get();
    $stmt = $pdo->prepare(
        'SELECT id, username, full_name, qa_role, department_id, is_active, auth_version
         FROM qa_users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => (int)$claims['uid']]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        Response::err('Account inactive or not found.', 403, 'ACCOUNT_INACTIVE');
    }

    $tokenAv = (int)($claims['av'] ?? 1);
    $dbAv = (int)$user['auth_version'];
    if ($tokenAv !== $dbAv) {
        Response::err('Session invalidated due to role or credential update.', 401, 'AUTH_VERSION_MISMATCH');
    }

    if (!empty($claims['fid'])) {
        $sess = $pdo->prepare(
            'SELECT revoked_at FROM qa_auth_refresh_tokens WHERE user_id = :u AND family_id = :f ORDER BY id DESC LIMIT 1'
        );
        $sess->execute([':u' => (int)$user['id'], ':f' => (string)$claims['fid']]);
        $row = $sess->fetch();
        if (!$row || $row['revoked_at'] !== null) {
            Response::err('Session has been revoked.', 401, 'SESSION_REVOKED');
        }
    }

    $deviceUuid = trim((string)($_SERVER['HTTP_X_DEVICE_UUID'] ?? ''));
    if ($deviceUuid !== '') {
        $dev = $pdo->prepare(
            'SELECT status FROM qa_device_keys WHERE device_uuid = :d AND user_id = :u LIMIT 1'
        );
        $dev->execute([':d' => $deviceUuid, ':u' => (int)$user['id']]);
        $devRow = $dev->fetch();
        if ($devRow && $devRow['status'] === 'revoked') {
            Response::err('This device has been revoked by an administrator.', 403, 'DEVICE_REVOKED');
        }
    }

    $baseRole = (string)$user['qa_role'];
    $effectiveRole = (string)($claims['rol'] ?? $baseRole);
    $impersonatedBy = isset($claims['imp_by']) ? (int)$claims['imp_by'] : null;

    if ($baseRole !== 'system_admin' && $effectiveRole !== $baseRole) {
        Response::err('Authorization scope mismatch.', 403, 'ROLE_SCOPE_MISMATCH');
    }

    if ($requiredPermission !== null && !Rbac::can($effectiveRole, $requiredPermission)) {
        Response::err('You do not have permission to perform this action.', 403, 'FORBIDDEN');
    }

    return [
        'uid' => (int)$user['id'],
        'username' => (string)$user['username'],
        'full_name' => (string)$user['full_name'],
        'role' => $effectiveRole,
        'base_role' => $baseRole,
        'department_id' => $user['department_id'] !== null ? (int)$user['department_id'] : null,
        'auth_version' => $dbAv,
        'device_uuid' => $deviceUuid !== '' ? $deviceUuid : null,
        'impersonated_by' => $impersonatedBy,
    ];
}
