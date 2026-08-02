// frontend/js/dashboard.js
/**
 * Smart Transaction Control - Dashboard Logic
 * Handles dashboard data loading, charts, and user interactions
 */
// API Base URL
const API_BASE = '../../backend/php/';
// Chart instances
let expensePieChart = null;
let dailyTrendChart = null;
// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    initDashboard();
    initSidebar();
    initTheme();
    setDefaultDate();
});
/**
 * Initialize dashboard
 */
async function initDashboard() {
    try {
        // Check authentication
        const sessionData = await checkSession();
        if (!sessionData.is_logged_in) {
            window.location.href = '../../index.php';
            return;
        }
        // Update user info
        updateUserInfo(sessionData);
        // Load dashboard data
        await loadDashboardData();
        // Initialize charts
        initializeCharts();
        // Show dashboard grid
        document.getElementById('dashboardLoading').style.display = 'none';
        document.getElementById('dashboardGrid').style.display = 'block';
    } catch (error) {
        console.error('Dashboard initialization error:', error);
        showError('Failed to load dashboard. Please refresh the page.');
    }
}
/**
 * Check session and get user data
 */
async function checkSession() {
    try {
        const response = await fetch(API_BASE + 'auth.php?action=session_info', {
            method: 'GET',
            credentials: 'same-origin'
        });
        const data = await response.json();
        return data.data || { is_logged_in: false };
    } catch (error) {
        console.error('Session check error:', error);
        return { is_logged_in: false };
    }
}
/**
 * Update user information in UI
 */
function updateUserInfo(sessionData) {
    if (sessionData.user_name) {
        document.getElementById('userName').textContent = sessionData.user_name;
    }
    if (sessionData.user_id) {
        const avatarUrl = `https://ui-avatars.com/api/svg?name=${encodeURIComponent(sessionData.user_name)}&color=4f46e5&background=e0e7ff&size=40`;
        document.getElementById('userAvatar').src = avatarUrl;
    }
}
/**
 * Load dashboard data from API
 */
async function loadDashboardData() {
    try {
        // Load transactions summary
        const summaryResponse = await fetch(API_BASE + 'transaction_crud.php?action=summary', {
            method: 'GET',
            credentials: 'same-origin'
        });
        const summaryData = await summaryResponse.json();
        if (summaryData.success) {
            updateStatsCards(summaryData.data);
            updateRecentTransactions(summaryData.data.recent_transactions);
            updateExpensePieChart(summaryData.data.category_breakdown);
            updateDailyTrendChart(summaryData.data.daily_trend);
        } else {
            showError('Failed to load dashboard data');
        }
    } catch (error) {
        console.error('Load dashboard data error:', error);
        showError('Network error. Please check your connection.');
    }
}
/**
 * Update statistics cards
 */
function updateStatsCards(data) {
    // Format currency
    const formatCurrency = (amount) => {
        return '₹' + parseFloat(amount).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };
    // Update DOM elements
    document.getElementById('totalIncome').textContent = formatCurrency(data.total_income);
    document.getElementById('totalExpense').textContent = formatCurrency(data.total_expense);
    document.getElementById('totalSavings').textContent = formatCurrency(data.balance);
    document.getElementById('currentBalance').textContent = formatCurrency(data.balance);
    // Update savings rate
    document.getElementById('savingsRate').textContent = data.savings_rate.toFixed(1) + '% savings rate';
    // Update change indicators (simplified - would calculate from previous period in production)
    document.getElementById('incomeChange').textContent = '+' + data.income_count + ' transactions this month';
    document.getElementById('expenseChange').textContent = '+' + data.expense_count + ' transactions this month';
}
/**
 * Update recent transactions table
 */
