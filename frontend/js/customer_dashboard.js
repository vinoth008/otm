// frontend/js/customer_dashboard.js
/**
 * Smart Transaction Control - Customer Dashboard Logic
 * Loads real MongoDB data: balance, income, expenses, pending approvals,
 * recent transactions, notifications, charts.
 */
let incomeExpenseChart = null;
let categoryPieChart = null;

document.addEventListener('DOMContentLoaded', async () => {
    await STC.boot({
        title: 'My Dashboard',
        page: 'dashboard.html',
        roles: ['customer', 'admin', 'staff', 'receptionist'],
        init: async (s) => {
            await loadCustomerDashboard();
        }
    });
});

async function loadCustomerDashboard() {
    try {
        // STC.get() returns the inner `data` payload directly
        const [summary, pending, notifications] = await Promise.all([
            STC.get('transaction_crud.php', 'summary'),
            STC.get('approval_crud.php', 'my_transactions', { status: 'pending', limit: 1 }),
            STC.get('notification_crud.php', 'get_all', { limit: 5 })
        ]);
        updateBalanceCards(summary || {});
        updateRecentTransactions(summary.recent_transactions || []);
        updateNotifications(notifications.notifications || []);
        updatePendingApprovals(pending.pagination?.total_count || 0);
        initCharts(summary || {});
        document.getElementById('dashboardLoading').style.display = 'none';
        document.getElementById('dashboardGrid').style.display = 'block';
    } catch (e) {
        console.error('Customer dashboard error:', e);
        STC.showError('Failed to load dashboard data');
        document.getElementById('dashboardLoading').innerHTML = '<div class="text-center p-4"><p class="text-secondary">Unable to load dashboard. <button class="btn btn-sm btn-primary mt-2" onclick="loadCustomerDashboard()">Retry</button></p></div>';
    }
}

function updateBalanceCards(data) {
    const fmt = (n) => '₹' + parseFloat(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const balance = data.balance ?? data.total_savings ?? 0;
    const income = data.total_income ?? 0;
    const expense = data.total_expense ?? 0;
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('currentBalance', fmt(balance));
    set('totalIncome', fmt(income));
    set('totalExpense', fmt(expense));
    set('incomeChange', (data.income_count || 0) + ' transactions');
    set('expenseChange', (data.expense_count || 0) + ' transactions');
    const session = window.STC_SESSION || {};
    set('accountNumber', 'Account #' + (session.account_number || '------'));
}

function updateRecentTransactions(list) {
    const tbody = document.getElementById('recentTransactionsTable');
    if (!tbody) return;
    if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-secondary py-4">No transactions yet</td></tr>';
        return;
    }
    const fmt = (n) => '₹' + parseFloat(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    tbody.innerHTML = list.slice(0, 6).map(t => {
        const isIncome = ['income', 'deposit'].includes(t.type);
        const amountCls = isIncome ? 'text-success' : 'text-danger';
        const sign = isIncome ? '+' : '-';
        return `<tr>
            <td>${STC.esc(t.date || '')}</td>
            <td>${STC.esc(t.description || t.category || 'Transfer')}</td>
            <td>${STC.esc(t.payment_method || '—').toUpperCase()}</td>
            <td>${STC.badge(t.status || 'pending')}</td>
            <td class="text-right ${amountCls}">${sign}${fmt(t.amount)}</td>
        </tr>`;
    }).join('');
}

function updateNotifications(list) {
    const container = document.getElementById('recentNotifications');
    if (!container) return;
    if (!list || list.length === 0) {
        container.innerHTML = '<div class="list-group-item text-center text-secondary py-4">No notifications</div>';
        return;
    }
    container.innerHTML = list.map(n => `
        <div class="list-group-item ${n.read ? '' : 'list-group-item-warning'}">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-semibold">${STC.esc(n.title)}</div>
                    <div class="text-secondary small">${STC.esc(n.message)}</div>
                </div>
                <small class="text-muted">${STC.esc(n.created_at || '')}</small>
            </div>
        </div>
    `).join('');
}

function updatePendingApprovals(count) {
    const el = document.getElementById('pendingApprovals');
    if (el) el.textContent = count || 0;
}

function initCharts(data) {
    // Income vs Expense bar chart
    const chartCtx = document.getElementById('incomeExpenseChart');
    if (chartCtx) {
        const labels = ['Income', 'Expense', 'Net'];
        const values = [
            parseFloat(data.total_income || 0),
            parseFloat(data.total_expense || 0),
            parseFloat((data.total_income || 0) - (data.total_expense || 0))
        ];
        if (incomeExpenseChart) incomeExpenseChart.destroy();
        incomeExpenseChart = new Chart(chartCtx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Amount (₹)',
                    data: values,
                    backgroundColor: ['#10b981', '#ef4444', '#6366f1'],
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }
    // Category breakdown pie
    const pieCtx = document.getElementById('categoryPieChart');
    if (pieCtx) {
        const breakdown = data.category_breakdown || [];
        if (categoryPieChart) categoryPieChart.destroy();
        if (!breakdown.length) {
            pieCtx.parentElement.innerHTML = '<div class="text-center text-secondary py-5">No category data yet</div>';
            return;
        }
        categoryPieChart = new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: breakdown.map(c => c.category || c._id || 'Other'),
                datasets: [{
                    data: breakdown.map(c => c.total || 0),
                    backgroundColor: ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#8b5cf6'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
}