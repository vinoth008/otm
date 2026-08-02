// frontend/js/utils.js
/**
 * Smart Transaction Control - Utility Functions
 * Shared utilities across all frontend pages
 */
// ============================================
// COOKIE HELPERS
// ============================================
/**
 * Get cookie by name
 */
function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
}
/**
 * Set cookie
 */
function setCookie(name, value, days = 7) {
    const expires = new Date(Date.now() + days * 24 * 60 * 60 * 1000).toUTCString();
    document.cookie = `${name}=${value}; expires=${expires}; path=/; SameSite=Strict;`;
}
/**
 * Delete cookie
 */
function deleteCookie(name) {
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
}
// ============================================
// FORMATTING HELPERS
// ============================================
/**
 * Format currency (INR)
 */
function formatCurrency(amount, showSymbol = true) {
    const formatted = parseFloat(amount).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    return showSymbol ? `₹${formatted}` : formatted;
}
/**
 * Format date for display
 */
function formatDate(dateStr, format = 'long') {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    if (format === 'short') {
        return date.toLocaleDateString('en-IN', {
            month: 'short',
            day: 'numeric'
        });
    }
    if (format === 'time') {
        return date.toLocaleTimeString('en-IN', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    return date.toLocaleDateString('en-IN', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}
/**
 * Format datetime
 */
function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleString('en-IN', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}
/**
 * Format number with commas
 */
function formatNumber(num) {
    return parseFloat(num).toLocaleString('en-IN');
}
/**
 * Format percentage
 */
function formatPercentage(value, decimals = 1) {
    return `${parseFloat(value).toFixed(decimals)}%`;
}
/**
 * Truncate text
 */
function truncateText(text, maxLength = 50) {
    if (!text) return '';
    if (text.length <= maxLength) return text;
    return text.substring(0, maxLength) + '...';
}
// ============================================
// UI HELPERS
// ============================================
/**
 * Show loading overlay
 */
function showLoading(message = 'Loading...') {
    const overlay = document.createElement('div');
    overlay.id = 'loadingOverlay';
    overlay.className = 'loading-overlay';
    overlay.innerHTML = `
        <div class="card" style="text-align: center; padding: 40px;">
            <div class="spinner spinner-lg" style="margin: 0 auto 16px;"></div>
            <p style="color: var(--text-primary);">${message}</p>
        </div>
    `;
    document.body.appendChild(overlay);
}
/**
 * Hide loading overlay
 */
function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.remove();
}
/**
 * Show toast notification
 */
function showToast(message, type = 'info', duration = 3000) {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <i class="fas fa-${getToastIcon(type)}"></i>
        <span>${message}</span>
    `;
    // Add styles if not exist
    if (!document.getElementById('toastStyles')) {
        const style = document.createElement('style');
        style.id = 'toastStyles';
        style.textContent = `
            .toast {
                position: fixed;
                bottom: 24px;
                right: 24px;
                padding: 12px 24px;
                border-radius: 8px;
                color: white;
                font-weight: 500;
                display: flex;
                align-items: center;
                gap: 8px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                z-index: 1000;
                animation: slideIn 0.3s ease;
            }
            .toast-success { background: #10b981; }
            .toast-error { background: #ef4444; }
            .toast-warning { background: #f59e0b; }
            .toast-info { background: #3b82f6; }
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    }
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.animation = 'slideIn 0.3s ease reverse';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}
/**
 * Get toast icon
 */
function getToastIcon(type) {
    const icons = {
        success: 'check-circle',
        error: 'exclamation-circle',
        warning: 'exclamation-triangle',
        info: 'info-circle'
    };
    return icons[type] || 'info-circle';
}
/**
 * Show success message
 */
function showSuccess(message) {
    showToast(message, 'success');
}
/**
 * Show error message
 */
function showError(message) {
    showToast(message, 'error');
}
/**
 * Show warning message
 */
function showWarning(message) {
    showToast(message, 'warning');
}
/**
 * Show info message
 */
function showInfo(message) {
    showToast(message, 'info');
}
// ============================================
// MODAL HELPERS
// ============================================
/**
 * Open modal
 */
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}
/**
 * Close modal
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}
/**
 * Close all modals
 */
function closeAllModals() {
    document.querySelectorAll('.modal').forEach(modal => {
        modal.classList.remove('active');
    });
    document.body.style.overflow = '';
}
// ============================================
// API HELPERS
// ============================================
/**
 * Make API request
 */
async function apiRequest(endpoint, options = {}) {
    const defaultOptions = {
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    const mergedOptions = {
        ...defaultOptions,
        ...options,
        headers: {
            ...defaultOptions.headers,
            ...(options.headers || {})
        }
    };
    try {
        const response = await fetch(endpoint, mergedOptions);
        const data = await response.json();
        return { response, data };
    } catch (error) {
        console.error('API request error:', error);
        throw error;
    }
}
/**
 * Check if user is authenticated
 */
async function checkAuth() {
    try {
        const { data } = await apiRequest('../../backend/php/auth.php?action=session_info');
        return data.success && data.data.is_logged_in;
    } catch {
        return false;
    }
}
/**
 * Require authentication
 */
async function requireAuth() {
    const isAuth = await checkAuth();
    if (!isAuth) {
        window.location.href = '../../index.php';
        return false;
    }
    return true;
}
/**
 * Logout
 */
async function logout() {
    if (!confirm('Are you sure you want to logout?')) return;
    try {
        await fetch('../../backend/php/auth.php?action=logout', {
            method: 'POST',
            credentials: 'same-origin'
        });
    } catch (error) {
        console.error('Logout error:', error);
    }
    window.location.href = '../../index.php';
}
// ============================================
// STORAGE HELPERS
// ============================================
/**
 * Save to localStorage
 */
function saveToLocal(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch (error) {
        console.error('Save to localStorage error:', error);
    }
}
/**
 * Get from localStorage
 */
function getFromLocal(key, defaultValue = null) {
    try {
        const item = localStorage.getItem(key);
        return item ? JSON.parse(item) : defaultValue;
    } catch (error) {
        console.error('Get from localStorage error:', error);
        return defaultValue;
    }
}
/**
 * Remove from localStorage
 */
function removeFromLocal(key) {
    try {
        localStorage.removeItem(key);
    } catch (error) {
        console.error('Remove from localStorage error:', error);
    }
}
// ============================================
// THEME HELPERS
// ============================================
/**
 * Get current theme
 */
function getCurrentTheme() {
    return document.documentElement.getAttribute('data-theme') || 'light';
}
/**
 * Set theme
 */
function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
}
/**
 * Toggle theme
 */
function toggleTheme() {
    const current = getCurrentTheme();
    setTheme(current === 'light' ? 'dark' : 'light');
}
// ============================================
// DEBOUNCE & THROTTLE
// ============================================
/**
 * Debounce function
 */
function debounce(func, wait = 300) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}
/**
 * Throttle function
 */
function throttle(func, limit = 300) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}
// ============================================
// EXPORT HELPERS
// ============================================
/**
 * Export data as CSV
 */
function exportToCSV(data, filename = 'export.csv') {
    if (!data || data.length === 0) {
        alert('No data to export');
        return;
    }
    const headers = Object.keys(data[0]);
    const csv = [
        headers.join(','),
        ...data.map(row =>
            headers.map(field =>
                JSON.stringify(row[field] || '')
            ).join(',')
        )
    ].join('\n');
    downloadFile(csv, filename, 'text/csv');
}
/**
 * Export data as JSON
 */
function exportToJSON(data, filename = 'export.json') {
    const json = JSON.stringify(data, null, 2);
    downloadFile(json, filename, 'application/json');
}
/**
 * Download file
 */
function downloadFile(content, filename, mimeType) {
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}
// ============================================
// CATEGORY HELPERS
// ============================================
/**
 * Get category color
 */
function getCategoryColor(category) {
    const colors = {
        'Food': '#f97316',
        'Travel': '#fb923c',
        'Shopping': '#fdba74',
        'Bills & Utilities': '#fbbf24',
        'Entertainment': '#fca5a5',
        'Medical': '#ef4444',
        'Education': '#f87171',
        'Salary': '#10b981',
        'Bonus': '#34d399',
        'Freelance': '#06b6d4',
        'Investment': '#8b5cf6',
        'Rental': '#a78bfa',
        'Other Income': '#6ee7b7',
        'Other Expense': '#6b7280',
        'Transfer': '#3b82f6'
    };
    return colors[category] || '#6b7280';
}
/**
 * Get category icon
 */
function getCategoryIcon(category) {
    const icons = {
        'Food': 'fa-utensils',
        'Travel': 'fa-car',
        'Shopping': 'fa-shopping-bag',
        'Bills & Utilities': 'fa-bolt',
        'Entertainment': 'fa-film',
        'Medical': 'fa-heart',
        'Education': 'fa-book',
        'Salary': 'fa-money-bill-wave',
        'Bonus': 'fa-gift',
        'Freelance': 'fa-laptop',
        'Investment': 'fa-chart-line',
        'Rental': 'fa-home',
        'Other Income': 'fa-plus-circle',
        'Other Expense': 'fa-minus-circle',
        'Transfer': 'fa-exchange-alt',
        'Fuel': 'fa-gas-pump',
        'Insurance': 'fa-shield-alt',
        'Subscriptions': 'fa-sync',
        'Rent': 'fa-home',
        'EMI': 'fa-credit-card',
        'Tax': 'fa-file-invoice-dollar',
        'Loan': 'fa-hand-holding-usd'
    };
    return icons[category] || 'fa-tag';
}
/**
 * Get payment method icon
 */
function getPaymentMethodIcon(method) {
    const icons = {
        'cash': 'fa-money-bill',
        'card': 'fa-credit-card',
        'upi': 'fa-mobile-alt',
        'bank_transfer': 'fa-university',
        'wallet': 'fa-wallet',
        'other': 'fa-question-circle'
    };
    return icons[method] || 'fa-question-circle';
}
/**
 * Format payment method display
 */
function formatPaymentMethod(method) {
    const methods = {
        'cash': 'Cash',
        'card': 'Card',
        'upi': 'UPI',
        'bank_transfer': 'Bank Transfer',
        'wallet': 'Wallet',
        'other': 'Other'
    };
    return methods[method] || method;
}
// ============================================
// MISC HELPERS
// ============================================
/**
 * Copy to clipboard
 */
async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        showSuccess('Copied to clipboard!');
        return true;
    } catch (error) {
        console.error('Copy error:', error);
        showError('Failed to copy');
        return false;
    }
}
/**
 * Generate random ID
 */
