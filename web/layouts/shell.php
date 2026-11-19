<?php
declare(strict_types=1);

use QidusAsset\Security\Rbac;
use QidusAsset\Support\EthiopianCalendar;

/**
 * Shared Light/Dark Mode Web Shell (`docs/04_UI_UX_DESIGN_SYSTEM.md`).
 *
 * @param array<string,mixed> $user
 * @param callable():void $renderBody
 */
function qaRenderShell(string $pageTitle, string $activePage, array $user, callable $renderBody): void
{
    $ethToday = EthiopianCalendar::today((string)qa_config('timezone'));
    $schoolName = (string)qa_config('school_name_en', 'QIDUS ASSET');
    $primaryColor = (string)qa_config('theme.primary', '#4cc9f0');
    $accentColor = (string)qa_config('theme.accent', '#7b2cbf');
    $role = (string)$user['role'];
    $canAsset = Rbac::can($role, 'asset.read');
    $canFinance = Rbac::can($role, 'finance.read') || Rbac::can($role, 'document.read');
    $canLeadership = Rbac::can($role, 'system.executive_summary') || Rbac::can($role, 'system.audit_read');
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
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
        <div>
          <div class="qa-brand-title"><?= e($schoolName) ?></div>
          <div class="qa-brand-sub">Every Birr, Asset &amp; Document</div>
        </div>
      </div>

      <div class="qa-nav-group">
        <div class="qa-nav-label">Departments</div>
        <?php if ($canLeadership): ?>
          <a class="qa-nav-link <?= $activePage === 'leadership' ? 'active' : '' ?>" href="index.php?page=leadership">
            <span>Executive &amp; Audit</span>
            <span class="qa-badge info">Gov</span>
          </a>
        <?php endif; ?>
        <?php if ($canAsset): ?>
          <a class="qa-nav-link <?= $activePage === 'asset' ? 'active' : '' ?>" href="index.php?page=asset">
            <span>Asset Department</span>
            <span class="qa-badge success">Dept 1</span>
          </a>
        <?php endif; ?>
        <?php if ($canFinance): ?>
          <a class="qa-nav-link <?= $activePage === 'finance' ? 'active' : '' ?>" href="index.php?page=finance">
            <span>Finance, Proc. &amp; Docs</span>
            <span class="qa-badge warning">Dept 2</span>
          </a>
        <?php endif; ?>
      </div>

      <div class="qa-nav-group">
        <div class="qa-nav-label">Session</div>
        <div class="qa-nav-link" style="cursor:default;">
          <span><?= e($user['full_name']) ?></span>
          <span class="qa-badge"><?= e($role) ?></span>
        </div>
        <form method="post" action="index.php?action=logout" style="margin-top:8px;">
          <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
          <button type="submit" class="qa-btn" style="width:100%;">Sign Out</button>
        </form>
      </div>

      <div class="qa-sidebar-footer">
        <div><strong><?= e($ethToday['formatted_en']) ?></strong></div>
        <div><?= e($ethToday['formatted_am']) ?></div>
      </div>
    </aside>

    <main class="qa-main">
      <header class="qa-topbar">
        <div class="qa-topbar-left">
          <button id="qaMobileMenuBtn" type="button" class="qa-btn qa-mobile-toggle" aria-label="Toggle menu">☰</button>
          <div>
            <h1 class="qa-topbar-title"><?= e($pageTitle) ?></h1>
            <div class="qa-topbar-sub">Mode: <?= e((string)qa_config('integration_mode', 'standalone')) ?> · Role: <?= e($role) ?></div>
          </div>
        </div>

        <div class="qa-topbar-actions">
          <?php if ($user['base_role'] === 'system_admin'): ?>
            <form method="post" action="index.php?action=switch_role" style="display:flex;gap:6px;align-items:center;">
              <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
              <select name="target_role" class="qa-select" aria-label="Switch acting role">
                <?php foreach (['system_admin', 'asset_head', 'asset_officer', 'finance_head', 'finance_staff', 'procurement_officer', 'document_controller', 'auditor', 'chairperson', 'deputy_chairperson'] as $r): ?>
                  <option value="<?= e($r) ?>" <?= $r === $role ? 'selected' : '' ?>>View as: <?= e($r) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="qa-btn">Switch</button>
            </form>
          <?php endif; ?>

          <button id="qaThemeToggle" type="button" class="qa-btn">☀️ Light Mode</button>
        </div>
      </header>

      <div class="qa-content">
        <?php if (!empty($user['is_impersonating'])): ?>
          <div class="qa-banner-impersonation">
            <div>
              <strong>Impersonation Mode Active:</strong> Viewing platform as <code><?= e($role) ?></code> (audited).
            </div>
            <form method="post" action="index.php?action=switch_role">
              <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
              <input type="hidden" name="target_role" value="system_admin">
              <button type="submit" class="qa-btn">Return to system_admin</button>
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
