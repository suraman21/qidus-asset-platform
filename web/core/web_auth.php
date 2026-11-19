<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\PasswordService;
use QidusAsset\Security\Rbac;

function qaWebBootSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('QIDUS_ASSET_SESSID');
        session_start();
    }

    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    if (empty($_SESSION['qa_csrf'])) {
        $_SESSION['qa_csrf'] = bin2hex(random_bytes(32));
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function qaCsrfToken(): string
{
    return (string)($_SESSION['qa_csrf'] ?? '');
}

function qaVerifyCsrf(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(qaCsrfToken(), $token);
}

/**
 * @return array<string,mixed>|null
 */
function qaWebCurrentUser(): ?array
{
    if (empty($_SESSION['qa_uid'])) {
        return null;
    }

    $pdo = Connection::get();
    $stmt = $pdo->prepare(
        'SELECT id, username, full_name, full_name_am, qa_role, department_id, is_active, auth_version
         FROM qa_users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => (int)$_SESSION['qa_uid']]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1 || (int)$user['auth_version'] !== (int)($_SESSION['qa_av'] ?? 0)) {
        $_SESSION = [];
        return null;
    }

    $baseRole = (string)$user['qa_role'];
    $actingRole = (string)($_SESSION['qa_acting_role'] ?? $baseRole);
    if ($baseRole !== 'system_admin') {
        $actingRole = $baseRole;
    }

    return [
        'uid' => (int)$user['id'],
        'username' => (string)$user['username'],
        'full_name' => (string)$user['full_name'],
        'full_name_am' => $user['full_name_am'],
        'base_role' => $baseRole,
        'role' => $actingRole,
        'is_impersonating' => $baseRole === 'system_admin' && $actingRole !== 'system_admin',
        'department_id' => $user['department_id'] !== null ? (int)$user['department_id'] : null,
    ];
}

function qaWebAttemptLogin(string $username, string $password): array
{
    $pdo = Connection::get();
    $stmt = $pdo->prepare(
        'SELECT id, username, full_name, qa_role, password_hash, is_active, auth_version
         FROM qa_users WHERE username = :u LIMIT 1'
    );
    $stmt->execute([':u' => trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !PasswordService::verify($password, $user['password_hash'])) {
        AuditLogger::log($pdo, [
            'actor_user_id' => $user ? (int)$user['id'] : null,
            'actor_role' => $user ? (string)$user['qa_role'] : 'anonymous',
            'action' => 'web.login_failed',
            'entity_type' => 'user',
            'entity_id' => trim($username),
            'outcome' => 'rejected',
        ]);
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    if ((int)$user['is_active'] !== 1) {
        return ['ok' => false, 'error' => 'Account is inactive or suspended.'];
    }

    session_regenerate_id(true);
    $_SESSION['qa_uid'] = (int)$user['id'];
    $_SESSION['qa_av'] = (int)$user['auth_version'];
    $_SESSION['qa_acting_role'] = (string)$user['qa_role'];
    $_SESSION['qa_csrf'] = bin2hex(random_bytes(32));

    $pdo->prepare('UPDATE qa_users SET last_login_at = NOW() WHERE id = :id')
        ->execute([':id' => (int)$user['id']]);

    AuditLogger::log($pdo, [
        'actor_user_id' => (int)$user['id'],
        'actor_role' => (string)$user['qa_role'],
        'action' => 'web.login',
        'entity_type' => 'user',
        'entity_id' => (string)$user['id'],
    ]);

    return ['ok' => true, 'role' => (string)$user['qa_role']];
}

function qaWebSwitchRole(array $user, string $targetRole): bool
{
    if ($user['base_role'] !== 'system_admin' || !in_array($targetRole, Rbac::ALL_ROLES, true)) {
        return false;
    }
    $_SESSION['qa_acting_role'] = $targetRole;

    $pdo = Connection::get();
    AuditLogger::log($pdo, [
        'actor_user_id' => $user['uid'],
        'actor_role' => $user['base_role'],
        'impersonated_by_user_id' => $user['uid'],
        'action' => 'web.switch_role',
        'entity_type' => 'role_switch',
        'entity_id' => $targetRole,
        'after' => ['target_role' => $targetRole],
    ]);
    return true;
}

function qaWebDefaultPageForRole(string $role): string
{
    return match ($role) {
        'finance_head', 'finance_staff', 'procurement_officer', 'document_controller' => 'finance',
        'system_admin', 'chairperson', 'deputy_chairperson', 'auditor' => 'leadership',
        default => 'asset',
    };
}
