<?php
declare(strict_types=1);

$schoolName = (string)qa_config('school_name_en', 'QIDUS ASSET');
$primaryColor = (string)qa_config('theme.primary', '#4cc9f0');
$accentColor = (string)qa_config('theme.accent', '#7b2cbf');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In · <?= e($schoolName) ?></title>
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/app.css">
  <style>
    :root { --school-primary: <?= e($primaryColor) ?>; --school-accent: <?= e($accentColor) ?>; }
  </style>
</head>
<body>
  <div class="qa-auth-wrap">
    <div class="qa-auth-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
        <div class="qa-brand" style="border-bottom:none;margin-bottom:0;padding:0;">
          <div class="qa-brand-mark">QA</div>
          <div>
            <div class="qa-brand-title"><?= e($schoolName) ?></div>
            <div class="qa-brand-sub">Asset, Finance, Procurement &amp; Vault</div>
          </div>
        </div>
        <button id="qaThemeToggle" type="button" class="qa-btn">☀️ Light Mode</button>
      </div>

      <?php if (!empty($loginError)): ?>
        <div style="background:rgba(239,68,68,0.14);border:1px solid rgba(239,68,68,0.45);padding:10px 12px;border-radius:10px;margin-bottom:14px;color:var(--accent-danger);">
          <?= e($loginError) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="index.php?action=login">
        <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
        <div class="qa-field">
          <label for="qaUsername">Username</label>
          <input id="qaUsername" name="username" type="text" class="qa-input" required autocomplete="username" placeholder="Enter your username">
        </div>
        <div class="qa-field">
          <label for="qaPassword">Password</label>
          <input id="qaPassword" name="password" type="password" class="qa-input" required autocomplete="current-password" placeholder="••••••••">
        </div>
        <button type="submit" class="qa-btn qa-btn-primary" style="width:100%;padding:11px 16px;">Sign In</button>
      </form>

      <div style="margin-top:16px;font-size:12px;color:var(--text-secondary);text-align:center;">
        "Every Birr, Every Asset, Every Document — Accounted For."
      </div>
    </div>
  </div>
  <script src="assets/js/theme.js"></script>
</body>
</html>
