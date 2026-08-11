/**
 * app.js — Global utilities, auth guard, sidebar, toast
 * Secure Online Transaction System
 */

// ── Constants ──────────────────────────────────────────────────
const APP_NAME    = 'SecureSOT';
const STORAGE_KEY = 'sot_user';

// ── Role → dashboard URL map ────────────────────────────────────
const ROLE_DASHBOARD = {
  admin:        '../html/admin/dashboard.html',
  staff:        '../html/staff/dashboard.html',
  receptionist: '../html/receptionist/dashboard.html',
  customer:     '../html/customer/dashboard.html',
};

// ── Auth helpers (backed by real backend session) ───────────────
const Auth = {
  login(user) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...user, loginAt: Date.now() }));
  },
  logout() {
    // Call backend logout endpoint
    fetch(`${API_BASE}?module=auth&action=logout`, { method: 'POST', credentials: 'same-origin' })
      .catch(() => {});
    localStorage.removeItem(STORAGE_KEY);
    window.location.href = getAuthPath('role-select.html');
  },
  getUser() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY)); } catch { return null; }
  },
  isLoggedIn() { return !!this.getUser(); },
  requireRole(allowed) {
    const u = this.getUser();
    if (!u) { this.logout(); return; }
    if (!allowed.includes(u.role)) { this.logout(); return; }
    return u;
  }
};

// ── Path helpers ─────────────────────────────────────────────────
function getAuthPath(page) {
  const depth = (location.pathname.match(/\//g)||[]).length;
  const prefix = depth > 1 ? '../auth/' : 'auth/';
  return prefix + page;
}

// ── Toast Notifications ──────────────────────────────────────────
function showToast(message, type = 'info', duration = 3500) {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
  const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
  const colors = { success:'var(--success)', error:'var(--danger)', warning:'var(--warning)', info:'var(--info)' };

  const toast = document.createElement('div');
  toast.className = `toast-custom ${type}`;
  toast.innerHTML = `
    <i class="fa-solid ${icons[type]||icons.info}" style="color:${colors[type]||colors.info};font-size:1.1rem;flex-shrink:0"></i>
    <span style="flex:1;font-size:0.875rem;color:var(--text-primary)">${message}</span>
    <button onclick="this.parentElement.remove()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0;font-size:0.8rem"><i class="fa-solid fa-xmark"></i></button>
  `;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), duration);
}

// ── Sidebar Toggle ───────────────────────────────────────────────
function initSidebar() {
  const sidebar  = document.getElementById('sidebar');
  const toggle   = document.getElementById('sidebar-toggle');
  const overlay  = document.getElementById('sidebar-overlay');
  if (!sidebar || !toggle) return;

  toggle.addEventListener('click', () => {
    if (window.innerWidth <= 768) {
      sidebar.classList.toggle('mobile-open');
      overlay && overlay.classList.toggle('active');
    } else {
      sidebar.classList.toggle('collapsed');
      const mc = document.querySelector('.main-content');
      if (mc) mc.style.marginLeft = sidebar.classList.contains('collapsed') ? '0' : 'var(--sidebar-w)';
    }
  });

  overlay && overlay.addEventListener('click', () => {
    sidebar.classList.remove('mobile-open');
    overlay.classList.remove('active');
  });
}

// ── Active nav link ──────────────────────────────────────────────
function setActiveNav() {
  const cur = location.pathname.split('/').pop();
  document.querySelectorAll('.nav-link-custom').forEach(link => {
    const href = link.getAttribute('href') || '';
    if (href.endsWith(cur)) link.classList.add('active');
    else link.classList.remove('active');
  });
}

// ── Render user in topbar/sidebar ───────────────────────────────
function renderUser() {
  const u = Auth.getUser();
  if (!u) return;
  document.querySelectorAll('[data-user-name]').forEach(el => el.textContent = u.name);
  document.querySelectorAll('[data-user-role]').forEach(el => el.textContent = capitalise(u.role));
  document.querySelectorAll('[data-user-avatar]').forEach(el => el.textContent = u.avatar || u.name.slice(0,2).toUpperCase());
  document.querySelectorAll('[data-user-email]').forEach(el => el.textContent = u.email);
}

// ── Logout buttons ───────────────────────────────────────────────
function initLogout() {
  document.querySelectorAll('[data-logout]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (confirm('Are you sure you want to logout?')) Auth.logout();
    });
  });
}

// ── Format helpers ───────────────────────────────────────────────
function formatCurrency(n) { return '₹' + Number(n).toLocaleString('en-IN', {minimumFractionDigits:2}); }
function formatDate(d) { return new Date(d).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'}); }
function formatDateTime(d) { return new Date(d).toLocaleString('en-IN', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
function capitalise(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
function randomBetween(min,max) { return Math.floor(Math.random()*(max-min+1))+min; }

// ── Confirm dialog ───────────────────────────────────────────────
function confirmAction(msg, onConfirm) {
  if (confirm(msg)) onConfirm();
}

// ── DOMContentLoaded init ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initSidebar();
  setActiveNav();
  renderUser();
  initLogout();
});