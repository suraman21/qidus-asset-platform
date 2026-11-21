<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\JwtService;
use QidusAsset\Security\PasswordService;
use QidusAsset\Security\Rbac;
use QidusAsset\Support\Response;

/**
 * @param list<string> $sub
 */
function qaHandleAuthRoute(string $method, array $sub): never
{
    $action = $sub[0] ?? '';

    if ($action === 'login' && $method === 'POST') {
        qaAuthLogin();
    }
    if ($action === 'refresh' && $method === 'POST') {
        qaAuthRefresh();
    }
    if ($action === 'logout' && $method === 'POST') {
        qaAuthLogout();
    }
    if ($action === 'impersonate' && $method === 'POST') {
        qaAuthImpersonate();
    }

    Response::err('Auth route not found.', 404, 'ROUTE_NOT_FOUND');
}

function qaAuthLogin(): never
{
    $body = Response::jsonBody();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $deviceLabel = trim((string)($body['device_label'] ?? $body['device_uuid'] ?? ''));

    if ($username === '' || $password === '') {
        Response::err('Username and password are required.', 422, 'VALIDATION_ERROR');
    }

    $pdo = Connection::get();

    // Sliding-window brute-force throttle (max 8 failed attempts per 15 min per username or IP)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $rateStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM qa_audit_logs
         WHERE action IN ('auth.login_failed','web.login_failed')
           AND server_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
           AND (entity_id = :u OR (:ip <> '' AND ip_address = :ip2))"
    );
    $rateStmt->execute([':u' => $username, ':ip' => $ip, ':ip2' => $ip]);
    if ((int)$rateStmt->fetchColumn() >= 8) {
        Response::err('Too many failed login attempts. Please wait 15 minutes and try again.', 429, 'RATE_LIMITED');
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, full_name, full_name_am, qa_role, department_id,
                password_hash, is_active, auth_version, external_user_id
         FROM qa_users
         WHERE username = :u
         LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !PasswordService::verify($password, $user['password_hash'])) {
        AuditLogger::log($pdo, [
            'actor_user_id' => $user ? (int)$user['id'] : null,
            'actor_role' => $user ? (string)$user['qa_role'] : 'anonymous',
            'action' => 'auth.login_failed',
            'entity_type' => 'user',
            'entity_id' => $username,
            'outcome' => 'rejected',
        ]);
        Response::err('Invalid username or password.', 401, 'INVALID_CREDENTIALS');
    }

    if ((int)$user['is_active'] !== 1) {
        Response::err('Account is suspended or inactive.', 403, 'ACCOUNT_INACTIVE');
    }

    $familyId = hash('sha256', random_bytes(32));
    $sessionId = hash('sha256', random_bytes(32));
    $refreshPlain = bin2hex(random_bytes(32));
    $refreshHash = hash('sha256', $refreshPlain);
    $refreshTtl = (int)qa_config('refresh_token_ttl', 7776000);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + $refreshTtl);

    $ins = $pdo->prepare(
        'INSERT INTO qa_auth_refresh_tokens (user_id, family_id, session_id, token_hash, device_label, expires_at)
         VALUES (:u, :f, :s, :h, :d, :exp)'
    );
    $ins->execute([
        ':u' => (int)$user['id'],
        ':f' => $familyId,
        ':s' => $sessionId,
        ':h' => $refreshHash,
        ':d' => $deviceLabel !== '' ? substr($deviceLabel, 0, 120) : null,
        ':exp' => $expiresAt,
    ]);

    $pdo->prepare('UPDATE qa_users SET last_login_at = NOW() WHERE id = :id')
        ->execute([':id' => (int)$user['id']]);

    $accessToken = JwtService::issueAccessToken([
        'uid' => (int)$user['id'],
        'usr' => (string)$user['username'],
        'rol' => (string)$user['qa_role'],
        'dept' => $user['department_id'] !== null ? (int)$user['department_id'] : null,
        'nam' => (string)$user['full_name'],
        'av' => (int)$user['auth_version'],
        'fid' => $familyId,
        'rsid' => $sessionId,
    ]);

    AuditLogger::log($pdo, [
        'actor_user_id' => (int)$user['id'],
        'actor_role' => (string)$user['qa_role'],
        'department_id' => $user['department_id'] !== null ? (int)$user['department_id'] : null,
        'action' => 'auth.login',
        'entity_type' => 'user',
        'entity_id' => (string)$user['id'],
        'outcome' => 'accepted',
    ]);

    Response::ok([
        'access_token' => $accessToken,
        'refresh_token' => $refreshPlain,
        'expires_in' => (int)qa_config('access_token_ttl', 900),
        'token_type' => 'Bearer',
        'user' => [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'full_name' => (string)$user['full_name'],
            'full_name_am' => $user['full_name_am'],
            'role' => (string)$user['qa_role'],
            'department_id' => $user['department_id'] !== null ? (int)$user['department_id'] : null,
            'capabilities' => Rbac::capabilitiesForRole((string)$user['qa_role']),
        ],
    ]);
}