function updateRecentTransactions(transactions) {
    const tbody = document.getElementById('recentTransactionsTable');
    if (!transactions || transactions.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center" style="padding: 40px;">
                    <i class="fas fa-receipt" style="font-size: 3rem; color: var(--text-tertiary);"></i>
                    <p>No transactions yet</p>
                    <button class="btn btn-primary btn-sm" onclick="openAddTransactionModal()">
                        <i class="fas fa-plus"></i> Add First Transaction
                    </button>
                </td>
            </tr>
        `;
        return;
    }
    tbody.innerHTML = transactions.map(trans => `
        <tr>
            <td>${formatDate(trans.date)}</td>
            <td>
                <div class="transaction-category">
                    <div class="category-icon" style="background: ${getCategoryColor(trans.category)}20;">
                        <i class="fas ${getCategoryIcon(trans.category)}"></i>
                    </div>
                    <span>${trans.category}</span>
                </div>
            </td>
            <td>${trans.description || '-'}</td>
            <td>${formatPaymentMethod(trans.payment_method)}</td>
            <td style="font-weight: 600; color: ${trans.type === 'income' ? 'var(--success-color)' : 'var(--danger-color)'};">
                ${trans.type === 'income' ? '+' : '-'}₹${parseFloat(trans.amount).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </td>
            <td>
                <span class="transaction-type ${trans.type}">
                    ${trans.type}
                </span>
            </td>
        </tr>
    `).join('');
}
/**
 * Initialize charts
 */
function initializeCharts() {
    // Expense Pie Chart
    const pieCtx = document.getElementById('expensePieChart').getContext('2d');
    expensePieChart = new Chart(pieCtx, {
        type: 'doughnut',
        data: {
            labels: [],
            datasets: [{
                data: [],
                backgroundColor: [
                    '#4f46e5', '#06b6d4', '#10b981', '#f59e0b',
                    '#ef4444', '#8b5cf6', '#ec4899', '#f97316'
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 16,
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${label}: ₹${value.toLocaleString('en-IN')} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
    // Daily Trend Chart
    const lineCtx = document.getElementById('dailyTrendChart').getContext('2d');
    dailyTrendChart = new Chart(lineCtx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'Daily Spending',
                data: [],
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `₹${context.parsed.y.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₹' + value.toLocaleString('en-IN');
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
/**
 * Update expense pie chart data
 */
function updateExpensePieChart(categoryBreakdown) {
    if (!expensePieChart || !categoryBreakdown) return;
    const labels = categoryBreakdown.map(item => item.category);
    const data = categoryBreakdown.map(item => item.total);
    expensePieChart.data.labels = labels;
    expensePieChart.data.datasets[0].data = data;
    expensePieChart.update();
}
/**
 * Update daily trend chart data
 */
function updateDailyTrendChart(dailyTrend) {
    if (!dailyTrendChart || !dailyTrend) return;
    const labels = dailyTrend.map(item => formatDate(item.date, 'short'));
    const data = dailyTrend.map(item => item.total);
    dailyTrendChart.data.labels = labels;
    dailyTrendChart.data.datasets[0].data = data;
    dailyTrendChart.update();
}
// ============================================
// SIDEBAR & NAVIGATION
// ============================================
/**
 * Initialize sidebar functionality
 */
function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarClose = document.getElementById('sidebarClose');
    const mobileOverlay = document.getElementById('mobileOverlay');
    // Toggle sidebar
    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.add('active');
        mobileOverlay.classList.add('active');
    });
    // Close sidebar
    sidebarClose.addEventListener('click', () => {
        sidebar.classList.remove('active');
        mobileOverlay.classList.remove('active');
    });
    // Close on overlay click
    mobileOverlay.addEventListener('click', () => {
        sidebar.classList.remove('active');
        mobileOverlay.classList.remove('active');
    });
}
/**
 * Initialize theme toggle
 */
function initTheme() {
    const themeToggle = document.getElementById('themeToggle');
    const html = document.documentElement;
    const moonIcon = themeToggle.querySelector('.fa-moon');
    const sunIcon = themeToggle.querySelector('.fa-sun');
    // Get saved theme
    const savedTheme = localStorage.getItem('theme') || 'light';
    html.setAttribute('data-theme', savedTheme);
    updateThemeIcons(savedTheme, moonIcon, sunIcon);
    // Toggle theme
    themeToggle.addEventListener('click', () => {
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateThemeIcons(newTheme, moonIcon, sunIcon);
    });
}
/**
 * Update theme toggle icons
 */
function updateThemeIcons(theme, moonIcon, sunIcon) {
    if (theme === 'dark') {
        moonIcon.style.display = 'none';
        sunIcon.style.display = 'block';
    } else {
        moonIcon.style.display = 'block';
        sunIcon.style.display = 'none';
    }
}
/**
 * Set default date for transaction form
 */
function setDefaultDate() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('transDate').value = today;
}
// ============================================
// TRANSACTION MODAL
// ============================================
/**
 * Open add transaction modal
 */
