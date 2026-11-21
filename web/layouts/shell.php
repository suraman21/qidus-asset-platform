<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;
use QidusAsset\Security\Rbac;
use QidusAsset\Support\EthiopianCalendar;

/**
 * Shared Light/Dark Mode Bilingual Web Shell (`docs/04_UI_UX_DESIGN_SYSTEM.md` & `docs/07`).
 *
 * @param array<string,mixed> $user
 * @param callable():void $renderBody
 */
function qaRenderShell(string $pageTitle, string $activePage, array $user, callable $renderBody): void
{
    $lang = qaLang();
    $ethToday = EthiopianCalendar::today((string)qa_config('timezone'));
    $schoolName = qaSchoolTitle();
    $primaryColor = (string)qa_config('theme.primary', '#3b82f6');
    $accentColor = (string)qa_config('theme.accent', '#f59e0b');
    $role = (string)$user['role'];
    $canAsset = Rbac::can($role, 'asset.read');
    $canFinance = Rbac::can($role, 'finance.read') || Rbac::can($role, 'document.read');
    $canLeadership = Rbac::can($role, 'system.executive_summary') || Rbac::can($role, 'system.audit_read');
    $displayName = ($lang === 'am' && !empty($user['full_name_am']))
        ? (string)$user['full_name_am']
        : (string)$user['full_name'];
    $activeTab = (string)($_GET['tab'] ?? '');
    $activeQuery = (string)($_GET['q'] ?? '');

    // Load active operational year & user notifications safely
    $activeYearLabel = $ethToday['year'] . ' EC';
    $notifications = [];
    $unreadCount = 0;
    try {
        $pdo = Connection::get();
        $yr = $pdo->query("SELECT label_en, label_am FROM qa_operational_years WHERE status = 'active' ORDER BY ec_year DESC LIMIT 1")->fetch();
        if ($yr) {
            $activeYearLabel = $lang === 'am' && !empty($yr['label_am']) ? (string)$yr['label_am'] : (string)$yr['label_en'];
        }
        $nStmt = $pdo->prepare(
            'SELECT id, type, title, message, is_read, created_at
             FROM qa_notifications
             WHERE recipient_user_id = :u
             ORDER BY is_read ASC, id DESC
             LIMIT 10'
        );
        $nStmt->execute([':u' => (int)$user['uid']]);
        $notifications = $nStmt->fetchAll();
        foreach ($notifications as $n) {
            if ((int)$n['is_read'] === 0) {
                $unreadCount++;
            }
        }
    } catch (\Throwable $e) {
        // Non-fatal fallback
    }

    $searchTargetPage = ($activePage === 'finance' || (!$canAsset && $canFinance)) ? 'finance' : 'asset';
    $searchTargetTab = $searchTargetPage === 'finance' ? 'documents' : 'inventory';
    $currentUri = 'index.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string)$_SERVER['QUERY_STRING'] : '');
    ?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> · <?= e($schoolName) ?></title>
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/app.css">
  <style>
    :root { --school-primary: <?= e($primaryColor) ?>; --school-accent: <?= e($accentColor) ?>; }
  </style>
</head>
<body>
  <div class="qa-shell">
    <aside id="qaSidebar" class="qa-sidebar" aria-label="Primary Navigation">
      <div class="qa-brand">
        <div class="qa-brand-mark">QA</div>
        <div style="min-width:0;">
          <div class="qa-brand-title"><?= e($schoolName) ?></div>
          <div class="qa-brand-sub"><?= e(qaT('brand.sub')) ?></div>
        </div>
      </div>

      <div class="qa-nav-group">
        <div class="qa-nav-label"><?= e(qaT('nav.departments')) ?></div>
        <?php if ($canLeadership): ?>
          <a class="qa-nav-link <?= $activePage === 'leadership' ? 'active' : '' ?>" href="index.php?page=leadership">
            <span><?= e(qaT('nav.executive')) ?></span>
            <span class="qa-badge info"><?= e(qaT('badge.gov')) ?></span>
          </a>
        <?php endif; ?>
        <?php if ($canAsset): ?>
          <a class="qa-nav-link <?= $activePage === 'asset' && ($activeTab === '' || $activeTab === 'inventory') ? 'active' : '' ?>" href="index.php?page=asset">
            <span><?= e(qaT('nav.asset')) ?></span>
            <span class="qa-badge success"><?= e(qaT('badge.dept1')) ?></span>
          </a>
          <a class="qa-nav-link qa-nav-sub <?= $activePage === 'asset' && $activeTab === 'handovers' ? 'active' : '' ?>" href="index.php?page=asset&amp;tab=handovers">
            <span><?= e(qaT('task.custody')) ?></span>
          </a>
          <a class="qa-nav-link qa-nav-sub <?= $activePage === 'asset' && $activeTab === 'requests' ? 'active' : '' ?>" href="index.php?page=asset&amp;tab=requests">
            <span><?= e(qaT('task.requests')) ?></span>
          </a>
          <a class="qa-nav-link qa-nav-sub <?= $activePage === 'asset' && $activeTab === 'events' ? 'active' : '' ?>" href="index.php?page=asset&amp;tab=events">
            <span><?= e(qaT('task.events')) ?></span>
          </a>
          <a class="qa-nav-link qa-nav-sub <?= $activePage === 'asset' && $activeTab === 'maintenance' ? 'active' : '' ?>" href="index.php?page=asset&amp;tab=maintenance">
            <span><?= e(qaT('task.maintenance')) ?></span>
          </a>
          <a class="qa-nav-link qa-nav-sub <?= $activePage === 'asset' && $activeTab === 'reports' ? 'active' : '' ?>" href="index.php?page=asset&amp;tab=reports">
            <span><?= e(qaT('task.reports')) ?></span>
          </a>
        <?php endif; ?>
        <?php if ($canFinance): ?>
          <a class="qa-nav-link <?= $activePage === 'finance' && ($activeTab === '' || $activeTab === 'ledger') ? 'active' : '' ?>" href="index.php?page=finance">
            <span><?= e(qaT('nav.finance')) ?></span>
            <span class="qa-badge warning"><?= e(qaT('badge.dept2')) ?></span>
          </a>
          <a class="qa-nav-link qa-nav-sub <?= $activePage === 'finance' && $activeTab === 'budgets' ? 'active' : '' ?>" href="index.php?page=finance&amp;tab=budgets">
            <span><?= e(qaT('task.budgets')) ?></span>
          </a>
          <a class="qa-nav-link qa-nav-sub <?= $activePage === 'finance' && $activeTab === 'reconcile' ? 'active' : '' ?>" href="index.php?page=finance&amp;tab=reconcile">
            <span><?= e(qaT('task.reconcile')) ?></span>
          </a>
          <a class="qa-nav-link qa-nav-sub <?= $activePage === 'finance' && $activeTab === 'procurement' ? 'active' : '' ?>" href="index.php?page=finance&amp;tab=procurement">
            <span><?= e(qaT('task.procurement')) ?></span>
          </a>
          <a class="qa-nav-link qa-nav-sub <?= $activePage === 'finance' && $activeTab === 'documents' ? 'active' : '' ?>" href="index.php?page=finance&amp;tab=documents">
            <span><?= e(qaT('task.vault')) ?></span>
          </a>
        <?php endif; ?>
      </div>

      <div class="qa-nav-group">
        <div class="qa-nav-label"><?= e(qaT('nav.account')) ?></div>
        <a class="qa-nav-link <?= $activePage === 'profile' ? 'active' : '' ?>" href="index.php?page=profile">
          <span><?= e($displayName) ?></span>
          <span class="qa-badge"><?= e(qaT('badge.profile')) ?></span>
        </a>
        <div style="padding:2px 12px 6px;font-size:11px;color:var(--text-muted);">
          <?= e(qaT('topbar.role')) ?>: <code><?= e($role) ?></code>
        </div>
        <form method="post" action="index.php?action=logout" style="margin-top:6px;">
          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
          <button type="submit" class="qa-btn" style="width:100%;"><?= e(qaT('nav.signout')) ?></button>
        </form>
      </div>

      <div class="qa-sidebar-footer">
        <div><strong><?= e($ethToday['formatted_am']) ?></strong></div>
        <div><?= e($ethToday['formatted_en']) ?></div>
        <div style="margin-top:4px;"><span class="qa-badge info"><?= e(qaT('topbar.year_active')) ?>: <?= e($activeYearLabel) ?></span></div>
      </div>
    </aside>

    <main class="qa-main">
      <header class="qa-topbar">
        <div class="qa-topbar-left">
          <button id="qaMobileMenuBtn" type="button" class="qa-btn qa-mobile-toggle" aria-label="Toggle menu">☰</button>
          <div>
            <h1 class="qa-topbar-title"><?= e($pageTitle) ?></h1>
            <div class="qa-topbar-sub"><?= e(qaT('topbar.mode')) ?>: <?= e((string)qa_config('integration_mode', 'standalone')) ?> · <?= e(qaT('topbar.role')) ?>: <?= e($role) ?> · <?= e($ethToday['formatted_am']) ?></div>
          </div>
        </div>

        <?php if ($canAsset || $canFinance): ?>
          <form method="get" action="index.php" class="qa-global-search">
            <input type="hidden" name="page" value="<?= e($searchTargetPage) ?>">
            <input type="hidden" name="tab" value="<?= e($searchTargetTab) ?>">
            <input type="search" name="q" value="<?= e($activeQuery) ?>" class="qa-input" placeholder="<?= e(qaT('topbar.search_ph')) ?>" aria-label="<?= e(qaT('topbar.search_ph')) ?>">
            <button type="submit" class="qa-btn"><?= e(qaT('topbar.search_btn')) ?></button>
          </form>
        <?php endif; ?>

        <div class="qa-topbar-actions">
          <div class="qa-notif-wrap">
            <button id="qaNotifToggle" type="button" class="qa-btn" aria-expanded="false" aria-label="<?= e(qaT('topbar.notif')) ?>">
              🔔 <?php if ($unreadCount > 0): ?><span class="qa-badge warning"><?= $unreadCount ?></span><?php endif; ?>
            </button>
            <div id="qaNotifPanel" class="qa-notif-panel" role="region" aria-label="<?= e(qaT('topbar.notif')) ?>">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <strong><?= e(qaT('topbar.notif')) ?></strong>
                <?php if ($unreadCount > 0): ?>
                  <form method="post" action="index.php?action=mark_notif_read" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
                    <input type="hidden" name="notification_id" value="0">
                    <input type="hidden" name="return_to" value="<?= e($currentUri) ?>">
                    <button type="submit" class="qa-btn" style="padding:4px 8px;font-size:11px;"><?= e(qaT('topbar.notif_mark_all')) ?></button>
                  </form>
                <?php endif; ?>
              </div>
              <?php if ($notifications === []): ?>
                <div style="font-size:12px;color:var(--text-muted);"><?= e(qaT('topbar.notif_empty')) ?></div>
              <?php else: ?>
                <?php foreach ($notifications as $n): ?>
                  <div class="qa-notif-item <?= (int)$n['is_read'] === 0 ? 'unread' : '' ?>">
                    <div style="font-weight:700;"><?= e((string)$n['title']) ?></div>
                    <div style="color:var(--text-secondary);"><?= e((string)$n['message']) ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <button id="qaPrintBtn" type="button" class="qa-btn" title="<?= e(qaT('topbar.print')) ?>">🖨️ <?= e(qaT('topbar.print')) ?></button>

          <div class="qa-lang-switch" role="group" aria-label="Language switcher">
            <a class="qa-lang-pill <?= $lang === 'am' ? 'active' : '' ?>" href="<?= e(qaLangSwitchUrl('am')) ?>">አማርኛ</a>
            <a class="qa-lang-pill <?= $lang === 'en' ? 'active' : '' ?>" href="<?= e(qaLangSwitchUrl('en')) ?>">EN</a>
          </div>

          <?php if ($user['base_role'] === 'system_admin'): ?>
            <form method="post" action="index.php?action=switch_role" style="display:flex;gap:6px;align-items:center;">
              <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
              <select name="target_role" class="qa-select" aria-label="Switch acting role">
                <?php foreach (['system_admin', 'asset_head', 'asset_officer', 'finance_head', 'finance_staff', 'procurement_officer', 'document_controller', 'auditor', 'chairperson', 'deputy_chairperson'] as $r): ?>
                  <option value="<?= e($r) ?>" <?= $r === $role ? 'selected' : '' ?>><?= e(qaT('topbar.view_as')) ?> <?= e($r) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="qa-btn"><?= e(qaT('topbar.switch')) ?></button>
            </form>
          <?php endif; ?>

          <button id="qaThemeToggle"
                  type="button"
                  class="qa-btn"
                  data-label-light="<?= e(qaT('topbar.light_mode')) ?>"
                  data-label-dark="<?= e(qaT('topbar.dark_mode')) ?>"><?= e(qaT('topbar.light_mode')) ?></button>
        </div>
      </header>

      <div class="qa-content">
        <?php if (!empty($user['is_impersonating'])): ?>
          <div class="qa-banner-impersonation">
            <div>
              <strong><?= e(qaT('topbar.impersonating')) ?></strong> <code><?= e($role) ?></code>
            </div>
            <form method="post" action="index.php?action=switch_role">
              <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
              <input type="hidden" name="target_role" value="system_admin">
              <button type="submit" class="qa-btn"><?= e(qaT('topbar.return_admin')) ?></button>
            </form>
          </div>
        <?php endif; ?>

        <?php $renderBody(); ?>
      </div>
    </main>
  </div>
  <script src="assets/js/theme.js"></script>
</body>
</html>
    <?php
}
