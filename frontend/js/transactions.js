// frontend/js/transactions.js
/**
 * Smart Transaction Control - Transactions Management
 * Handles all transaction operations: list, filter, search, add, edit, delete
 */
// API Base URL
const API_BASE = '../../backend/php/';
// State
let currentPage = 1;
let currentLimit = 20;
let allTransactions = [];
let selectedTransactions = [];
let currentViewTransaction = null;
let isEditMode = false;
// Categories by type
const categories = {
    expense: ['Food', 'Travel', 'Shopping', 'Bills & Utilities', 'Entertainment', 'Medical', 'Education', 'Rent', 'Utilities', 'Groceries', 'Other'],
    income: ['Salary', 'Bonus', 'Freelance', 'Investment', 'Rental', 'Other Income'],
    transfer: ['Bank Transfer', 'Wallet Transfer', 'Friend', 'Family'],
    loan: ['Personal Loan', 'Home Loan', 'Car Loan', 'Education Loan'],
    borrow: ['From Friend', 'From Family', 'From Bank'],
    lend: ['To Friend', 'To Family', 'To Business'],
    investment: ['Stocks', 'Mutual Funds', 'FD', 'RD', 'Crypto', 'Gold']
};
// ============================================
// INITIALIZATION
// ============================================
// Initialization is deferred until the STC shell has rendered the
// role-based sidebar/navbar. transactions.html calls window.initTransactions()
// from the STC.boot init callback. A fallback timer guards execution when the
// shell has already rendered (e.g., direct navigation).
window.__stcTxnReady = false;
window.initTransactions = function () {
    if (window.__stcTxnReady) return;
    window.__stcTxnReady = true;
    initDateRangePicker();
    setDefaultDate();
    loadTransactions();
    loadCategories();
};
document.addEventListener('DOMContentLoaded', function () {
    // Fallback: if the STC shell finished before this listener ran, start now.
    window.setTimeout(function () {
        if (!window.__stcTxnReady && window.STC && STC.isBooted) {
            window.initTransactions();
        }
    }, 2500);
});
/**
 * Initialize transactions page
 */
async function initTransactionsPage() {
    try {
        // Check authentication
        const sessionData = await checkSession();
        if (!sessionData.is_logged_in) {
            window.location.href = '../../index.php';
            return;
        }
        // Update user info
        updateUserInfo(sessionData);
        // Initialize theme
        initTheme();
        // Initialize sidebar
        initSidebar();
    } catch (error) {
        console.error('Initialization error:', error);
    }
}
/**
 * Initialize date range picker
 */
function initDateRangePicker() {
    flatpickr("#dateRangePicker", {
        mode: "range",
        dateFormat: "Y-m-d",
        defaultDate: [
            new Date().getFullYear() + "-" + (new Date().getMonth() + 1) + "-01",
            new Date()
        ],
        maxDate: "today",
        theme: "light"
    });
}
/**
 * Set default date for transaction form
 */
function setDefaultDate() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('transDate').value = today;
}
/**
 * Load categories from API
 */
