<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\PasswordService;

$pdo = Connection::get();
$uid = (int)$user['uid'];
$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qaVerifyCsrf($_POST['csrf'] ?? null)) {
        $flashErr = qaLang() === 'am' ? 'የደህንነት ቶከን (CSRF) ትክክል አይደለም።' : 'Invalid CSRF token.';
    } else {
        $act = (string)($_POST['profile_action'] ?? '');
        try {
            if ($act === 'update_info') {
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $fullNameAm = trim((string)($_POST['full_name_am'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $prefLang = (string)($_POST['preferred_lang'] ?? 'am');

                if ($fullName === '') {
                    throw new \DomainException(qaLang() === 'am' ? 'ሙሉ ስም መሙላት አለበት።' : 'Full name is required.');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \DomainException(qaLang() === 'am' ? 'የኢሜይል አድራሻው ትክክል አይደለም።' : 'Invalid email format.');
                }

                $pdo->prepare(
                    'UPDATE qa_users SET full_name = :fn, full_name_am = :fa, email = :em WHERE id = :id'
                )->execute([
                    ':fn' => substr($fullName, 0, 150),
                    ':fa' => $fullNameAm !== '' ? substr($fullNameAm, 0, 150) : null,
                    ':em' => $email !== '' ? substr($email, 0, 160) : null,
                    ':id' => $uid,
                ]);
                qaSetLang($prefLang);

                AuditLogger::log($pdo, [
                    'actor_user_id' => $uid,
                    'actor_role' => (string)$user['role'],
                    'action' => 'user.profile_update',
                    'entity_type' => 'user',
                    'entity_id' => (string)$uid,
                    'after' => ['full_name' => $fullName, 'full_name_am' => $fullNameAm, 'lang' => qaLang()],
                ]);

                $user['full_name'] = $fullName;
                $user['full_name_am'] = $fullNameAm !== '' ? $fullNameAm : null;
                $flashOk = qaLang() === 'am' ? 'የግል መገለጫዎ በተሳካ ሁኔታ ተዘምኗል።' : 'Profile details updated successfully.';
            } elseif ($act === 'change_password') {
                $currentPw = (string)($_POST['current_password'] ?? '');
                $newPw = (string)($_POST['new_password'] ?? '');
                $confirmPw = (string)($_POST['confirm_password'] ?? '');

                $pwStmt = $pdo->prepare('SELECT password_hash FROM qa_users WHERE id = :id LIMIT 1');
                $pwStmt->execute([':id' => $uid]);
                $hashRow = $pwStmt->fetch();
                if (!$hashRow || empty($hashRow['password_hash']) || !PasswordService::verify($currentPw, (string)$hashRow['password_hash'])) {
                    throw new \DomainException(qaLang() === 'am' ? 'የአሁኑ የይለፍ ቃል ትክክል አይደለም።' : 'Current password is incorrect.');
                }
                if (strlen($newPw) < 10) {
                    throw new \DomainException(qaLang() === 'am' ? 'አዲሱ የይለፍ ቃል ቢያንስ 10 ፊደላት ሊኖረው ይገባል።' : 'New password must be at least 10 characters.');
                }
                if ($newPw !== $confirmPw) {
                    throw new \DomainException(qaLang() === 'am' ? 'አዲሱ የይለፍ ቃል እና ማረጋገጫው አይመሳሰሉም።' : 'New password and confirmation do not match.');
                }

                $newHash = PasswordService::hash($newPw);
                $pdo->prepare('UPDATE qa_users SET password_hash = :ph, auth_version = auth_version + 1 WHERE id = :id')
                    ->execute([':ph' => $newHash, ':id' => $uid]);

                $avStmt = $pdo->prepare('SELECT auth_version FROM qa_users WHERE id = :id LIMIT 1');
                $avStmt->execute([':id' => $uid]);
                $_SESSION['qa_av'] = (int)$avStmt->fetchColumn();

                AuditLogger::log($pdo, [
                    'actor_user_id' => $uid,
                    'actor_role' => (string)$user['role'],
                    'action' => 'user.change_password',
                    'entity_type' => 'user',
                    'entity_id' => (string)$uid,
                ]);
                $flashOk = qaLang() === 'am' ? 'የይለፍ ቃልዎ ተቀይሯል፤ የቀድሞ ቶከኖች ተሰርዘዋል።' : 'Password updated and previous sessions/tokens rotated.';
            }
        } catch (\Throwable $e) {
            $flashErr = $e->getMessage();
        }
    }
}

$uStmt = $pdo->prepare(
    'SELECT u.username, u.email, u.full_name, u.full_name_am, u.qa_role, u.auth_version, u.last_login_at, u.created_at,
            d.name_en AS dept_en, d.name_am AS dept_am
     FROM qa_users u
     LEFT JOIN qa_departments d ON d.id = u.department_id
     WHERE u.id = :id LIMIT 1'
);
$uStmt->execute([':id' => $uid]);
$me = $uStmt->fetch() ?: [];

$devStmt = $pdo->prepare('SELECT device_uuid, device_label, key_algorithm, status, last_used_at FROM qa_device_keys WHERE user_id = :id ORDER BY id DESC');
$devStmt->execute([':id' => $uid]);
$myDevices = $devStmt->fetchAll();

qaRenderShell(qaT('page.profile'), 'profile', $user, static function () use ($me, $myDevices, $flashOk, $flashErr): void {
    $lang = qaLang();
    ?>
    <?php if ($flashOk !== ''): ?>
      <div class="qa-card" style="border-color:rgba(16,185,129,0.5);margin-bottom:16px;color:var(--accent-success);"><strong>✓</strong> <?= e($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="qa-card" style="border-color:rgba(239,68,68,0.5);margin-bottom:16px;color:var(--accent-danger);"><strong>!</strong> <?= e($flashErr) ?></div>
    <?php endif; ?>

    <section class="qa-panel-grid">
      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title"><?= e(qaT('profile.info_title')) ?></h2>
          <span class="qa-badge info"><?= e((string)($me['qa_role'] ?? '')) ?></span>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
          <input type="hidden" name="profile_action" value="update_info">
          <div class="qa-field">
            <label><?= e(qaT('profile.username')) ?></label>
            <input class="qa-input" value="<?= e((string)($me['username'] ?? '')) ?>" disabled>
          </div>
          <div class="qa-field">
            <label for="pfFullName"><?= e(qaT('profile.full_name_en')) ?></label>
            <input id="pfFullName" name="full_name" class="qa-input" value="<?= e((string)($me['full_name'] ?? '')) ?>" required>
          </div>
          <div class="qa-field">
            <label for="pfFullNameAm"><?= e(qaT('profile.full_name_am')) ?></label>
            <input id="pfFullNameAm" name="full_name_am" class="qa-input" value="<?= e((string)($me['full_name_am'] ?? '')) ?>" placeholder="ምሳሌ፦ ቅዱስ አበበ">
          </div>
          <div class="qa-field">
            <label for="pfEmail"><?= e(qaT('profile.email')) ?></label>
            <input id="pfEmail" name="email" type="email" class="qa-input" value="<?= e((string)($me['email'] ?? '')) ?>" placeholder="name@example.org">
          </div>
          <div class="qa-field">
            <label for="pfLang"><?= e(qaT('profile.language')) ?></label>
            <select id="pfLang" name="preferred_lang" class="qa-select" style="width:100%;">
              <option value="am" <?= $lang === 'am' ? 'selected' : '' ?>>አማርኛ (Amharic — Default)</option>
              <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
            </select>
          </div>
          <button type="submit" class="qa-btn qa-btn-primary"><?= e(qaT('profile.save_btn')) ?></button>
        </form>
      </div>

      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title"><?= e(qaT('profile.pw_title')) ?></h2>
          <span class="qa-badge warning">v<?= (int)($me['auth_version'] ?? 1) ?></span>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
          <input type="hidden" name="profile_action" value="change_password">
          <div class="qa-field">
            <label for="pwCur"><?= e(qaT('profile.pw_current')) ?></label>
            <input id="pwCur" name="current_password" type="password" class="qa-input" required autocomplete="current-password">
          </div>
          <div class="qa-field">
            <label for="pwNew"><?= e(qaT('profile.pw_new')) ?></label>
            <input id="pwNew" name="new_password" type="password" class="qa-input" minlength="10" required autocomplete="new-password">
          </div>
          <div class="qa-field">
            <label for="pwConf"><?= e(qaT('profile.pw_confirm')) ?></label>
            <input id="pwConf" name="confirm_password" type="password" class="qa-input" minlength="10" required autocomplete="new-password">
          </div>
          <button type="submit" class="qa-btn qa-btn-primary"><?= e(qaT('profile.pw_btn')) ?></button>
        </form>
      </div>
    </section>

    <section class="qa-panel-grid">
      <div class="qa-card">
        <div class="qa-card-header"><h2 class="qa-card-title"><?= e(qaT('profile.meta_title')) ?></h2></div>
        <div class="qa-table-wrap">
          <table class="qa-table">
            <tbody>
              <tr><th><?= e(qaT('profile.username')) ?></th><td><code><?= e((string)($me['username'] ?? '')) ?></code></td></tr>
              <tr><th><?= e(qaT('topbar.role')) ?></th><td><span class="qa-badge info"><?= e((string)($me['qa_role'] ?? '')) ?></span></td></tr>
              <tr><th><?= e(qaT('nav.departments')) ?></th><td><?= e($lang === 'am' && !empty($me['dept_am']) ? (string)$me['dept_am'] : (string)($me['dept_en'] ?? '—')) ?></td></tr>
              <tr><th>Last Login</th><td class="tabular-nums"><?= e((string)($me['last_login_at'] ?? '—')) ?></td></tr>
              <tr><th>Created</th><td class="tabular-nums"><?= e((string)($me['created_at'] ?? '—')) ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="qa-card">
        <div class="qa-card-header">
          <h2 class="qa-card-title"><?= e(qaT('profile.devices_title')) ?></h2>
          <span class="qa-badge info"><?= count($myDevices) ?></span>
        </div>
        <?php if ($myDevices === []): ?>
          <p style="color:var(--text-secondary);margin:0;">
            <?= $lang === 'am' ? 'እስካሁን የተመዘገበ የአንድሮይድ መሣሪያ የለም።' : 'No Android Keystore devices registered for your account yet.' ?>
          </p>
        <?php else: ?>
          <div class="qa-table-wrap">
            <table class="qa-table">
              <thead><tr><th>Device</th><th>Alg</th><th>Status</th><th>Last Used</th></tr></thead>
              <tbody>
                <?php foreach ($myDevices as $d): ?>
                  <tr>
                    <td><code><?= e($d['device_uuid']) ?></code><div style="font-size:11px;color:var(--text-secondary);"><?= e($d['device_label']) ?></div></td>
                    <td><?= e($d['key_algorithm']) ?></td>
                    <td><span class="qa-badge <?= $d['status'] === 'active' ? 'success' : ($d['status'] === 'revoked' ? 'danger' : 'warning') ?>"><?= e($d['status']) ?></span></td>
                    <td class="tabular-nums"><?= e((string)($d['last_used_at'] ?? '—')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>
    <?php
});