function openAddTransactionModal() {
    document.getElementById('addTransactionModal').classList.add('active');
    setDefaultDate();
}
/**
 * Close add transaction modal
 */
function closeAddTransactionModal() {
    document.getElementById('addTransactionModal').classList.remove('active');
    document.getElementById('addTransactionForm').reset();
    document.getElementById('addTransactionError').style.display = 'none';
    document.getElementById('addTransactionSuccess').style.display = 'none';
    setDefaultDate();
}
/**
 * Submit transaction
 */
async function submitTransaction() {
    const form = document.getElementById('addTransactionForm');
    const formData = new FormData(form);
    // Get CSRF token (would be embedded in page in production)
    const csrfToken = getCookie('csrf_token') || '';
    formData.append('csrf_token', csrfToken);
    // Validate
    if (!validateTransactionForm()) {
        return;
    }
    // Convert FormData to JSON
    const data = {};
    formData.forEach((value, key) => {
        data[key] = value;
    });
    // Add file if present
    const receiptFile = document.getElementById('transReceipt').files[0];
    try {
        const response = await fetch(API_BASE + 'transaction_crud.php?action=create', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.success) {
            showSuccess('Transaction added successfully!');
            closeAddTransactionModal();
            loadDashboardData(); // Refresh dashboard
        } else {
            showError(result.error || 'Failed to add transaction');
        }
    } catch (error) {
        console.error('Submit transaction error:', error);
        showError('Network error. Please try again.');
    }
}
/**
 * Validate transaction form
 */
function validateTransactionForm() {
    const amount = document.getElementById('transAmount').value;
    const category = document.getElementById('transCategory').value;
    const errorDiv = document.getElementById('addTransactionError');
    if (parseFloat(amount) <= 0) {
        errorDiv.textContent = 'Amount must be greater than 0';
        errorDiv.style.display = 'block';
        return false;
    }
    if (!category) {
        errorDiv.textContent = 'Please select a category';
        errorDiv.style.display = 'block';
        return false;
    }
    errorDiv.style.display = 'none';
    return true;
}
// ============================================
// UTILITY FUNCTIONS
// ============================================
/**
 * Format date for display
 */
function formatDate(dateStr, format = 'long') {
    const date = new Date(dateStr);
    const options = format === 'short' ?
        { month: 'short', day: 'numeric' } :
        { year: 'numeric', month: 'short', day: 'numeric' };
    return date.toLocaleDateString('en-IN', options);
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
        'Other Income': '#6ee7b7'
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
        'Other Income': 'fa-plus-circle'
    };
    return icons[category] || 'fa-tag';
}
/**
 * Show error message
 */
function showError(message) {
    // Could use a toast library or custom implementation
    alert('Error: ' + message);
}
/**
 * Show success message
 */
function showSuccess(message) {
    alert('Success: ' + message);
}
/**
 * Get cookie value
 */
function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
}
/**
 * Change chart period
 */
function changeChartPeriod(period) {
    // Would reload data with different period
    console.log('Change period to:', period);
}
/**
 * Logout user
 */
async function logout() {
    if (!confirm('Are you sure you want to logout?')) return;
    try {
        await fetch(API_BASE + 'auth.php?action=logout', {
            method: 'POST',
            credentials: 'same-origin'
        });
        window.location.href = '../../index.php';
    } catch (error) {
        console.error('Logout error:', error);
        window.location.href = '../../index.php';
    }
}
