<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/config/bootstrap.php';
require_once __DIR__ . '/core/web_auth.php';
require_once __DIR__ . '/layouts/shell.php';

use QidusAsset\Security\Rbac;

qaWebBootSession();

$action = (string)($_GET['action'] ?? '');
$user = qaWebCurrentUser();

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qaVerifyCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
    $_SESSION = [];
    session_destroy();
    header('Location: index.php?page=login');
    exit;
}

if ($action === 'switch_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user || !qaVerifyCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $targetRole = (string)($_POST['target_role'] ?? '');
    if (qaWebSwitchRole($user, $targetRole)) {
        $next = qaWebDefaultPageForRole($targetRole);
        header('Location: index.php?page=' . urlencode($next));
        exit;
    }
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'login' || ($_POST['action'] ?? '') === 'login')) {
    if (!qaVerifyCsrf($_POST['csrf'] ?? null)) {
        $loginError = 'Session expired. Please refresh and try again.';
    } else {
        $res = qaWebAttemptLogin((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($res['ok']) {
            $next = qaWebDefaultPageForRole((string)$res['role']);
            header('Location: index.php?page=' . urlencode($next));
            exit;
        }
        $loginError = (string)$res['error'];
    }
}

if (!$user) {
    if (($_GET['page'] ?? '') === 'setup') {
        require __DIR__ . '/pages/setup.php';
        exit;
    }
    require __DIR__ . '/pages/login.php';
    exit;
}

$page = (string)($_GET['page'] ?? qaWebDefaultPageForRole((string)$user['role']));
$role = (string)$user['role'];

if ($page === 'asset') {
    if (!Rbac::can($role, 'asset.read')) {
        http_response_code(403);
        exit('Forbidden: Asset Department access denied.');
    }
    require __DIR__ . '/pages/asset_dept.php';
    exit;
}

if ($page === 'finance') {
    if (!Rbac::can($role, 'finance.read') && !Rbac::can($role, 'document.read')) {
        http_response_code(403);
        exit('Forbidden: Finance & Related Department access denied.');
    }
    require __DIR__ . '/pages/finance_dept.php';
    exit;
}

if ($page === 'leadership') {
    if (!Rbac::can($role, 'system.executive_summary') && !Rbac::can($role, 'system.audit_read')) {
        header('Location: index.php?page=' . urlencode(qaWebDefaultPageForRole($role)));
        exit;
    }
    require __DIR__ . '/pages/leadership_audit.php';
    exit;
}

header('Location: index.php?page=' . urlencode(qaWebDefaultPageForRole($role)));
exit;
