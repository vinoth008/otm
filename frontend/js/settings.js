// frontend/js/settings.js
const API_BASE = '../../backend/php/';

document.addEventListener('DOMContentLoaded', async function() {
    if (!await requireAuth()) return;
    initSidebar();
    initTheme();
    initThemeSelect();
    initPreferenceToggles();
});

function changeTheme(value) {
    if (value === 'auto') {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        value = prefersDark ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', value);
    localStorage.setItem('theme', value);
    updateThemeIcons(value);
}

function initThemeSelect() {
    const select = document.getElementById('themeSelect');
    if (!select) return;
    const current = localStorage.getItem('theme') || 'light';
    select.value = current;
    select.addEventListener('change', () => changeTheme(select.value));
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        const stored = localStorage.getItem('theme') || 'light';
        if (stored === 'auto' || document.getElementById('themeSelect').value === 'auto') {
            changeTheme(e.matches ? 'dark' : 'light');
        }
    });
}

function initPreferenceToggles() {
    document.querySelectorAll('.checkbox-wrapper input[type="checkbox"]').forEach(function(cb) {
        const key = 'pref_' + (cb.closest('div').querySelector('div') ? cb.closest('div').querySelector('div').textContent.trim() : 'toggle');
        const stored = localStorage.getItem(key);
        if (stored !== null) cb.checked = stored === 'true';
        cb.addEventListener('change', function() {
            localStorage.setItem(key, cb.checked);
        });
    });
}

function changePassword() {
    showInfo('Password change form coming soon. You can reset your password from the login page.');
}

function enable2FA() {
    showInfo('Two-factor authentication setup coming soon.');
}

function manageDevices() {
    showInfo('Device management coming soon.');
}

function exportData() {
    window.utils.exportCSV([]);
}

function importData() {
    showInfo('Data import coming soon. Use CSV import in the Transactions page.');
}

function deleteAccount() {
    if (!confirm('This will permanently delete your account and all data. Continue?')) return;
    try {
        fetch(API_BASE + 'user_crud.php?action=delete_account', { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    window.location.href = '../../index.php';
                } else {
                    showError(d.message || 'Failed to delete account.');
                }
            });
    } catch (e) {
        showError('Unable to reach server.');
    }
}

async function requireAuth() {
    if (!await checkAuth()) {
        window.location.href = '../../index.php';
        return false;
    }
    return true;
}

async function checkAuth() {
    try {
        const r = await fetch(API_BASE + 'auth.php?action=session_info');
        const d = await r.json();
        return d.data && d.data.is_logged_in;
    } catch (e) {
        return false;
    }
}

function initSidebar() {
    const s = document.getElementById('sidebar');
    const t = document.getElementById('sidebarToggle');
    const c = document.getElementById('sidebarClose');
    const o = document.getElementById('mobileOverlay');
    if (t) t.addEventListener('click', () => s.classList.add('open'));
    if (c) c.addEventListener('click', () => s.classList.remove('open'));
    if (o) o.addEventListener('click', () => s.classList.remove('open'));
}

function initTheme() {
    const h = document.documentElement;
    const saved = localStorage.getItem('theme') || 'light';
    h.setAttribute('data-theme', saved);
    updateThemeIcons(saved);
    const t = document.getElementById('themeToggle');
    if (t) {
        t.addEventListener('click', () => {
            const next = h.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            h.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
            updateThemeIcons(next);
        });
    }
}

function updateThemeIcons(theme) {
    const moon = document.querySelector('#themeToggle .fa-moon');
    const sun = document.querySelector('#themeToggle .fa-sun');
    if (moon) moon.style.display = theme === 'light' ? 'block' : 'none';
    if (sun) sun.style.display = theme === 'light' ? 'none' : 'block';
}

function logout() {
    window.utils.logout();
}
