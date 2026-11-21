<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/config/bootstrap.php';
require_once __DIR__ . '/core/web_auth.php';
require_once __DIR__ . '/core/i18n.php';
require_once __DIR__ . '/layouts/shell.php';

use QidusAsset\Database\Connection;
use QidusAsset\Security\Rbac;

qaWebBootSession();

if (isset($_GET['set_lang']) && in_array($_GET['set_lang'], ['am', 'en'], true)) {
    qaSetLang((string)$_GET['set_lang']);
    $params = $_GET;
    unset($params['set_lang']);
    $qs = http_build_query($params);
    header('Location: index.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$action = (string)($_GET['action'] ?? '');
$user = qaWebCurrentUser();

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qaVerifyCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
    $lang = qaLang();
    $_SESSION = [];
    session_destroy();
    qaSetLang($lang);
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

if ($action === 'mark_notif_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user || !qaVerifyCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $nid = (int)($_POST['notification_id'] ?? 0);
    $pdo = Connection::get();
    if ($nid > 0) {
        $pdo->prepare('UPDATE qa_notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND recipient_user_id = :u')
            ->execute([':id' => $nid, ':u' => (int)$user['uid']]);
    } else {
        $pdo->prepare('UPDATE qa_notifications SET is_read = 1, read_at = NOW() WHERE recipient_user_id = :u AND is_read = 0')
            ->execute([':u' => (int)$user['uid']]);
    }
    $ret = (string)($_POST['return_to'] ?? 'index.php');
    if (str_starts_with($ret, 'index.php')) {
        header('Location: ' . $ret);
    } else {
        header('Location: index.php');
    }
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'login' || ($_POST['action'] ?? '') === 'login')) {
    if ($user) {
        header('Location: index.php?page=' . urlencode(qaWebDefaultPageForRole((string)$user['role'])));
        exit;
    }
    if (!qaVerifyCsrf($_POST['csrf'] ?? null)) {
        $loginError = qaLang() === 'am'
            ? 'የክፍለ ጊዜው ማረጋገጫ ጊዜው አልፏል። እባክዎ ገጹን አድሰው እንደገና ይሞክሩ።'
            : 'Session expired. Please refresh and try again.';
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
    $requestedPage = (string)($_GET['page'] ?? '');
    if ($requestedPage !== 'setup') {
        try {
            $cnt = (int)Connection::get()->query('SELECT COUNT(*) FROM qa_users')->fetchColumn();
            if ($cnt === 0) {
                header('Location: index.php?page=setup');
                exit;
            }
        } catch (\Throwable $e) {
            // Fall through to login if DB check fails
        }
    }
    if ($requestedPage === 'setup') {
        require __DIR__ . '/pages/setup.php';
        exit;
    }
    require __DIR__ . '/pages/login.php';
    exit;
}

$page = (string)($_GET['page'] ?? qaWebDefaultPageForRole((string)$user['role']));
$role = (string)$user['role'];

if ($page === 'profile') {
    require __DIR__ . '/pages/profile.php';
    exit;
}

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
