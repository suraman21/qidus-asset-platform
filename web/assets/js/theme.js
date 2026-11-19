(function () {
  'use strict';

  var STORAGE_KEY = 'qa_theme';

  function getPreferredTheme() {
    var saved = localStorage.getItem(STORAGE_KEY);
    if (saved === 'light' || saved === 'dark') {
      return saved;
    }
    return 'dark';
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(STORAGE_KEY, theme);
    var btn = document.getElementById('qaThemeToggle');
    if (btn) {
      btn.textContent = theme === 'dark' ? '☀️ Light Mode' : '🌙 Dark Mode';
      btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    applyTheme(getPreferredTheme());

    var themeBtn = document.getElementById('qaThemeToggle');
    if (themeBtn) {
      themeBtn.addEventListener('click', function () {
        var current = document.documentElement.getAttribute('data-theme') || 'dark';
        applyTheme(current === 'dark' ? 'light' : 'dark');
      });
    }

    var menuBtn = document.getElementById('qaMobileMenuBtn');
    var sidebar = document.getElementById('qaSidebar');
    if (menuBtn && sidebar) {
      menuBtn.addEventListener('click', function () {
        sidebar.classList.toggle('open');
      });
    }
  });
})();
