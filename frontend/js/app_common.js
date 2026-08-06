// frontend/js/app_common.js
/**
 * Smart Transaction Control - Shared App Shell & Helpers
 * Renders role-based sidebar + top navigation, handles auth guards,
 * theme, notifications, and provides API helper methods.
 */
// ============================================
// PATH DETECTION
// ============================================
const STC = (function () {
    const path = window.location.pathname;
    const marker = '/frontend/html/';
    const afterMarker = path.includes(marker) ? path.split(marker)[1] : '';
    const DEEP = afterMarker.indexOf('/') !== -1; // true for admin/ /staff/ /receptionist/ subfolders
    const ROOT = DEEP ? '../../..' : '../..';
    const API = DEEP ? '../../../backend/php/' : '../../backend/php/';
    const P = DEEP ? '../' : ''; // prefix for cross-folder customer pages

    // Fallback toggle if utils.js wasn't loaded before app_common.js
    const THEME_STORE_KEY = 'theme';
    function localGetTheme() {
        return document.documentElement.getAttribute('data-theme') || 'light';
    }
    function localSetTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem(THEME_STORE_KEY, theme);
        // Persist to backend so it survives across devices (best-effort)
        try {
            fetch(apiUrl('settings.php', 'update'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ theme_preference: theme })
            }).catch(() => {});
        } catch (e) { /* ignore */ }
    }
    if (typeof window.toggleTheme !== 'function') {
        window.toggleTheme = function toggleTheme() {
            localSetTheme(localGetTheme() === 'light' ? 'dark' : 'light');
        };
    }
    if (typeof window.setTheme !== 'function') {
        window.setTheme = localSetTheme;
    }

    // Inject the MINI_PROJECT design system on every page that boots the shell.
    // ROOT is relative to frontend/html/ — the CSS lives at ROOT/css/mini_theme.css.
    (function injectTheme() {
        if (document.getElementById('miniThemeCss')) return;
        const link = document.createElement('link');
        link.id = 'miniThemeCss';
        link.rel = 'stylesheet';
        link.href = ROOT + '/css/mini_theme.css';
        document.head.appendChild(link);
    })();

    // ============================================
    // NAVIGATION
    // ============================================
    // NAV paths are relative to frontend/html/ so they resolve from any page
    // depth (top-level pages use no prefix, role subfolders get '../').
    const NAV = {
        // Admin role - full system access
        admin: [
            {
                section: 'Main',
                items: [
                    { href: 'admin/dashboard.html', icon: 'fa-th-large', label: 'Dashboard' },
                    { href: 'admin/transactions.html', icon: 'fa-exchange-alt', label: 'Transactions' },
                    { href: 'admin/users.html', icon: 'fa-users', label: 'Users' }
                ]
            },
            {
                section: 'Approvals',
                items: [
                    { href: 'admin/transactions.html', icon: 'fa-check-double', label: 'Pending Approvals' },
                    { href: 'admin/notifications.html', icon: 'fa-bell', label: 'Notifications' }
                ]
            },
            {
                section: 'Management',
                items: [
                    { href: 'categories.html', icon: 'fa-tags', label: 'Categories' },
                    { href: 'admin/roles.html', icon: 'fa-user-shield', label: 'Roles & Permissions' },
                    { href: 'admin/settings.html', icon: 'fa-cog', label: 'System Settings' }
                ]
            },
            {
                section: 'Audit & Reports',
                items: [
                    { href: 'admin/audit.html', icon: 'fa-scroll', label: 'Audit Logs' },
                    { href: 'admin/reports.html', icon: 'fa-chart-bar', label: 'Reports' },
                    { href: 'analytics.html', icon: 'fa-chart-pie', label: 'Analytics' }
                ]
            },
            {
                section: 'Account',
                items: [
                    { href: 'profile.html', icon: 'fa-user', label: 'Profile' },
                    { href: 'settings.html', icon: 'fa-cog', label: 'My Settings' }
                ]
            }
        ],
        // Staff role - approve/reject NEFT/IMPS, manage customers, reports
        staff: [
            {
                section: 'Main',
                items: [
                    { href: 'staff/dashboard.html', icon: 'fa-th-large', label: 'Dashboard' },
                    { href: 'staff/customers.html', icon: 'fa-users', label: 'Customers' },
                    { href: 'staff/transfers.html', icon: 'fa-exchange-alt', label: 'Transaction Requests' }
                ]
            },
            {
                section: 'Approvals',
                items: [
                    { href: 'staff/transfers.html', icon: 'fa-check-double', label: 'Pending Approvals' }
                ]
            },
            {
                section: 'Services',
                items: [
                    { href: 'staff/appointments.html', icon: 'fa-calendar-check', label: 'Appointments' },
                    { href: 'staff/complaints.html', icon: 'fa-headset', label: 'Complaints' },
                    { href: 'staff/beneficiaries.html', icon: 'fa-user-friends', label: 'Beneficiaries' },
                    { href: 'staff/receipts.html', icon: 'fa-receipt', label: 'Receipts' }
                ]
            },
            {
                section: 'Account',
                items: [
                    { href: 'profile.html', icon: 'fa-user', label: 'Profile' }
                ]
            }
        ],
        // Receptionist role - register customers, create requests, upload docs
        receptionist: [
            {
                section: 'Main',
                items: [
                    { href: 'receptionist/dashboard.html', icon: 'fa-th-large', label: 'Dashboard' },
                    { href: 'receptionist/customers.html', icon: 'fa-users', label: 'Customers' }
                ]
            },
            {
                section: 'Services',
                items: [
                    { href: 'receptionist/appointments.html', icon: 'fa-calendar-check', label: 'Appointments' },
                    { href: 'receptionist/receipts.html', icon: 'fa-receipt', label: 'Receipts' }
                ]
            },
            {
                section: 'Account',
                items: [
                    { href: 'profile.html', icon: 'fa-user', label: 'Profile' }
                ]
            }
        ],
        // Customer role - banking operations
        customer: [
            {
                section: 'Main',
                items: [
                    { href: 'customer/dashboard.html', icon: 'fa-th-large', label: 'Dashboard' },
                    { href: 'transactions.html', icon: 'fa-exchange-alt', label: 'Transactions' },
                    { href: 'transfers.html', icon: 'fa-paper-plane', label: 'Transfer / Pay' }
                ]
            },
            {
                section: 'Banking',
                items: [
                    { href: 'wallet.html', icon: 'fa-wallet', label: 'My Wallet' },
                    { href: 'beneficiaries.html', icon: 'fa-user-friends', label: 'Beneficiaries' },
                    { href: 'budget.html', icon: 'fa-piggy-bank', label: 'Budget' },
                    { href: 'goals.html', icon: 'fa-bullseye', label: 'Goals' }
                ]
            },
            {
                section: 'Account',
                items: [
                    { href: 'profile.html', icon: 'fa-user', label: 'Profile' },
                    { href: 'settings.html', icon: 'fa-cog', label: 'Settings' },
                    { href: 'reports.html', icon: 'fa-chart-bar', label: 'Reports' }
                ]
            }
        ]
    };

    const ROLE_LABEL = {
        admin: 'Administrator',
        staff: 'Staff',
        receptionist: 'Receptionist',
        customer: 'Customer'
    };

    // ============================================
    // API HELPERS
    // ============================================
    function apiUrl(module, action, params) {
        let url = API + module + '?action=' + encodeURIComponent(action);
        if (params) {
            const qs = new URLSearchParams(params).toString();
            if (qs) url += '&' + qs;
        }
        return url;
    }

    async function request(url, options) {
        const res = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                ...(options && options.headers ? options.headers : {})
            }
        });
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'Request failed');
        }
        return data.data;
    }

    function get(module, action, params) {
        return request(apiUrl(module, action, params));
    }

    function post(module, action, body) {
        const payload = { ...(body || {}) };
        if (!payload.csrf_token) {
            payload.csrf_token = getCookie('csrf_token') || '';
        }
        return request(apiUrl(module, action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    }

    async function session() {
        try {
            const res = await fetch(apiUrl('auth.php', 'session_info'), { credentials: 'same-origin' });
            const data = await res.json();
            return data.data || { is_logged_in: false };
        } catch (e) {
            return { is_logged_in: false };
        }
    }

    // ============================================
    // SHELL RENDER
    // ============================================
    function renderShell(config, s) {
        const role = s.user_role || 'customer';
        const currentPage = config.page || '';
        const nav = NAV[role] || NAV.customer;
        document.body.setAttribute('data-role', role);

        const activeCls = (href) => {
            const base = href.split('/').pop();
            return base === currentPage ? ' active' : '';
        };

        // On deep (role subfolder) pages, every nav href needs '../' to resolve
        // relative to frontend/html/ (e.g. ../user/dashboard.html).
        // On top-level pages, hrefs are already relative to frontend/html/.
        const linkPrefix = DEEP ? '../' : '';

        let navHTML = '';
        nav.forEach(group => {
            navHTML += `<div class="sidebar-divider"></div>
                        <div class="sidebar-section-title">${group.section}</div>`;
            group.items.forEach(item => {
                const href = linkPrefix + item.href;
                navHTML += `<a href="${href}" class="sidebar-menu-item${activeCls(item.href)}">
                                <i class="fas ${item.icon}"></i>
                                <span>${item.label}</span>
                            </a>`;
            });
        });
        navHTML += `<div class="sidebar-divider"></div>
                    <a href="#" class="sidebar-menu-item" onclick="STC.logout()">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>`;

        const sidebar = document.createElement('aside');
        sidebar.className = 'sidebar';
        sidebar.id = 'sidebar';
        sidebar.innerHTML = `
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-wallet"></i>
                    <span>STC</span>
                </div>
                <button class="sidebar-toggle" id="sidebarClose"><i class="fas fa-times"></i></button>
            </div>
            <nav class="sidebar-menu">${navHTML}</nav>
            <div class="sidebar-footer">
                <div class="card" style="background: linear-gradient(135deg, #4f46e5, #06b6d4); color: white; padding: 16px; text-align: center;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.9rem;">
                        <i class="fas fa-shield-alt"></i> ${ROLE_LABEL[role] || 'Member'}
                    </div>
                    <p style="font-size: 0.72rem; margin: 0; opacity: 0.9;">
                        ${s.user_name || ''}
                    </p>
                </div>
            </div>`;

        const overlay = document.createElement('div');
        overlay.className = 'mobile-overlay';
        overlay.id = 'mobileOverlay';

        const topnav = document.createElement('div');
        topnav.className = 'top-nav';
        topnav.innerHTML = `
            <div class="top-nav-left">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <div>
                    <h1 class="page-title">${config.title || 'Dashboard'}</h1>
                    <div class="breadcrumb">
                        <a href="#">Home</a>
                        <i class="fas fa-chevron-right" style="font-size: 0.625rem;"></i>
                        <span>${config.title || 'Dashboard'}</span>
                    </div>
                </div>
            </div>
            <div class="top-nav-right">
                <div class="nav-actions">
                    <button class="nav-icon-btn" id="themeToggle" title="Toggle Theme">
                        <i class="fas fa-moon"></i>
                        <i class="fas fa-sun" style="display: none;"></i>
                    </button>
                    <div class="nav-notif-wrap">
                        <button class="nav-icon-btn" id="notificationsBtn" title="Notifications">
                            <i class="fas fa-bell"></i>
                            <span class="badge badge-danger" id="notifBadge" style="position: absolute; top: -5px; right: -5px; display: none;">0</span>
                        </button>
                        <div class="dropdown-menu" id="notifDropdown" style="display: none;"></div>
                    </div>
                    <div class="user-menu" id="userMenu">
                        <img src="https://ui-avatars.com/api/svg?name=${encodeURIComponent(s.user_name || 'User')}&color=4f46e5&background=e0e7ff&size=40" id="userAvatar" alt="User">
                        <div class="user-info">
                            <span class="user-name" id="userName">${s.user_name || 'Loading...'}</span>
                            <span class="user-role" id="userRole">${ROLE_LABEL[role] || 'User'}</span>
                        </div>
                    </div>
                </div>
            </div>`;

        const main = document.querySelector('main.main-content');
        if (main) {
            main.insertBefore(topnav, main.firstChild);
        }
        const layout = document.querySelector('.dashboard-layout');
        if (layout) {
            layout.insertBefore(overlay, layout.firstChild);
            layout.insertBefore(sidebar, layout.firstChild);
        }
        wireShell();
    }

    function wireShell() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('mobileOverlay');
        const toggleBtn = document.getElementById('sidebarToggle');
        const closeBtn = document.getElementById('sidebarClose');
        if (sidebar && toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.add('open');
                if (overlay) overlay.classList.add('active');
            });
        }
        if (sidebar && closeBtn) {
            closeBtn.addEventListener('click', () => {
                sidebar.classList.remove('open');
                if (overlay) overlay.classList.remove('active');
            });
        }
        if (overlay) {
            overlay.addEventListener('click', () => {
                if (sidebar) sidebar.classList.remove('open');
                overlay.classList.remove('active');
            });
        }
        const themeBtn = document.getElementById('themeToggle');
        if (themeBtn) {
            const applyThemeIcon = () => {
                const dark = document.documentElement.getAttribute('data-theme') === 'dark';
                if (themeBtn) {
                    const moon = themeBtn.querySelector('.fa-moon');
                    const sun = themeBtn.querySelector('.fa-sun');
                    if (moon) moon.style.display = dark ? 'none' : 'inline-block';
                    if (sun) sun.style.display = dark ? 'inline-block' : 'none';
                }
            };
            themeBtn.addEventListener('click', () => {
                toggleTheme();
                applyThemeIcon();
            });
            applyThemeIcon();
        }
        const notifBtn = document.getElementById('notificationsBtn');
        if (notifBtn) {
            notifBtn.addEventListener('click', loadNotifications);
        }
        loadUnreadCount();
    }

    async function loadUnreadCount() {
        try {
            const data = await get('notification_crud.php', 'stats');
            const badge = document.getElementById('notifBadge');
            if (badge) {
                const count = data.unread_count || 0;
                badge.textContent = count;
                badge.style.display = count > 0 ? 'inline-block' : 'none';
            }
        } catch (e) { /* ignore */ }
    }

    async function loadNotifications() {
        const dropdown = document.getElementById('notifDropdown');
        if (!dropdown) return;
        if (dropdown.style.display === 'block') {
            dropdown.style.display = 'none';
            return;
        }
        try {
            const data = await get('notification_crud.php', 'get_all');
            const list = data.notifications || [];
            let html = '<div class="dropdown-header">Notifications</div>';
            if (list.length === 0) {
                html += '<div class="dropdown-item" style="color: var(--text-secondary);">No notifications</div>';
            } else {
                list.slice(0, 5).forEach(n => {
                    html += `<div class="dropdown-item ${n.read ? '' : 'unread'}">
                                <div style="font-weight: 600; font-size: 0.8rem;">${escapeHtml(n.title)}</div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary);">${escapeHtml(n.message)}</div>
                                <div style="font-size: 0.68rem; color: var(--text-tertiary); margin-top: 4px;">${n.created_at}</div>
                            </div>`;
                });
                html += `<div class="dropdown-item" style="text-align: center;">
                            <a href="#" onclick="STC.markAllRead(); return false;" style="font-size: 0.75rem;">Mark all as read</a>
                         </div>`;
            }
            dropdown.innerHTML = html;
            dropdown.style.display = 'block';
            loadUnreadCount();
        } catch (e) {
            if (dropdown) dropdown.style.display = 'none';
        }
    }

    async function markAllRead() {
        try {
            await post('notification_crud.php', 'mark_read', {});
            const dropdown = document.getElementById('notifDropdown');
            if (dropdown) dropdown.style.display = 'none';
            loadUnreadCount();
        } catch (e) { /* ignore */ }
    }

    async function logout() {
        if (!confirm('Are you sure you want to logout?')) return;
        try {
            await fetch(apiUrl('auth.php', 'logout'), { method: 'POST', credentials: 'same-origin' });
        } catch (e) { /* ignore */ }
        window.location.href = ROOT + '/index.php';
    }

    // ============================================
    // BOOT
    // ============================================
    async function boot(config) {
        const s = await session();
        if (!s.is_logged_in) {
            window.location.href = ROOT + '/index.php';
            return null;
        }
        STC.isBooted = true;
        window.STC_SESSION = s;
        if (config.roles && s.user_role !== 'admin' && !config.roles.includes(s.user_role)) {
            window.location.href = s.dashboard_url || (ROOT + '/index.php');
            return null;
        }
        // Apply saved theme
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme) {
            document.documentElement.setAttribute('data-theme', savedTheme);
        } else if (s.user_theme) {
            document.documentElement.setAttribute('data-theme', s.user_theme);
        }
        renderShell(config, s);
        if (typeof config.init === 'function') {
            config.init(s);
        }
        return s;
    }

    function esc(text) {
        return escapeHtml(String(text == null ? '' : text));
    }

    // ============================================
    // TOASTS (no page reload notifications)
    // ============================================
    function showToast(message, type) {
        type = type || 'info';
        let container = document.getElementById('stcToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'stcToastContainer';
            container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 99999; display: flex; flex-direction: column; gap: 10px;';
            document.body.appendChild(container);
        }
        const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
        const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };
        const toast = document.createElement('div');
        toast.style.cssText = 'background: var(--bg-card, #ffffff); border: 1px solid var(--border, #e5e7eb); border-left: 4px solid ' + (colors[type] || colors.info) + '; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.3); padding: 12px 16px; min-width: 260px; max-width: 360px; display: flex; align-items: center; gap: 10px; font-size: 0.85rem; color: var(--text-primary, #1f2937); animation: slideInRight 0.25s ease;';
        toast.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '" style="color: ' + (colors[type] || colors.info) + '; font-size: 1.1rem;"></i><span></span>';
        toast.querySelector('span').textContent = message;
        container.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.3s'; setTimeout(() => toast.remove(), 300); }, 3500);
    }

    function showToastSuccess(message) { showToast(message, 'success'); }
    function showToastError(message) { showToast(message, 'error'); }
    function showToastWarning(message) { showToast(message, 'warning'); }
    function showToastInfo(message) { showToast(message, 'info'); }
    function showError(message) { showToast(message, 'error'); }

    function badge(status) {
        const map = {
            pending: 'badge badge-warning',
            scheduled: 'badge badge-info',
            approved: 'badge badge-success',
            completed: 'badge badge-success',
            rejected: 'badge badge-danger',
            cancelled: 'badge badge-secondary',
            open: 'badge badge-warning',
            in_progress: 'badge badge-info',
            resolved: 'badge badge-success',
            closed: 'badge badge-secondary',
            active: 'badge badge-success',
            suspended: 'badge badge-danger',
            deleted: 'badge badge-secondary'
        };
        const cls = map[status] || 'badge';
        const label = status ? status.replace(/_/g, ' ') : '';
        return `<span class="${cls}">${label}</span>`;
    }

    return {
        DEEP, ROOT, API, NAV, ROLE_LABEL,
        session, get, post, apiUrl, boot, logout,
        markAllRead, loadUnreadCount,
        esc, badge,
        showToast, showToastSuccess, showToastError, showToastWarning, showToastInfo,
        showError
    };
})();
