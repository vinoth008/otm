// Shared Dark/Light Theme Toggle for main app pages
(function () {
  var btn = document.getElementById('themeBtn');
  // Auto-create a floating toggle button if the page has no themeBtn
  if (!btn) {
    btn = document.createElement('button');
    btn.id = 'themeBtn';
    btn.title = 'Toggle light/dark mode';
    btn.style.cssText = 'position:fixed;top:16px;right:16px;z-index:99999;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;font-size:16px;cursor:pointer;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.25);color:#fff;';
    document.body.appendChild(btn);
  }
  var isLight = localStorage.getItem('sot-theme') === 'light';
  function apply() {
    document.body.classList.toggle('light', isLight);
    btn.innerHTML = isLight ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
    btn.style.background = isLight ? 'rgba(15,23,42,0.08)' : 'rgba(255,255,255,0.12)';
    btn.style.border = isLight ? '1px solid rgba(15,23,42,0.2)' : '1px solid rgba(255,255,255,0.25)';
    btn.style.color = isLight ? '#0f172a' : '#fff';
  }
  btn.addEventListener('click', function () {
    isLight = !document.body.classList.contains('light');
    localStorage.setItem('sot-theme', isLight ? 'light' : 'dark');
    apply();
  });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply);
  } else {
    apply();
  }
})();
