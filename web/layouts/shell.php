<?php
declare(strict_types=1);

use QidusAsset\Security\Rbac;
use QidusAsset\Support\EthiopianCalendar;

/**
 * Shared Light/Dark Mode Bilingual Web Shell (`docs/04_UI_UX_DESIGN_SYSTEM.md`).
 *
 * @param array<string,mixed> $user
 * @param callable():void $renderBody
 */
function qaRenderShell(string $pageTitle, string $activePage, array $user, callable $renderBody): void
{
    $lang = qaLang();
    $ethToday = EthiopianCalendar::today((string)qa_config('timezone'));
    $schoolName = qaSchoolTitle();
    $primaryColor = (string)qa_config('theme.primary', '#4cc9f0');
    $accentColor = (string)qa_config('theme.accent', '#7b2cbf');
    $role = (string)$user['role'];
    $canAsset = Rbac::can($role, 'asset.read');
    $canFinance = Rbac::can($role, 'finance.read') || Rbac::can($role, 'document.read');
    $canLeadership = Rbac::can($role, 'system.executive_summary') || Rbac::can($role, 'system.audit_read');
    $displayName = ($lang === 'am' && !empty($user['full_name_am']))
        ? (string)$user['full_name_am']
        : (string)$user['full_name'];
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
          <a class="qa-nav-link <?= $activePage === 'asset' ? 'active' : '' ?>" href="index.php?page=asset">
            <span><?= e(qaT('nav.asset')) ?></span>
            <span class="qa-badge success"><?= e(qaT('badge.dept1')) ?></span>
          </a>
        <?php endif; ?>
        <?php if ($canFinance): ?>
          <a class="qa-nav-link <?= $activePage === 'finance' ? 'active' : '' ?>" href="index.php?page=finance">
            <span><?= e(qaT('nav.finance')) ?></span>
            <span class="qa-badge warning"><?= e(qaT('badge.dept2')) ?></span>
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
      </div>
    </aside>

    <main class="qa-main">
      <header class="qa-topbar">
        <div class="qa-topbar-left">
          <button id="qaMobileMenuBtn" type="button" class="qa-btn qa-mobile-toggle" aria-label="Toggle menu">☰</button>
          <div>
            <h1 class="qa-topbar-title"><?= e($pageTitle) ?></h1>
            <div class="qa-topbar-sub"><?= e(qaT('topbar.mode')) ?>: <?= e((string)qa_config('integration_mode', 'standalone')) ?> · <?= e(qaT('topbar.role')) ?>: <?= e($role) ?></div>
          </div>
        </div>

        <div class="qa-topbar-actions">
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
