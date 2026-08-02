const API_BASE = '../../backend/php/';

document.addEventListener('DOMContentLoaded', async function() {
    if (!await requireAuth()) return;
    initSidebar();
    initTheme();
    await loadBudgets();
    initGlobalSearch();
});

async function loadBudgets() {
    try {
        const r = await fetch(API_BASE + 'budget_crud.php?action=get_all');
        const d = await r.json();
        if (d.success) {
            renderBudgetTable(d.data.budgets || []);
            updateSummary(d.data.budgets || []);
        } else {
            showError(d.message || 'Failed to load budgets.');
        }
    } catch (e) {
        showError('Unable to reach server. Check your connection.');
    }
}

function updateSummary(budgets) {
    const totalBudget = budgets.reduce((sum, b) => sum + (parseFloat(b.limit) || 0), 0);
    const totalSpent = budgets.reduce((sum, b) => sum + (parseFloat(b.spent) || 0), 0);
    const remaining = totalBudget - totalSpent;
    const utilization = totalBudget > 0 ? Math.round((totalSpent / totalBudget) * 100) : 0;

    document.getElementById('totalBudget').textContent = formatCurrency(totalBudget);
    document.getElementById('totalSpent').textContent = formatCurrency(totalSpent);
    document.getElementById('remainingBudget').textContent = formatCurrency(remaining);
    document.getElementById('budgetUtilization').textContent = utilization + '%';
}

function renderBudgetTable(budgets) {
    const tbody = document.getElementById('budgetTable');
    if (!budgets.length) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7">
                    <div class="empty-state">
                        <i class="fas fa-wallet empty-state-icon"></i>
                        <h4>No budgets yet</h4>
                        <p>Create your first budget to start tracking your spending.</p>
                        <button class="btn btn-primary" onclick="openAddBudgetModal()">
                            <i class="fas fa-plus"></i> Create Budget
                        </button>
                    </div>
                </td>
            </tr>`;
        return;
    }
    tbody.innerHTML = budgets.map(budget => {
        const spent = parseFloat(budget.spent) || 0;
        const limit = parseFloat(budget.limit) || 0;
        const percentage = limit > 0 ? Math.round((spent / limit) * 100) : 0;
        const remaining = limit - spent;
        const isOver = percentage > 100;
        const isWarning = percentage >= parseFloat(budget.alert_threshold) || percentage >= 80;
        const barColor = isOver ? 'var(--danger-color)' : isWarning ? 'var(--warning-color)' : 'var(--success-color)';

        return `
            <tr>
                <td>
                    <div class="category-badge">
                        <i class="fas ${getCategoryIcon(budget.category)}" style="color: ${getCategoryColor(budget.category)};"></i>
                        <span>${budget.category}</span>
                    </div>
                </td>
                <td class="text-primary fw-600">${formatCurrency(limit)}</td>
                <td>${formatCurrency(spent)}</td>
                <td class="${remaining < 0 ? 'text-danger' : 'text-success'}">${formatCurrency(remaining)}</td>
                <td style="min-width: 160px;">
                    <div class="progress-bar" style="margin-bottom: 6px;">
                        <div class="progress-bar-fill" style="width: ${Math.min(percentage, 100)}%; background: ${barColor};"></div>
                    </div>
                    <span style="font-size: 0.75rem; color: var(--text-tertiary);">${percentage}% used</span>
                </td>
                <td>
                    <span class="badge ${isOver ? 'badge-danger' : isWarning ? 'badge-warning' : 'badge-success'}">
                        ${isOver ? 'Over Budget' : isWarning ? 'Warning' : 'On Track'}
                    </span>
                </td>
                <td>
                    <div class="table-actions">
                        <button class="icon-btn" title="Edit" onclick="editBudget('${budget._id}')">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="icon-btn icon-btn-danger" title="Delete" onclick="deleteBudget('${budget._id}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>`;
    }).join('');
}

function openAddBudgetModal() {
    document.getElementById('addBudgetForm').reset();
    document.getElementById('addBudgetModal').classList.add('open');
}

function closeAddBudgetModal() {
    document.getElementById('addBudgetModal').classList.remove('open');
}

