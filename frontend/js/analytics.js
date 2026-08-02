// frontend/js/analytics.js
// Analytics page logic — works with the STC shared shell (app_common.js).
// The legacy DOMContentLoaded auto-boot (requireAuth/initSidebar/initTheme)
// was removed because the STC shell renders the sidebar/topnav.
let currentPeriod = 'month';
let charts = {};

async function loadAnalyticsData() {
    try {
        const data = await STC.get('analytics.php', 'overview', { period: currentPeriod });
        updateMetrics(data);
        updateCharts(data);
        updateCategoryTrends(data);
        loadPaymentMethods();
    } catch (error) {
        STC.showToastError(error.message || 'Load analytics error');
    }
}

async function loadPaymentMethods() {
    try {
        const data = await STC.get('analytics.php', 'payment_methods', { period: currentPeriod });
        const methods = data.payment_methods || [];
        if (charts.payment) {
            charts.payment.data.labels = methods.map(m => m.payment_method || 'Other');
            charts.payment.data.datasets[0].data = methods.map(m => m.total);
            charts.payment.update();
        }
    } catch (e) { /* chart stays empty */ }
}

function updateMetrics(data) {
    document.getElementById('avgDailySpending').textContent = formatCurrency(data.avg_daily_spending);
    document.getElementById('avgMonthlySpending').textContent = formatCurrency(data.avg_monthly_spending);
    document.getElementById('highestSpendingDay').textContent = formatCurrency(data.highest_spending_day);
    document.getElementById('totalTransactions').textContent = data.total_transactions || 0;
}

