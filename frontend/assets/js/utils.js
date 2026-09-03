/**
 * utils.js — Shared utility functions for SecureSOT frontend
 */

// ── Format Helpers ───────────────────────────────────────────────
function formatCurrency(amount, currency = 'INR') {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency', currency,
    minimumFractionDigits: 2, maximumFractionDigits: 2
  }).format(Number(amount) || 0);
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  return new Date(dateStr).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateTime(dateStr) {
  if (!dateStr) return '—';
  return new Date(dateStr).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function formatRelativeTime(dateStr) {
  if (!dateStr) return '—';
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins  = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days  = Math.floor(diff / 86400000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  if (hours < 24) return `${hours}h ago`;
  if (days < 7) return `${days}d ago`;
  return formatDate(dateStr);
}

function capitalise(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1);
}

function truncate(str, maxLen = 50) {
  if (!str) return '';
  return str.length > maxLen ? str.slice(0, maxLen) + '…' : str;
}

// ── Number Helpers ───────────────────────────────────────────────
function clamp(val, min, max) { return Math.min(Math.max(val, min), max); }
function randomBetween(min, max) { return Math.floor(Math.random() * (max - min + 1)) + min; }
function roundTo(n, decimals = 2) { return Math.round(n * Math.pow(10, decimals)) / Math.pow(10, decimals); }

// ── DOM Helpers ───────────────────────────────────────────────────
function qs(selector, parent = document) { return parent.querySelector(selector); }
function qsa(selector, parent = document) { return [...parent.querySelectorAll(selector)]; }

function setHTML(selector, html, parent = document) {
  const el = qs(selector, parent);
  if (el) el.innerHTML = html;
}

function setText(selector, text, parent = document) {
  const el = qs(selector, parent);
  if (el) el.textContent = text;
}

function show(selector, parent = document) {
  const el = qs(selector, parent);
  if (el) el.style.display = '';
}

function hide(selector, parent = document) {
  const el = qs(selector, parent);
  if (el) el.style.display = 'none';
}

function toggle(selector, show, parent = document) {
  const el = qs(selector, parent);
  if (el) el.style.display = show ? '' : 'none';
}

function setLoading(btnEl, isLoading, originalText = 'Submit') {
  if (!btnEl) return;
  btnEl.disabled = isLoading;
  btnEl.innerHTML = isLoading
    ? `<span class="spinner" style="width:16px;height:16px;border-width:2px;display:inline-block"></span> Loading…`
    : originalText;
}

// ── Badge Color by type ───────────────────────────────────────────
function typeBadge(type) {
  const map = {
    income: 'success', expense: 'danger', transfer: 'info',
    open: 'warning', resolved: 'success', closed: 'secondary',
    active: 'success', pending: 'warning', suspended: 'danger',
    low: 'secondary', medium: 'warning', high: 'danger', critical: 'dark',
  };
  const cls = map[type?.toLowerCase()] ?? 'secondary';
  return `<span class="badge bg-${cls}">${capitalise(type || '')}</span>`;
}

// ── Debounce ───────────────────────────────────────────────────────
function debounce(fn, delay = 300) {
  let timer;
  return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}

// ── Pagination helper ─────────────────────────────────────────────
function buildPagination(containerId, total, page, perPage, onPageChange) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const totalPages = Math.ceil(total / perPage);
  if (totalPages <= 1) { container.innerHTML = ''; return; }

  let html = '<nav><ul class="pagination pagination-sm mb-0">';
  html += `<li class="page-item ${page === 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${page - 1}">‹</a></li>`;
  for (let p = 1; p <= totalPages; p++) {
    html += `<li class="page-item ${p === page ? 'active' : ''}"><a class="page-link" href="#" data-page="${p}">${p}</a></li>`;
  }
  html += `<li class="page-item ${page === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${page + 1}">›</a></li>`;
  html += '</ul></nav>';

  container.innerHTML = html;
  container.querySelectorAll('.page-link').forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();
      const p = parseInt(link.dataset.page);
      if (p >= 1 && p <= totalPages) onPageChange(p);
    });
  });
}

// ── CSV Export ───────────────────────────────────────────────────
function exportToCSV(data, filename = 'export.csv') {
  if (!data.length) { showToast('No data to export', 'warning'); return; }
  const headers = Object.keys(data[0]);
  const rows    = data.map(r => headers.map(h => JSON.stringify(r[h] ?? '')).join(','));
  const csv     = [headers.join(','), ...rows].join('\n');
  const blob    = new Blob([csv], { type: 'text/csv' });
  const url     = URL.createObjectURL(blob);
  const a       = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
}

// ── Local storage helpers ─────────────────────────────────────────
function lsGet(key, fallback = null) {
  try { const v = localStorage.getItem(key); return v ? JSON.parse(v) : fallback; } catch { return fallback; }
}
function lsSet(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch {} }
function lsRemove(key) { localStorage.removeItem(key); }