function generateId(length = 12) {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    return Array.from({ length }, () =>
        chars.charAt(Math.floor(Math.random() * chars.length))
    ).join('');
}
/**
 * Escape HTML
 */
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}
/**
 * Parse query parameters
 */
function getQueryParams() {
    const params = new URLSearchParams(window.location.search);
    return Object.fromEntries(params);
}
/**
 * Get query parameter by name
 */
function getQueryParam(name, defaultValue = null) {
    const params = getQueryParams();
    return params[name] || defaultValue;
}
/**
 * Navigate to page
 */
function navigateTo(url, delay = 0) {
    if (delay > 0) {
        setTimeout(() => {
            window.location.href = url;
        }, delay);
    } else {
        window.location.href = url;
    }
}
/**
 * Confirm action
 */
function confirmAction(message = 'Are you sure?') {
    return confirm(message);
}
// Make functions globally available
window.utils = {
    getCookie, setCookie, deleteCookie,
    formatCurrency, formatDate, formatDateTime, formatNumber, formatPercentage,
    truncateText,
    showLoading, hideLoading,
    showToast, showSuccess, showError, showWarning, showInfo,
    openModal, closeModal, closeAllModals,
    apiRequest, checkAuth, requireAuth, logout,
    saveToLocal, getFromLocal, removeFromLocal,
    getCurrentTheme, setTheme, toggleTheme,
    debounce, throttle,
    exportToCSV, exportToJSON, downloadFile,
    getCategoryColor, getCategoryIcon, getPaymentMethodIcon, formatPaymentMethod,
    copyToClipboard, generateId, escapeHtml,
    getQueryParams, getQueryParam, navigateTo, confirmAction
};