function qaAuthRefresh(): never
{
    $body = Response::jsonBody();
    $refreshPlain = trim((string)($body['refresh_token'] ?? ''));
    if ($refreshPlain === '') {
        Response::err('refresh_token is required.', 422, 'VALIDATION_ERROR');
    }

    $tokenHash = hash('sha256', $refreshPlain);
    $pdo = Connection::get();

    $result = Connection::transaction(function (PDO $tx) use ($tokenHash) {
        $stmt = $tx->prepare(
            'SELECT s.id, s.user_id, s.family_id, s.device_label, s.expires_at, s.used_at, s.revoked_at,
                    u.username, u.full_name, u.qa_role, u.department_id, u.is_active, u.auth_version
             FROM qa_auth_refresh_tokens s
             JOIN qa_users u ON u.id = s.user_id
             WHERE s.token_hash = :h
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':h' => $tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['error' => ['Refresh token invalid.', 401, 'INVALID_REFRESH_TOKEN']];
        }

        // Reuse / theft detection: revoke entire token family
        if ($row['revoked_at'] !== null || $row['used_at'] !== null) {
            $tx->prepare('UPDATE qa_auth_refresh_tokens SET revoked_at = NOW() WHERE family_id = :f AND revoked_at IS NULL')
                ->execute([':f' => (string)$row['family_id']]);
            AuditLogger::log($tx, [
                'actor_user_id' => (int)$row['user_id'],
                'actor_role' => (string)$row['qa_role'],
                'action' => 'auth.refresh_reuse_detected',
                'entity_type' => 'refresh_session',
                'entity_id' => (string)$row['family_id'],
                'outcome' => 'rejected',
            ]);
            return ['error' => ['Refresh token reuse detected; session family revoked.', 401, 'REFRESH_TOKEN_REUSED']];
        }

        if ((int)$row['is_active'] !== 1) {
            return ['error' => ['Account is inactive.', 403, 'ACCOUNT_INACTIVE']];
        }
        if (strtotime((string)$row['expires_at']) <= time()) {
            return ['error' => ['Refresh token expired.', 401, 'REFRESH_TOKEN_EXPIRED']];
        }

        $newSessionId = hash('sha256', random_bytes(32));
        $newPlain = bin2hex(random_bytes(32));
        $newHash = hash('sha256', $newPlain);
        $refreshTtl = (int)qa_config('refresh_token_ttl', 7776000);
        $newExpires = gmdate('Y-m-d H:i:s', time() + $refreshTtl);

        $ins = $tx->prepare(
            'INSERT INTO qa_auth_refresh_tokens (user_id, family_id, session_id, token_hash, device_label, expires_at)
             VALUES (:u, :f, :s, :h, :d, :exp)'
        );
        $ins->execute([
            ':u' => (int)$row['user_id'],
            ':f' => (string)$row['family_id'],
            ':s' => $newSessionId,
            ':h' => $newHash,
            ':d' => $row['device_label'],
            ':exp' => $newExpires,
        ]);

        $tx->prepare('UPDATE qa_auth_refresh_tokens SET used_at = NOW() WHERE id = :id')
            ->execute([':id' => (int)$row['id']]);

        $accessToken = JwtService::issueAccessToken([
            'uid' => (int)$row['user_id'],
            'usr' => (string)$row['username'],
            'rol' => (string)$row['qa_role'],
            'dept' => $row['department_id'] !== null ? (int)$row['department_id'] : null,
            'nam' => (string)$row['full_name'],
            'av' => (int)$row['auth_version'],
            'fid' => (string)$row['family_id'],
            'rsid' => $newSessionId,
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $newPlain,
            'expires_in' => (int)qa_config('access_token_ttl', 900),
            'token_type' => 'Bearer',
        ];
    });

    if (isset($result['error'])) {
        Response::err($result['error'][0], $result['error'][1], $result['error'][2]);
    }
    Response::ok($result);
}

function qaAuthLogout(): never
{
    $body = Response::jsonBody();
    $refreshPlain = trim((string)($body['refresh_token'] ?? ''));
    $pdo = Connection::get();

    if ($refreshPlain !== '') {
        $hash = hash('sha256', $refreshPlain);
        $stmt = $pdo->prepare('SELECT user_id, family_id FROM qa_auth_refresh_tokens WHERE token_hash = :h LIMIT 1');
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare('UPDATE qa_auth_refresh_tokens SET revoked_at = NOW() WHERE family_id = :f AND revoked_at IS NULL')
                ->execute([':f' => (string)$row['family_id']]);
            AuditLogger::log($pdo, [
                'actor_user_id' => (int)$row['user_id'],
                'actor_role' => 'authenticated',
                'action' => 'auth.logout',
                'entity_type' => 'refresh_session',
                'entity_id' => (string)$row['family_id'],
            ]);
        }
    }
    Response::ok(['message' => 'Logged out successfully.']);
}

function qaAuthImpersonate(): never
{
    $auth = qaRequireAuth('system.admin');
    if ($auth['base_role'] !== 'system_admin') {
        Response::err('Only system_admin may switch into department roles.', 403, 'FORBIDDEN');
    }

    $body = Response::jsonBody();
    $targetRole = trim((string)($body['target_role'] ?? ''));
    if (!in_array($targetRole, Rbac::ALL_ROLES, true)) {
        Response::err('Invalid target_role.', 422, 'VALIDATION_ERROR');
    }

    $pdo = Connection::get();
    AuditLogger::log($pdo, [
        'actor_user_id' => $auth['uid'],
        'actor_role' => $auth['base_role'],
        'impersonated_by_user_id' => $auth['uid'],
        'action' => 'auth.impersonate_role',
        'entity_type' => 'role_switch',
        'entity_id' => $targetRole,
        'after' => ['target_role' => $targetRole],
        'outcome' => 'accepted',
    ]);

    $token = JwtService::issueAccessToken([
        'uid' => $auth['uid'],
        'usr' => $auth['username'],
        'rol' => $targetRole,
        'dept' => $auth['department_id'],
        'nam' => $auth['full_name'],
        'av' => $auth['auth_version'],
        'imp_by' => $auth['uid'],
    ], 1800);

    Response::ok([
        'access_token' => $token,
        'effective_role' => $targetRole,
        'capabilities' => Rbac::capabilitiesForRole($targetRole),
        'expires_in' => 1800,
    ]);
}
