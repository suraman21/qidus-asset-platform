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
      var lightLabel = btn.getAttribute('data-label-light') || '☀️ Light Mode';
      var darkLabel = btn.getAttribute('data-label-dark') || '🌙 Dark Mode';
      btn.textContent = theme === 'dark' ? lightLabel : darkLabel;
      btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    }
  }

  function initSignaturePads() {
    var pads = document.querySelectorAll('[data-sig-pad]');
    pads.forEach(function (canvas) {
      var targetId = canvas.getAttribute('data-sig-pad');
      var hidden = document.getElementById(targetId);
      if (!canvas.getContext || !hidden) {
        return;
      }
      var ctx = canvas.getContext('2d');
      ctx.lineWidth = 2;
      ctx.lineCap = 'round';
      ctx.strokeStyle = '#0f172a';
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      var drawing = false;
      function pos(e) {
        var r = canvas.getBoundingClientRect();
        var clientX = e.touches ? e.touches[0].clientX : e.clientX;
        var clientY = e.touches ? e.touches[0].clientY : e.clientY;
        return {
          x: (clientX - r.left) * (canvas.width / r.width),
          y: (clientY - r.top) * (canvas.height / r.height)
        };
      }
      function start(e) {
        drawing = true;
        var p = pos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        e.preventDefault();
      }
      function move(e) {
        if (!drawing) {
          return;
        }
        var p = pos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        hidden.value = canvas.toDataURL('image/png');
        e.preventDefault();
      }
      function end() {
        if (drawing) {
          drawing = false;
          hidden.value = canvas.toDataURL('image/png');
        }
      }
      canvas.addEventListener('mousedown', start);
      canvas.addEventListener('mousemove', move);
      window.addEventListener('mouseup', end);
      canvas.addEventListener('touchstart', start, { passive: false });
      canvas.addEventListener('touchmove', move, { passive: false });
      canvas.addEventListener('touchend', end);

      var clearBtn = document.querySelector('[data-sig-clear="' + targetId + '"]');
      if (clearBtn) {
        clearBtn.addEventListener('click', function () {
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0, 0, canvas.width, canvas.height);
          hidden.value = '';
        });
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    applyTheme(getPreferredTheme());
    initSignaturePads();

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