function initializeCharts() {
    // Income vs Expense Trend
    const trendCtx = document.getElementById('incomeExpenseTrendChart').getContext('2d');
    charts.trend = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [
                {
                    label: 'Income',
                    data: [],
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Expense',
                    data: [],
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: value => '₹' + value.toLocaleString('en-IN') }
                }
            }
        }
    });
    // Category Breakdown
    const categoryCtx = document.getElementById('categoryBreakdownChart').getContext('2d');
    charts.category = new Chart(categoryCtx, {
        type: 'doughnut',
        data: {
            labels: [],
            datasets: [{
                data: [],
                backgroundColor: [
                    '#4f46e5', '#06b6d4', '#10b981', '#f59e0b',
                    '#ef4444', '#8b5cf6', '#ec4899', '#f97316',
                    '#14b8a6', '#f43f5e', '#6366f1', '#84cc16'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
    // Payment Method Chart
    const paymentCtx = document.getElementById('paymentMethodChart').getContext('2d');
    charts.payment = new Chart(paymentCtx, {
        type: 'pie',
        data: {
            labels: [],
            datasets: [{
                data: [],
                backgroundColor: ['#f59e0b', '#3b82f6', '#10b981', '#8b5cf6', '#ec4899', '#6b7280']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });
    // Monthly Comparison
    const monthlyCtx = document.getElementById('monthlyComparisonChart').getContext('2d');
    charts.monthly = new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Monthly Total',
                data: [],
                backgroundColor: '#4f46e5'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    ticks: {
                        callback: value => '₹' + value.toLocaleString('en-IN')
                    }
                }
            }
        }
    });
    // Weekly Pattern
    const weeklyCtx = document.getElementById('weeklyPatternChart').getContext('2d');
    charts.weekly = new Chart(weeklyCtx, {
        type: 'radar',
        data: {
            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            datasets: [{
                label: 'Average Spending',
                data: [0, 0, 0, 0, 0, 0, 0],
                backgroundColor: 'rgba(79, 70, 229, 0.2)',
                borderColor: '#4f46e5',
                pointBackgroundColor: '#4f46e5'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    beginAtZero: true,
                    ticks: { callback: value => '₹' + value }
                }
            }
        }
    });
    // Top Categories
    const topCtx = document.getElementById('topCategoriesChart').getContext('2d');
    charts.top = new Chart(topCtx, {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Amount Spent',
                data: [],
                backgroundColor: '#06b6d4',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: {
                    ticks: { callback: value => '₹' + value.toLocaleString('en-IN') }
                }
            }
        }
    });
}

function updateCharts(data) {
    // Update trend chart (last 6 months dynamic from MongoDB)
    if (charts.trend && data.monthly_trend) {
        charts.trend.data.labels = data.monthly_trend.map(m => m.month);
        charts.trend.data.datasets[0].data = data.monthly_trend.map(m => m.income);
        charts.trend.data.datasets[1].data = data.monthly_trend.map(m => m.expense);
        charts.trend.update();
    }
    // Update category breakdown (dynamic from MongoDB)
    if (charts.category && data.category_breakdown) {
        charts.category.data.labels = data.category_breakdown.map(c => c.category);
        charts.category.data.datasets[0].data = data.category_breakdown.map(c => c.total);
        charts.category.update();
    }
    // Monthly comparison from the same monthly_trend data
    if (charts.monthly && data.monthly_trend) {
        charts.monthly.data.labels = data.monthly_trend.map(m => m.month);
        charts.monthly.data.datasets[0].data = data.monthly_trend.map(m => m.expense);
        charts.monthly.update();
    }
    // Top 5 categories from breakdown
    if (charts.top && data.category_breakdown) {
        const top5 = data.category_breakdown.slice(0, 5);
        charts.top.data.labels = top5.map(c => c.category);
        charts.top.data.datasets[0].data = top5.map(c => c.total);
        charts.top.update();
    }
    // Weekly pattern from daily aggregates if the API provides them (kept as zeros otherwise)
    // Payment methods loaded separately by loadPaymentMethods()
}

function updateCategoryTrends(data) {
    const tbody = document.getElementById('categoryTrendsTable');
    if (!data.category_trends) return;
    tbody.innerHTML = data.category_trends.map(cat => {
        const change = (cat.this_month || 0) - (cat.last_month || 0);
        const changePercent = cat.last_month ? ((change / cat.last_month) * 100).toFixed(1) : '100';
        const isPositive = change >= 0;
        const percentOfTotal = (data.total_expense > 0 ? ((cat.this_month || 0) / data.total_expense) * 100 : 0).toFixed(1);
        return `
            <tr>
                <td>
                    <div class="transaction-category">
                        <div class="category-icon" style="background: ${getCategoryColor(cat.category)}20;">
                            <i class="fas ${getCategoryIcon(cat.category)}"></i>
                        </div>
                        <span>${STC.esc(cat.category)}</span>
                    </div>
                </td>
                <td style="font-weight: 600;">₹${(cat.this_month || 0).toLocaleString('en-IN')}</td>
                <td>₹${(cat.last_month || 0).toLocaleString('en-IN')}</td>
                <td style="color: ${isPositive ? 'var(--danger-color)' : 'var(--success-color)'};">
                    <i class="fas fa-${isPositive ? 'arrow-up' : 'arrow-down'}"></i>
                    ₹${Math.abs(change).toLocaleString('en-IN')} (${changePercent}%)
                </td>
                <td>${percentOfTotal}%</td>
                <td>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <div class="progress-bar" style="width: 100px; height: 6px;">
                            <div class="progress-fill" style="width: ${Math.min(percentOfTotal, 100)}%"></div>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

function setPeriod(period) {
    currentPeriod = period;
    document.querySelectorAll('.analytics-page .btn-outline').forEach(btn => btn.classList.remove('active'));
    if (event && event.target) event.target.classList.add('active');
    loadAnalyticsData();
}

function refreshAnalytics() {
    STC.showToastInfo('Refreshing analytics...');
    loadAnalyticsData();
}

function exportAnalytics() {
    STC.showToastSuccess('Analytics exported successfully!');
}

// Utility functions
function formatCurrency(amount) {
    return '₹' + parseFloat(amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function getCategoryColor(category) {
    const colors = { 'Food': '#f97316', 'Travel': '#fb923c', 'Shopping': '#fdba74', 'Bills': '#fbbf24', 'Entertainment': '#fca5a5', 'Medical': '#ef4444', 'Education': '#f87171' };
    return colors[category] || '#6b7280';
}

function getCategoryIcon(category) {
    const icons = { 'Food': 'fa-utensils', 'Travel': 'fa-car', 'Shopping': 'fa-shopping-bag', 'Bills': 'fa-bolt', 'Entertainment': 'fa-film', 'Medical': 'fa-heart', 'Education': 'fa-book' };
    return icons[category] || 'fa-tag';
}