async function loadCategories() {
    try {
        const response = await fetch(API_BASE + 'category_crud.php?action=get_all', {
            method: 'GET',
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.success) {
            window.allCategories = data.data.categories;
            updateCategories();
        }
    } catch (error) {
        console.error('Load categories error:', error);
        // Use default categories
        updateCategories();
    }
}
/**
 * Update categories dropdown based on transaction type
 */
function updateCategories() {
    const type = document.getElementById('transType').value;
    const categorySelect = document.getElementById('transCategory');
    const availableCategories = categories[type] || categories.expense;
    categorySelect.innerHTML = '<option value="">Select category</option>' +
        availableCategories.map(cat => `<option value="${cat}">${cat}</option>`).join('');
}
/**
 * Load transactions from API
 */
async function loadTransactions() {
    showLoading(true);
    try {
        // Build query parameters
        const params = new URLSearchParams({
            action: 'get_all',
            page: currentPage,
            limit: currentLimit
        });
        // Add filters
        const filterType = document.getElementById('filterType').value;
        const filterCategory = document.getElementById('filterCategory').value;
        const filterMinAmount = document.getElementById('filterMinAmount').value;
        const filterMaxAmount = document.getElementById('filterMaxAmount').value;
        const filterSort = document.getElementById('filterSort').value;
        const dateRange = document.getElementById('dateRangePicker').value;
        if (filterType) params.append('type', filterType);
        if (filterCategory) params.append('category', filterCategory);
        if (filterMinAmount) params.append('min_amount', filterMinAmount);
        if (filterMaxAmount) params.append('max_amount', filterMaxAmount);
        if (filterSort) params.append('sort', filterSort);
        if (dateRange) {
            const [dateFrom, dateTo] = dateRange.split(' to ');
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);
        }
        // Fetch transactions
        const response = await fetch(API_BASE + 'transaction_crud.php?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.success) {
            allTransactions = data.data.transactions;
            const pagination = data.data.pagination;
            renderTransactionsTable();
            renderTransactionsGrid();
            updatePagination(pagination);
            calculateSummary();
        } else {
            showError('Failed to load transactions');
        }
    } catch (error) {
        console.error('Load transactions error:', error);
        showError('Network error. Please check your connection.');
    } finally {
        showLoading(false);
    }
}
/**
 * Render transactions table
 */
