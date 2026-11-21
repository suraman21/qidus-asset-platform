<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\PasswordService;

$pdo = Connection::get();
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM qa_users')->fetchColumn();
if ($userCount > 0) {
    header('Location: index.php?page=login');
    exit;
}

$setupErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qaVerifyCsrf($_POST['csrf'] ?? null)) {
        $setupErr = 'Invalid CSRF token.';
    } else {
        $schoolEn = trim((string)($_POST['school_name_en'] ?? 'Sunday School'));
        $schoolAm = trim((string)($_POST['school_name_am'] ?? 'ሰንበት ትምህርት ቤት'));
        $modeRaw = (string)($_POST['integration_mode'] ?? 'standalone');
        $mode = in_array($modeRaw, ['standalone', 'ssms_direct', 'ssms_http'], true) ? $modeRaw : 'standalone';
        $username = trim((string)($_POST['username'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $fullName === '' || strlen($password) < 8) {
            $setupErr = 'Admin username, full name, and password (min 8 chars) are required.';
        } else {
            $pdo->prepare(
                'UPDATE qa_install_config SET school_name_en = :se, school_name_am = :sa, integration_mode = :im WHERE id = 1'
            )->execute([':se' => $schoolEn, ':sa' => $schoolAm, ':im' => $mode]);

            $hash = PasswordService::hash($password);
            $pdo->prepare(
                "INSERT INTO qa_users (source_system, username, full_name, qa_role, department_id, password_hash, is_active)
                 VALUES ('standalone', :u, :fn, 'system_admin', 1, :ph, 1)"
            )->execute([':u' => $username, ':fn' => $fullName, ':ph' => $hash]);
            $uid = (int)$pdo->lastInsertId();

            AuditLogger::log($pdo, [
                'actor_user_id' => $uid,
                'actor_role' => 'system_admin',
                'action' => 'system.first_run_setup',
                'entity_type' => 'install_config',
                'entity_id' => '1',
                'after' => ['school_name_en' => $schoolEn, 'integration_mode' => $mode],
            ]);

            header('Location: index.php?page=login&setup=complete');
            exit;
        }
    }
}

$lang = qaLang();
$schoolName = qaSchoolTitle();
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(qaT('setup.title')) ?> · <?= e($schoolName) ?></title>
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <div class="qa-auth-wrap">
    <div class="qa-auth-card" style="max-width:520px;">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
        <div>
          <div class="qa-brand-title"><?= e(qaT('setup.title')) ?></div>
          <div class="qa-brand-sub"><?= e(qaT('setup.subtitle')) ?></div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
          <div class="qa-lang-switch" role="group" aria-label="Language switcher">
            <a class="qa-lang-pill <?= $lang === 'am' ? 'active' : '' ?>" href="<?= e(qaLangSwitchUrl('am')) ?>">አማ</a>
            <a class="qa-lang-pill <?= $lang === 'en' ? 'active' : '' ?>" href="<?= e(qaLangSwitchUrl('en')) ?>">EN</a>
          </div>
          <button id="qaThemeToggle"
                  type="button"
                  class="qa-btn"
                  data-label-light="<?= e(qaT('topbar.light_mode')) ?>"
                  data-label-dark="<?= e(qaT('topbar.dark_mode')) ?>"><?= e(qaT('topbar.light_mode')) ?></button>
        </div>
      </div>

      <?php if ($setupErr !== ''): ?>
        <div style="background:rgba(239,68,68,0.14);border:1px solid rgba(239,68,68,0.45);padding:10px;border-radius:10px;margin-bottom:12px;color:var(--accent-danger);">
          <?= e($setupErr) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="index.php?page=setup">
        <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
        <div class="qa-field">
          <label for="sEn"><?= e(qaT('setup.school_en')) ?></label>
          <input id="sEn" name="school_name_en" class="qa-input" required value="Sunday School">
        </div>
        <div class="qa-field">
          <label for="sAm"><?= e(qaT('setup.school_am')) ?></label>
          <input id="sAm" name="school_name_am" class="qa-input" value="ሰንበት ትምህርት ቤት">
        </div>
        <div class="qa-field">
          <label for="sMode"><?= e(qaT('setup.mode')) ?></label>
          <select id="sMode" name="integration_mode" class="qa-select" style="width:100%;">
            <option value="standalone">Standalone (Independent Directory)</option>
            <option value="ssms_http">SSMS HTTP Connector</option>
            <option value="ssms_direct">SSMS Direct Colocated DB</option>
          </select>
        </div>
        <div class="qa-field">
          <label for="aUser"><?= e(qaT('setup.admin_user')) ?></label>
          <input id="aUser" name="username" class="qa-input" required placeholder="sysadmin">
        </div>
        <div class="qa-field">
          <label for="aName"><?= e(qaT('setup.admin_name')) ?></label>
          <input id="aName" name="full_name" class="qa-input" required placeholder="Administrator">
        </div>
        <div class="qa-field">
          <label for="aPass"><?= e(qaT('setup.admin_pass')) ?></label>
          <input id="aPass" name="password" type="password" minlength="8" class="qa-input" required>
        </div>
        <button type="submit" class="qa-btn qa-btn-primary" style="width:100%;"><?= e(qaT('setup.submit')) ?></button>
      </form>
    </div>
  </div>
  <script src="assets/js/theme.js"></script>
</body>
</html>
