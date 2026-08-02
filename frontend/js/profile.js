// frontend/js/profile.js
const API_BASE = '../../backend/php/';

document.addEventListener('DOMContentLoaded', async function() {
    if (!await requireAuth()) return;
    initSidebar();
    initTheme();
    await loadProfile();
    initProfileForm();
});

async function loadProfile() {
    try {
        const r = await fetch(API_BASE + 'auth.php?action=session_info');
        const d = await r.json();
        if (!d.success || !d.data.user) return;
        const u = d.data.user;
        const name = (u.name || u.first_name || 'User').split(' ')[0];
        document.getElementById('profileName').textContent = u.name || u.first_name || 'User';
        document.getElementById('profileEmail').textContent = u.email || '';
        document.getElementById('firstName').value = u.first_name || name;
        document.getElementById('lastName').value = u.last_name || '';
        document.getElementById('profileEmailInput').value = u.email || '';
        document.getElementById('phone').value = u.phone || '';
        document.getElementById('bio').value = u.bio || '';
    } catch (e) {
        showError('Unable to load profile.');
    }
}

async function initProfileForm() {
    document.getElementById('profileForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const body = new URLSearchParams();
        body.append('first_name', document.getElementById('firstName').value);
        body.append('last_name', document.getElementById('lastName').value);
        body.append('email', document.getElementById('profileEmailInput').value);
        body.append('phone', document.getElementById('phone').value);
        body.append('bio', document.getElementById('bio').value);

        try {
            const r = await fetch(API_BASE + 'user_crud.php?action=update_profile', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const d = await r.json();
            if (d.success) {
                showSuccess('Profile updated successfully!');
                await loadProfile();
            } else {
                showError(d.message || 'Failed to update profile.');
            }
        } catch (err) {
            showError('Unable to reach server.');
        }
    });
}

function changeAvatar() {
    showInfo('Avatar upload coming soon. You can update your photo in the Settings page.');
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
    const t = document.getElementById('themeToggle');
    const h = document.documentElement;
    const saved = localStorage.getItem('theme') || 'light';
    h.setAttribute('data-theme', saved);
    if (t) {
        t.addEventListener('click', () => {
            const next = h.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            h.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
        });
    }
}

function logout() {
    window.utils.logout();
}