function renderTransactionsTable() {
    const tbody = document.getElementById('transactionsTableBody');
    if (!allTransactions || allTransactions.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center" style="padding: 60px 20px;">
                    <i class="fas fa-receipt" style="font-size: 4rem; color: var(--text-tertiary);"></i>
                    <h3 style="color: var(--text-primary); margin-bottom: 8px;">No transactions found</h3>
                    <p style="color: var(--text-secondary); margin-bottom: 24px;">
                        Start by adding your first transaction
                    </p>
                    <button class="btn btn-primary" onclick="openAddTransactionModal()">
                        <i class="fas fa-plus"></i> Add Transaction
                    </button>
                </td>
            </tr>
        `;
        return;
    }
    tbody.innerHTML = allTransactions.map((trans, index) => `
        <tr>
            <td>
                <input type="checkbox" class="transaction-checkbox"
                    data-id="${trans._id}"
                    onchange="toggleTransactionSelection('${trans._id}')">
            </td>
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
            <td>
                ${trans.tags && trans.tags.length > 0 ?
                    trans.tags.map(tag => `<span class="badge badge-primary" style="margin: 2px;">${tag}</span>`).join(' ') :
                    '-'
                }
            </td>
            <td style="font-weight: 600; color: ${trans.type === 'income' ? 'var(--success-color)' : 'var(--danger-color)'};">
                ${trans.type === 'income' ? '+' : '-'}₹${parseFloat(trans.amount).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </td>
            <td>
                <span class="transaction-type ${trans.type}">
                    ${trans.type}
                </span>
            </td>
            <td>
                <div style="display: flex; gap: 4px;">
                    <button class="btn btn-sm btn-icon" onclick="viewTransaction('${trans._id}')">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-icon" onclick="editTransaction('${trans._id}')">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-icon" onclick="deleteTransactionConfirm('${trans._id}')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}
/**
 * Render transactions grid view
 */
function renderTransactionsGrid() {
    const grid = document.getElementById('gridView');
    if (!allTransactions || allTransactions.length === 0) {
        grid.innerHTML = '';
        return;
    }
    grid.innerHTML = allTransactions.map(trans => `
        <div class="card transaction-card" style="cursor: pointer;" onclick="viewTransaction('${trans._id}')">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                <div class="transaction-category">
                    <div class="category-icon" style="width: 40px; height: 40px; background: ${getCategoryColor(trans.category)}20; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas ${getCategoryIcon(trans.category)}"></i>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: var(--text-primary);">${trans.category}</div>
                        <div style="font-size: 0.75rem; color: var(--text-tertiary);">${formatDate(trans.date)}</div>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 1.25rem; font-weight: 700; color: ${trans.type === 'income' ? 'var(--success-color)' : 'var(--danger-color)'};">
                        ${trans.type === 'income' ? '+' : '-'}₹${parseFloat(trans.amount).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </div>
                    <span class="transaction-type ${trans.type}" style="font-size: 0.75rem;">${trans.type}</span>
                </div>
            </div>
            ${trans.description ? `
                <p style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 12px;">
                    ${trans.description}
                </p>
            ` : ''}
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                <span style="font-size: 0.75rem; color: var(--text-tertiary);">
                    <i class="fas ${getPaymentMethodIcon(trans.payment_method)}"></i>
                    ${formatPaymentMethod(trans.payment_method)}
                </span>
                ${trans.tags && trans.tags.length > 0 ? `
                    <div>
                        ${trans.tags.slice(0, 2).map(tag =>
                            `<span class="badge badge-primary" style="font-size: 0.625rem; margin: 2px;">${tag}</span>`
                        ).join('')}
                        ${trans.tags.length > 2 ? `<span class="badge badge-primary" style="font-size: 0.625rem;">+${trans.tags.length - 2} more</span>` : ''}
                    </div>
                ` : ''}
            </div>
        </div>
    `).join('');
}
/**
 * Update pagination
 */
function updatePagination(pagination) {
    document.getElementById('showingStart').textContent = pagination.total_count > 0 ? ((pagination.current_page - 1) * pagination.per_page) + 1 : 0;
    document.getElementById('showingEnd').textContent = Math.min(pagination.current_page * pagination.per_page, pagination.total_count);
    document.getElementById('totalEntries').textContent = pagination.total_count;
    document.getElementById('currentPage').textContent = `Page ${pagination.current_page}`;
    document.getElementById('prevPage').disabled = pagination.current_page === 1;
    document.getElementById('nextPage').disabled = pagination.current_page === pagination.total_pages;
}
/**
 * Calculate summary from filtered transactions
 */
function calculateSummary() {
    let totalIncome = 0;
    let totalExpense = 0;
    allTransactions.forEach(trans => {
        if (trans.type === 'income') {
            totalIncome += parseFloat(trans.amount);
        } else if (trans.type === 'expense') {
            totalExpense += parseFloat(trans.amount);
        }
    });
    const netBalance = totalIncome - totalExpense;
    document.getElementById('filterTotalIncome').textContent = formatCurrency(totalIncome);
    document.getElementById('filterTotalExpense').textContent = formatCurrency(totalExpense);
    document.getElementById('filterNetBalance').textContent = formatCurrency(netBalance);
    document.getElementById('filterTotalCount').textContent = allTransactions.length;
}
/**
 * Apply filters
 */
function applyFilters() {
    currentPage = 1;
    loadTransactions();
}
/**
 * Clear filters
 */
function clearFilters() {
    document.getElementById('filterType').value = '';
    document.getElementById('filterCategory').value = '';
    document.getElementById('filterMinAmount').value = '';
    document.getElementById('filterMaxAmount').value = '';
    document.getElementById('filterSort').value = 'date_desc';
    document.getElementById('dateRangePicker').value = '';
    currentPage = 1;
    loadTransactions();
}
/**
 * Change page
 */
function changePage(direction) {
    currentPage += direction;
    if (currentPage < 1) currentPage = 1;
    loadTransactions();
}
/**
 * Toggle view mode (table/grid)
 */
function toggleView() {
    const tableView = document.getElementById('tableView');
    const gridView = document.getElementById('gridView');
    const toggleBtn = document.getElementById('toggleView');
    if (tableView.style.display !== 'none') {
        tableView.style.display = 'none';
        gridView.style.display = 'grid';
        toggleBtn.innerHTML = '<i class="fas fa-list"></i> Table View';
    } else {
        tableView.style.display = 'block';
        gridView.style.display = 'none';
        toggleBtn.innerHTML = '<i class="fas fa-th"></i> Grid View';
    }
}
// ============================================
// TRANSACTION OPERATIONS
// ============================================
/**
 * Open add transaction modal
 */
function openAddTransactionModal() {
    isEditMode = false;
    document.getElementById('modalTitle').textContent = 'Add New Transaction';
    document.getElementById('transactionForm').reset();
    document.getElementById('transId').value = '';
    document.getElementById('deleteBtn').style.display = 'none';
    document.getElementById('saveBtnText').textContent = 'Save Transaction';
    document.getElementById('transactionError').style.display = 'none';
    document.getElementById('transactionSuccess').style.display = 'none';
    document.getElementById('existingReceipt').style.display = 'none';
    setDefaultDate();
    updateCategories();
    document.getElementById('transactionModal').classList.add('active');
}
/**
 * Close transaction modal
 */
function closeTransactionModal() {
    document.getElementById('transactionModal').classList.remove('active');
}
/**
 * View transaction details
 */
async function viewTransaction(transactionId) {
    try {
        const response = await fetch(API_BASE + 'transaction_crud.php?action=get&id=' + transactionId, {
            method: 'GET',
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.success) {
            currentViewTransaction = data.data;
            renderTransactionDetails(data.data);
            document.getElementById('viewTransactionModal').classList.add('active');
        } else {
            showError('Failed to load transaction details');
        }
    } catch (error) {
        console.error('View transaction error:', error);
        showError('Network error');
    }
}
/**
 * Render transaction details in view modal
 */
function renderTransactionDetails(trans) {
    const content = document.getElementById('viewTransactionContent');
    content.innerHTML = `
        <div style="display: grid; gap: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div class="transaction-category">
                    <div class="category-icon" style="width: 48px; height: 48px; background: ${getCategoryColor(trans.category)}20; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas ${getCategoryIcon(trans.category)}"></i>
                    </div>
                    <div>
                        <div style="font-size: 1.25rem; font-weight: 600; color: var(--text-primary);">${trans.category}</div>
                        <div style="font-size: 0.875rem; color: var(--text-tertiary);">${formatDate(trans.date)}</div>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 2rem; font-weight: 700; color: ${trans.type === 'income' ? 'var(--success-color)' : 'var(--danger-color)'};">
                        ${trans.type === 'income' ? '+' : '-'}₹${parseFloat(trans.amount).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </div>
                    <span class="transaction-type ${trans.type}">${trans.type}</span>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 16px; background: var(--bg-secondary); border-radius: 8px;">
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-tertiary); margin-bottom: 4px;">Category</div>
                    <div style="font-weight: 500; color: var(--text-primary);">${trans.category}</div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-tertiary); margin-bottom: 4px;">Payment Method</div>
                    <div style="font-weight: 500; color: var(--text-primary);">
                        <i class="fas ${getPaymentMethodIcon(trans.payment_method)}"></i>
                        ${formatPaymentMethod(trans.payment_method)}
                    </div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-tertiary); margin-bottom: 4px;">Date</div>
                    <div style="font-weight: 500; color: var(--text-primary);">${formatDate(trans.date)}</div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-tertiary); margin-bottom: 4px;">Description</div>
                    <div style="font-weight: 500; color: var(--text-primary);">${trans.description || '-'}</div>
                </div>
            </div>
            ${trans.notes ? `
                <div style="padding: 16px; background: var(--bg-secondary); border-radius: 8px;">
                    <div style="font-size: 0.75rem; color: var(--text-tertiary); margin-bottom: 8px;">Notes</div>
                    <div style="color: var(--text-primary); white-space: pre-wrap;">${trans.notes}</div>
                </div>
            ` : ''}
            ${trans.tags && trans.tags.length > 0 ? `
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-tertiary); margin-bottom: 8px;">Tags</div>
                    <div>
                        ${trans.tags.map(tag => `<span class="badge badge-primary" style="margin: 2px;">${tag}</span>`).join(' ')}
                    </div>
                </div>
            ` : ''}
            ${trans.receipt_url ? `
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-tertiary); margin-bottom: 8px;">Receipt</div>
                    <a href="${trans.receipt_url}" target="_blank" class="btn btn-outline btn-sm">
                        <i class="fas fa-file-pdf"></i>
                        View Receipt
                    </a>
                </div>
            ` : ''}
            <div style="padding: 16px; background: var(--bg-tertiary); border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="color: var(--text-tertiary);">Created</span>
                    <span style="color: var(--text-primary);">${trans.created_at}</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-tertiary);">Last Updated</span>
                    <span style="color: var(--text-primary);">${trans.updated_at}</span>
                </div>
            </div>
        </div>
    `;
}
/**
 * Close view transaction modal
 */
function closeViewTransactionModal() {
    document.getElementById('viewTransactionModal').classList.remove('active');
}
/**
 * Edit transaction from view
 */
function editTransactionFromView() {
    closeViewTransactionModal();
    if (currentViewTransaction) {
        editTransaction(currentViewTransaction._id);
    }
}
/**
 * Edit transaction
 */
async function editTransaction(transactionId) {
    try {
        const response = await fetch(API_BASE + 'transaction_crud.php?action=get&id=' + transactionId, {
            method: 'GET',
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.success) {
            const trans = data.data;
            isEditMode = true;
            document.getElementById('modalTitle').textContent = 'Edit Transaction';
            document.getElementById('transId').value = trans._id;
            document.getElementById('deleteBtn').style.display = 'inline-block';
            document.getElementById('saveBtnText').textContent = 'Update Transaction';
            // Fill form
            document.getElementById('transType').value = trans.type;
            updateCategories();
            document.getElementById('transAmount').value = trans.amount;
            document.getElementById('transCategory').value = trans.category;
            document.getElementById('transSubcategory').value = trans.subcategory || '';
            document.getElementById('transDescription').value = trans.description || '';
            document.getElementById('transDate').value = trans.date;
            document.getElementById('transPaymentMethod').value = trans.payment_method;
            document.getElementById('transRecipient').value = trans.recipient_payer || '';
            document.getElementById('transTags').value = trans.tags ? trans.tags.join(', ') : '';
            document.getElementById('transNotes').value = trans.notes || '';
            // Advanced options
            document.getElementById('transIsRecurring').checked = trans.is_recurring || false;
            if (trans.is_recurring) {
                toggleRecurringOptions();
                document.getElementById('transRecurringFrequency').value = trans.recurring_frequency;
            }
            document.getElementById('transIsInstallment').checked = !!trans.installment_total;
            if (trans.installment_total) {
                toggleInstallmentOptions();
                document.getElementById('transInstallmentTotal').value = trans.installment_total;
                document.getElementById('transInstallmentPaid').value = trans.installment_paid;
            }
            document.getElementById('transIsSplit').checked = trans.is_split || false;
            if (trans.is_split) {
                toggleSplitOptions();
                document.getElementById('transSplitWith').value = trans.split_with ? trans.split_with.join(', ') : '';
                document.getElementById('transSplitAmount').value = trans.split_amount;
            }
            // Show existing receipt
            if (trans.receipt_url) {
                document.getElementById('existingReceiptLink').href = trans.receipt_url;
                document.getElementById('existingReceipt').style.display = 'block';
            }
            document.getElementById('transactionError').style.display = 'none';
            document.getElementById('transactionSuccess').style.display = 'none';
            document.getElementById('transactionModal').classList.add('active');
        } else {
            showError('Failed to load transaction');
        }
    } catch (error) {
        console.error('Edit transaction error:', error);
        showError('Network error');
    }
}
/**
 * Submit transaction (create or update)
 */
async function submitTransaction() {
    const form = document.getElementById('transactionForm');
    const formData = new FormData(form);
    // Get CSRF token
    const csrfToken = getCookie('csrf_token') || '';
    formData.append('csrf_token', csrfToken);
    // Validate
    if (!validateTransactionForm()) {
        return;
    }
    // Convert FormData to JSON
    const data = {};
    formData.forEach((value, key) => {
        if (key === 'tags') {
            data[key] = value.split(',').map(t => t.trim()).filter(t => t);
        } else if (key === 'split_with') {
            data[key] = value.split(',').map(s => s.trim()).filter(s => s);
        } else if (key === 'is_recurring' || key === 'is_installment' || key === 'is_split') {
            data[key] = form.querySelector(`[name="${key}"]`).checked;
        } else if (value) {
            data[key] = value;
        }
    });
    // Add ID if editing
    if (isEditMode) {
        data.id = document.getElementById('transId').value;
    }
    try {
        const action = isEditMode ? 'update' : 'create';
        const response = await fetch(API_BASE + 'transaction_crud.php?action=' + action, {
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
            showSuccess(isEditMode ? 'Transaction updated successfully!' : 'Transaction added successfully!');
            closeTransactionModal();
            loadTransactions();
        } else {
            showError(result.error || 'Failed to save transaction');
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
    const errorDiv = document.getElementById('transactionError');
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
/**
 * Delete transaction confirmation
 */
function deleteTransactionConfirm(transactionId) {
    if (!confirm('Are you sure you want to delete this transaction? This action cannot be undone!')) {
        return;
    }
    deleteTransaction(transactionId);
}
/**
 * Delete transaction
 */
async function deleteTransaction(transactionId) {
    try {
        const response = await fetch(API_BASE + 'transaction_crud.php?action=delete', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                id: transactionId,
                csrf_token: getCookie('csrf_token') || ''
            })
        });
        const result = await response.json();
        if (result.success) {
            showSuccess('Transaction deleted successfully!');
            closeTransactionModal();
            closeViewTransactionModal();
            loadTransactions();
        } else {
            showError(result.error || 'Failed to delete transaction');
        }
    } catch (error) {
        console.error('Delete transaction error:', error);
        showError('Network error');
    }
}
// ============================================
// BULK ACTIONS
// ============================================
/**
 * Toggle select all
 */
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.transaction-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = selectAll.checked;
        toggleTransactionSelection(cb.dataset.id);
    });
}
/**
 * Toggle transaction selection
 */
function toggleTransactionSelection(transactionId) {
    const index = selectedTransactions.indexOf(transactionId);
    if (index > -1) {
        selectedTransactions.splice(index, 1);
    } else {
        selectedTransactions.push(transactionId);
    }
    // Show bulk actions if transactions selected
    if (selectedTransactions.length > 0) {
        // Could show bulk actions button
        console.log('Selected:', selectedTransactions.length, 'transactions');
    }
}
/**
 * Export transactions
 */
function exportTransactions() {
    // In production, would call backend to generate CSV/PDF
    const csvContent = generateCSV();
    downloadCSV(csvContent, 'transactions_export.csv');
    showSuccess('Transactions exported successfully!');
}
/**
 * Generate CSV from transactions
 */
function generateCSV() {
    const headers = ['Date', 'Type', 'Category', 'Subcategory', 'Description', 'Amount', 'Payment Method', 'Tags', 'Notes'];
    const rows = allTransactions.map(trans => [
        trans.date,
        trans.type,
        trans.category,
        trans.subcategory || '',
        trans.description || '',
        trans.amount,
        trans.payment_method,
        (trans.tags || []).join('; '),
        trans.notes || ''
    ]);
    return [headers, ...rows].map(row => row.join(',')).join('\n');
}
/**
 * Download CSV file
 */
function downloadCSV(csvContent, filename) {
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
}
// ============================================
// ADVANCED OPTIONS TOGGLES
// ============================================
/**
 * Toggle recurring options
 */
function toggleRecurringOptions() {
    const isRecurring = document.getElementById('transIsRecurring').checked;
    document.getElementById('recurringOptions').style.display = isRecurring ? 'block' : 'none';
}
/**
 * Toggle installment options
 */
function toggleInstallmentOptions() {
    const isInstallment = document.getElementById('transIsInstallment').checked;
    document.getElementById('installmentOptions').style.display = isInstallment ? 'block' : 'none';
}
/**
 * Toggle split options
 */
function toggleSplitOptions() {
    const isSplit = document.getElementById('transIsSplit').checked;
    document.getElementById('splitOptions').style.display = isSplit ? 'block' : 'none';
}
// ============================================
// UTILITY FUNCTIONS (shared with dashboard.js)
// ============================================
// Check session
async function checkSession() {
    try {
        const response = await fetch(API_BASE + 'auth.php?action=session_info', {
            method: 'GET',
            credentials: 'same-origin'
        });
        const data = await response.json();
        return data.data || { is_logged_in: false };
    } catch (error) {
        return { is_logged_in: false };
    }
}
// Update user info (guarded: STC shell renders the navbar user block)
function updateUserInfo(sessionData) {
    const userNameEl = document.getElementById('userName');
    if (userNameEl && sessionData.user_name) {
        userNameEl.textContent = sessionData.user_name;
    }
    const avatarEl = document.getElementById('userAvatar');
    if (avatarEl && sessionData.user_id) {
        const avatarUrl = `https://ui-avatars.com/api/svg?name=${encodeURIComponent(sessionData.user_name)}&color=4f46e5&background=e0e7ff&size=40`;
        avatarEl.src = avatarUrl;
    }
}
// Initialize theme (guarded: STC shell handles theme toggling)
function initTheme() {
    const themeToggle = document.getElementById('themeToggle');
    if (!themeToggle) return;
    const html = document.documentElement;
    const moonIcon = themeToggle.querySelector('.fa-moon');
    const sunIcon = themeToggle.querySelector('.fa-sun');
    const savedTheme = localStorage.getItem('theme') || 'light';
    html.setAttribute('data-theme', savedTheme);
    updateThemeIcons(savedTheme, moonIcon, sunIcon);
    themeToggle.addEventListener('click', () => {
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateThemeIcons(newTheme, moonIcon, sunIcon);
    });
}
function updateThemeIcons(theme, moonIcon, sunIcon) {
    if (theme === 'dark') {
        moonIcon.style.display = 'none';
        sunIcon.style.display = 'block';
    } else {
        moonIcon.style.display = 'block';
        sunIcon.style.display = 'none';
    }
}
// Initialize sidebar (guarded: STC shell renders the role-based sidebar)
function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarClose = document.getElementById('sidebarClose');
    const mobileOverlay = document.getElementById('mobileOverlay');
    if (!sidebar || !sidebarToggle) return;
    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.add('active');
        mobileOverlay.classList.add('active');
    });
    sidebarClose.addEventListener('click', () => {
        sidebar.classList.remove('active');
        mobileOverlay.classList.remove('active');
    });
    mobileOverlay.addEventListener('click', () => {
        sidebar.classList.remove('active');
        mobileOverlay.classList.remove('active');
    });
}
// Show/hide loading
function showLoading(show) {
    document.getElementById('transactionsLoading').style.display = show ? 'block' : 'none';
}
// Show error
function showError(message) {
    alert('Error: ' + message);
}
// Show success
function showSuccess(message) {
    alert('Success: ' + message);
}
// Format currency
function formatCurrency(amount) {
    return '₹' + parseFloat(amount).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}
// Format date
function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-IN', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}
// Format payment method
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
// Get payment method icon
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
// Get category color
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
// Get category icon
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
// Get cookie
function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
}
// Logout
async function logout() {
    if (!confirm('Are you sure you want to logout?')) return;
    try {
        await fetch(API_BASE + 'auth.php?action=logout', {
            method: 'POST',
            credentials: 'same-origin'
        });
    } catch (error) {}
    window.location.href = '../../index.php';
}