async function editBudget(id) {
    try {
        const r = await fetch(API_BASE + 'budget_crud.php?action=get_all');
        const d = await r.json();
        if (!d.success) return;
        const budget = (d.data.budgets || []).find(b => b._id === id);
        if (!budget) return;

        document.getElementById('budgetCategory').value = budget.category;
        document.getElementById('budgetLimit').value = budget.limit;
        document.getElementById('alertThreshold').value = budget.alert_threshold || 80;
        document.getElementById('addBudgetModal').classList.add('open');
        currentBudgetId = id;
    } catch (e) {
        showError('Unable to reach server.');
    }
}

let currentBudgetId = null;

async function saveBudget() {
    const category = document.getElementById('budgetCategory').value;
    const limit = document.getElementById('budgetLimit').value;
    const alertThreshold = document.getElementById('alertThreshold').value || 80;

    if (!category || !limit || parseFloat(limit) <= 0) {
        showError('Please select a category and enter a valid limit.');
        return;
    }

    const action = currentBudgetId ? 'update' : 'create';
    const body = new URLSearchParams();
    if (currentBudgetId) body.append('id', currentBudgetId);
    body.append('category', category);
    body.append('limit', limit);
    body.append('alert_threshold', alertThreshold);

    try {
        const r = await fetch(API_BASE + 'budget_crud.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        });
        const d = await r.json();
        if (d.success) {
            currentBudgetId = null;
            closeAddBudgetModal();
            showSuccess(action === 'update' ? 'Budget updated!' : 'Budget created successfully!');
            await loadBudgets();
        } else {
            showError(d.message || 'Failed to save budget.');
        }
    } catch (e) {
        showError('Unable to reach server. Check your connection.');
    }
}

async function deleteBudget(id) {
    if (!confirm('Are you sure you want to delete this budget?')) return;
    try {
        const r = await fetch(API_BASE + 'budget_crud.php?action=delete&id=' + encodeURIComponent(id));
        const d = await r.json();
        if (d.success) {
            showSuccess('Budget deleted!');
            await loadBudgets();
        } else {
            showError(d.message || 'Failed to delete budget.');
        }
    } catch (e) {
        showError('Unable to reach server.');
    }
}

function openAlertSettings() {
    showInfo('Alert settings panel coming soon. You will be notified when a budget reaches its threshold.');
}

function initGlobalSearch() {
    const input = document.getElementById('globalSearch');
    if (!input) return;
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            window.location.href = 'transactions.html?search=' + encodeURIComponent(input.value);
        }
    });
}

function formatCurrency(amount) {
    return '₹' + parseFloat(amount || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function getCategoryColor(cat) {
    const colors = {
        'Food': '#f97316',
        'Travel': '#fb923c',
        'Shopping': '#fdba74',
        'Bills & Utilities': '#f59e0b',
        'Entertainment': '#a855f7',
        'Medical': '#ef4444',
        'Education': '#3b82f6'
    };
    return colors[cat] || '#6b7280';
}

function getCategoryIcon(cat) {
    const icons = {
        'Food': 'fa-utensils',
        'Travel': 'fa-car',
        'Shopping': 'fa-shopping-bag',
        'Bills & Utilities': 'fa-file-invoice-dollar',
        'Entertainment': 'fa-film',
        'Medical': 'fa-briefcase-medical',
        'Education': 'fa-graduation-cap'
    };
    return icons[cat] || 'fa-tag';
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
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    const closeBtn = document.getElementById('sidebarClose');
    const overlay = document.getElementById('mobileOverlay');

    if (toggle) toggle.addEventListener('click', () => sidebar.classList.add('open'));
    if (closeBtn) closeBtn.addEventListener('click', () => sidebar.classList.remove('open'));
    if (overlay) overlay.addEventListener('click', () => sidebar.classList.remove('open'));
}

function initTheme() {
    const toggleBtn = document.getElementById('themeToggle');
    const root = document.documentElement;
    const savedTheme = localStorage.getItem('theme') || 'light';
    root.setAttribute('data-theme', savedTheme);
    updateThemeIcons(savedTheme);
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            root.setAttribute('data-theme', next);
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
