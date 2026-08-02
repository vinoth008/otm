// frontend/js/admin.js
/**
 * Smart Transaction Control - Admin Panel Logic
 * Handles dashboard, users, transactions, branches, roles, transfers,
 * complaints, notifications, audit, reports, expenses and settings.
 */
let adminChart = null;

// ============================================
// DASHBOARD
// ============================================
async function initAdminDashboard() {
    const loading = document.getElementById('dashboardLoading');
    const grid = document.getElementById('dashboardGrid');
    try {
        const stats = await STC.get('admin_crud.php', 'get_stats');
        document.getElementById('statTotalUsers').textContent = stats.total_users || 0;
        document.getElementById('statCustomers').textContent = (stats.customers || 0) + ' customers';
        document.getElementById('statStaffLine').textContent = (stats.staff_users || 0) + ' staff, ' + (stats.receptionist_users || 0) + ' receptionists';
        document.getElementById('statTotalTransactions').textContent = stats.total_transactions || 0;
        document.getElementById('statNetIncome').textContent = formatCurrency((stats.total_income || 0) - (stats.total_expense || 0));
        document.getElementById('statWalletBalance').textContent = 'Wallet ' + formatCurrency(stats.total_wallet_balance || 0);
        const open = (stats.pending_transfers || 0) + (stats.open_complaints || 0) + (stats.pending_appointments || 0);
        document.getElementById('statOpenItems').textContent = open;
        document.getElementById('statOpenDetails').textContent =
            (stats.pending_transfers || 0) + ' transfers, ' +
            (stats.open_complaints || 0) + ' complaints, ' +
            (stats.pending_appointments || 0) + ' appointments';

        const ctx = document.getElementById('adminHealthChart');
        if (ctx && window.Chart) {
            if (adminChart) adminChart.destroy();
            adminChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Customers', 'Staff', 'Receptionists', 'Admins'],
                    datasets: [{
                        data: [stats.customers || 0, stats.staff_users || 0, stats.receptionist_users || 0, 1],
                        backgroundColor: ['#4f46e5', '#06b6d4', '#10b981', '#f59e0b'],
                        borderWidth: 0
                    }]
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
            });
        }

        const logs = await STC.get('audit_crud.php', 'get_logs');
        const tbody = document.getElementById('adminActivityTable');
        if (tbody) {
            const rows = (logs.logs || []).slice(0, 8);
            if (rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center">No activity yet</td></tr>';
            } else {
                tbody.innerHTML = rows.map(l => `
                    <tr>
                        <td style="font-size: 0.8rem;">${STC.esc(l.user_id ? l.user_id.slice(-6) : 'System')}</td>
                        <td style="font-size: 0.8rem;">${STC.esc(l.action)}</td>
                        <td style="font-size: 0.8rem;">${l.timestamp}</td>
                    </tr>`).join('');
            }
        }
    } catch (e) {
        showError(e.message);
    } finally {
        if (loading) loading.style.display = 'none';
        if (grid) grid.style.display = 'block';
    }
}

// ============================================
// USERS
// ============================================
async function initAdminUsers() {
    const btn = document.getElementById('openAddUserBtn');
    if (btn) btn.addEventListener('click', openAddUserModal);
    const saveBtn = document.getElementById('saveUserBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveNewUser);
    const applyBtn = document.getElementById('applyUserFilters');
    if (applyBtn) applyBtn.addEventListener('click', renderUsers);
    const search = document.getElementById('userSearch');
    if (search) search.addEventListener('keyup', debounce(renderUsers, 400));
    await renderUsers();
}

async function renderUsers() {
    const tbody = document.getElementById('usersTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const params = {};
        const role = document.getElementById('roleFilter');
        const search = document.getElementById('userSearch');
        if (role && role.value) params.role = role.value;
        if (search && search.value) params.search = search.value;
        const data = await STC.get('admin_crud.php', 'get_users', params);
        const users = data.users || [];
        if (users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center">No users found</td></tr>';
            return;
        }
        tbody.innerHTML = users.map(u => `
            <tr>
                <td>${STC.esc(u.first_name)} ${STC.esc(u.last_name)}</td>
                <td>${STC.esc(u.email)}</td>
                <td>${STC.esc(u.phone || '-')}</td>
                <td>
                    <select data-user="${u.user_id}" data-field="role" class="form-control" style="padding: 4px 8px; font-size: 0.8rem;">
                        ${['customer', 'staff', 'receptionist', 'admin'].map(r =>
                            `<option value="${r}" ${u.role === r ? 'selected' : ''}>${r}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select data-user="${u.user_id}" data-field="status" class="form-control" style="padding: 4px 8px; font-size: 0.8rem;">
                        ${['active', 'suspended'].map(s =>
                            `<option value="${s}" ${u.status === s ? 'selected' : ''}>${s}</option>`).join('')}
                    </select>
                </td>
                <td>${u.created_at || '-'}</td>
                <td><button class="btn btn-sm btn-primary" data-save-user="${u.user_id}">Save</button></td>
            </tr>`).join('');
        tbody.querySelectorAll('[data-save-user]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const row = btn.closest('tr');
                const body = { user_id: btn.dataset.saveUser };
                row.querySelectorAll('[data-field]').forEach(sel => {
                    body[sel.dataset.field] = sel.value;
                });
                try {
                    await STC.post('admin_crud.php', 'update_user', body);
                    showSuccess('User updated');
                } catch (e) {
                    showError(e.message);
                }
            });
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

function openAddUserModal() {
    const form = document.getElementById('addUserForm');
    if (form) form.reset();
    openModal('addUserModal');
}

async function saveNewUser() {
    const body = {
        first_name: document.getElementById('nuFirstName').value,
        last_name: document.getElementById('nuLastName').value,
        email: document.getElementById('nuEmail').value,
        phone: document.getElementById('nuPhone').value,
        password: document.getElementById('nuPassword').value,
        role: document.getElementById('nuRole').value
    };
    try {
        await STC.post('admin_crud.php', 'create_user', body);
        closeModal('addUserModal');
        showSuccess('User created successfully');
        renderUsers();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// TRANSACTIONS (ALL USERS)
// ============================================
let adminTxPage = 1;

async function initAdminTransactions() {
    const applyBtn = document.getElementById('applyTxFilters');
    if (applyBtn) applyBtn.addEventListener('click', () => { adminTxPage = 1; renderAdminTransactions(); });
    const prevBtn = document.getElementById('txPrev');
    if (prevBtn) prevBtn.addEventListener('click', () => { if (adminTxPage > 1) { adminTxPage--; renderAdminTransactions(); } });
    const nextBtn = document.getElementById('txNext');
    if (nextBtn) nextBtn.addEventListener('click', () => { adminTxPage++; renderAdminTransactions(); });
    await renderAdminTransactions();
}

async function renderAdminTransactions() {
    const tbody = document.getElementById('adminTxTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const params = { page: adminTxPage, limit: 20 };
        const type = document.getElementById('txTypeFilter');
        const search = document.getElementById('txSearch');
        if (type && type.value) params.type = type.value;
        if (search && search.value) params.search = search.value;
        const data = await STC.get('transaction_crud.php', 'admin_all', params);
        const tx = data.transactions || [];
        if (tx.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center">No transactions found</td></tr>';
        } else {
            tbody.innerHTML = tx.map(t => `
                <tr>
                    <td>${t.date}</td>
                    <td>${t.user_id ? t.user_id.slice(-6) : '-'}</td>
                    <td>${STC.esc(t.category)}</td>
                    <td>${STC.esc(t.description || '-')}</td>
                    <td>${STC.esc(t.payment_method || '-')}</td>
                    <td>${formatCurrency(t.amount)}</td>
                    <td>${STC.badge(t.type)}</td>
                </tr>`).join('');
        }
        const info = document.getElementById('adminTxInfo');
        if (info && data.pagination) {
            info.textContent = `Page ${data.pagination.current_page} of ${data.pagination.total_pages} (${data.pagination.total_count} total)`;
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

// ============================================
// BRANCHES
// ============================================
async function initAdminBranches() {
    const addBtn = document.getElementById('openAddBranchBtn');
    if (addBtn) addBtn.addEventListener('click', () => {
        const form = document.getElementById('addBranchForm');
        if (form) form.reset();
        openModal('addBranchModal');
    });
    const saveBtn = document.getElementById('saveBranchBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveBranch);
    await renderBranches();
}

async function renderBranches() {
    const tbody = document.getElementById('branchesTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const data = await STC.get('branch_crud.php', 'get_all');
        const branches = data.branches || [];
        if (branches.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center">No branches yet</td></tr>';
            return;
        }
        tbody.innerHTML = branches.map(b => `
            <tr>
                <td>${STC.esc(b.name)}</td>
                <td>${STC.esc(b.code)}</td>
                <td>${STC.esc(b.city)}</td>
                <td>${STC.esc(b.address)}</td>
                <td>${STC.esc(b.phone || '-')}</td>
                <td>${STC.badge(b.status)}</td>
                <td>
                    <select data-branch="${b.branch_id}" class="form-control" style="padding: 4px 8px; font-size: 0.8rem;">
                        <option value="active" ${b.status === 'active' ? 'selected' : ''}>active</option>
                        <option value="inactive" ${b.status === 'inactive' ? 'selected' : ''}>inactive</option>
                    </select>
                    <button class="btn btn-sm btn-primary" data-save-branch="${b.branch_id}">Save</button>
                </td>
            </tr>`).join('');
        tbody.querySelectorAll('[data-save-branch]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const row = btn.closest('tr');
                const status = row.querySelector('select').value;
                try {
                    await STC.post('branch_crud.php', 'update', { branch_id: btn.dataset.saveBranch, status });
                    showSuccess('Branch updated');
                } catch (e) {
                    showError(e.message);
                }
            });
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function saveBranch() {
    const g = id => document.getElementById(id).value;
    const body = {
        name: g('nbName'),
        code: g('nbCode'),
        address: g('nbAddress'),
        city: g('nbCity'),
        phone: g('nbPhone'),
        email: g('nbEmail'),
        manager_name: g('nbManager'),
        opening_hours: g('nbHours')
    };
    try {
        await STC.post('branch_crud.php', 'create', body);
        closeModal('addBranchModal');
        showSuccess('Branch created');
        renderBranches();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// ROLES
// ============================================
async function initAdminRoles() {
    const addBtn = document.getElementById('openAddRoleBtn');
    if (addBtn) addBtn.addEventListener('click', () => {
        const form = document.getElementById('addRoleForm');
        if (form) form.reset();
        openModal('addRoleModal');
    });
    const saveBtn = document.getElementById('saveRoleBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveRole);
    await renderRoles();
}

async function renderRoles() {
    const tbody = document.getElementById('rolesTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const data = await STC.get('admin_crud.php', 'get_roles');
        const roles = data.roles || [];
        if (roles.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center">No roles defined</td></tr>';
            return;
        }
        tbody.innerHTML = roles.map(r => `
            <tr>
                <td style="font-weight: 600;">${STC.esc(r.name)}</td>
                <td>${STC.esc(r.description || '-')}</td>
                <td>${(r.permissions || []).map(p => `<span class="badge badge-info">${STC.esc(p)}</span>`).join(' ') || '-'}</td>
                <td>${r.created_at || '-'}</td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function saveRole() {
    const permissions = [];
    document.querySelectorAll('.role-perm:checked').forEach(cb => permissions.push(cb.value));
    const body = {
        name: document.getElementById('nrName').value,
        description: document.getElementById('nrDescription').value,
        permissions
    };
    try {
        await STC.post('admin_crud.php', 'save_role', body);
        closeModal('addRoleModal');
        showSuccess('Role saved');
        renderRoles();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// TRANSFERS (ALL)
// ============================================
async function initAdminTransfers() {
    const applyBtn = document.getElementById('applyTransferFilters');
    if (applyBtn) applyBtn.addEventListener('click', renderAdminTransfers);
    await renderAdminTransfers();
}

async function renderAdminTransfers() {
    const tbody = document.getElementById('adminTransferTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const params = {};
        const status = document.getElementById('transferStatusFilter');
        if (status && status.value) params.status = status.value;
        const data = await STC.get('transfer_crud.php', 'all', params);
        const transfers = data.transfers || [];
        if (transfers.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">No transfers found</td></tr>';
            return;
        }
        tbody.innerHTML = transfers.map(t => `
            <tr>
                <td>${t.created_at}</td>
                <td>${STC.esc(t.type)}</td>
                <td>${formatCurrency(t.amount)}</td>
                <td>${STC.esc(t.description || '-')}</td>
                <td>${STC.badge(t.status)}</td>
                <td>
                    ${t.status === 'pending' || t.status === 'scheduled' ? `
                        <button class="btn btn-sm btn-success" data-tx-approve="${t.transfer_id}">Approve</button>
                        <button class="btn btn-sm btn-danger" data-tx-reject="${t.transfer_id}">Reject</button>` : '-'}
                </td>
            </tr>`).join('');
        tbody.querySelectorAll('[data-tx-approve]').forEach(btn => {
            btn.addEventListener('click', () => changeTransferStatus(btn.dataset.txApprove, 'completed'));
        });
        tbody.querySelectorAll('[data-tx-reject]').forEach(btn => {
            btn.addEventListener('click', () => changeTransferStatus(btn.dataset.txReject, 'rejected'));
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function changeTransferStatus(id, status) {
    try {
        await STC.post('transfer_crud.php', 'update_status', { transfer_id: id, status });
        showSuccess('Transfer ' + status);
        renderAdminTransfers();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// COMPLAINTS
// ============================================
let activeComplaintId = null;

async function initAdminComplaints() {
    const applyBtn = document.getElementById('applyComplaintFilters');
    if (applyBtn) applyBtn.addEventListener('click', renderAdminComplaints);
    const saveBtn = document.getElementById('saveComplaintResponseBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveComplaintResponse);
    await renderAdminComplaints();
}

async function renderAdminComplaints() {
    const tbody = document.getElementById('adminComplaintTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const params = {};
        const status = document.getElementById('complaintStatusFilter');
        if (status && status.value) params.status = status.value;
        const data = await STC.get('complaint_crud.php', 'get_all', params);
        const complaints = data.complaints || [];
        if (complaints.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">No complaints found</td></tr>';
            return;
        }
        tbody.innerHTML = complaints.map(c => `
            <tr>
                <td>${c.created_at}</td>
                <td>${STC.esc(c.subject)}</td>
                <td>${STC.esc(c.category)}</td>
                <td>${STC.badge(c.priority)}</td>
                <td>${STC.badge(c.status)}</td>
                <td><button class="btn btn-sm btn-primary" data-open-complaint="${c.complaint_id}">Handle</button></td>
            </tr>`).join('');
        tbody.querySelectorAll('[data-open-complaint]').forEach(btn => {
            btn.addEventListener('click', () => openComplaintModal(btn.dataset.openComplaint, complaints));
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

function openComplaintModal(id, list) {
    const c = list.find(x => x.complaint_id === id);
    if (!c) return;
    activeComplaintId = id;
    document.getElementById('complaintDetailSubject').textContent = c.subject;
    document.getElementById('complaintDetailDescription').textContent = c.description;
    document.getElementById('complaintResponseStatus').value = c.status;
    document.getElementById('complaintResponseText').value = c.response || '';
    openModal('complaintResponseModal');
}

async function saveComplaintResponse() {
    const body = {
        complaint_id: activeComplaintId,
        status: document.getElementById('complaintResponseStatus').value,
        response: document.getElementById('complaintResponseText').value
    };
    try {
        await STC.post('complaint_crud.php', 'update', body);
        closeModal('complaintResponseModal');
        showSuccess('Complaint updated');
        renderAdminComplaints();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// NOTIFICATIONS (BROADCAST)
// ============================================
async function initAdminNotifications() {
    const sendBtn = document.getElementById('sendNotificationBtn');
    if (sendBtn) sendBtn.addEventListener('click', sendBroadcastNotification);
    const testBtn = document.getElementById('sendTestNotificationBtn');
    if (testBtn) testBtn.addEventListener('click', () => {
        document.getElementById('notifTitle').value = 'Test notification';
        document.getElementById('notifMessage').value = 'This is a test broadcast from the admin panel.';
    });
    await loadRecentNotifications();
}

async function loadRecentNotifications() {
    const tbody = document.getElementById('sentNotificationsTable');
    if (!tbody) return;
    try {
        const data = await STC.get('notification_crud.php', 'get_all');
        const list = (data.notifications || []).slice(0, 10);
        tbody.innerHTML = list.map(n => `
            <tr>
                <td>${STC.esc(n.title)}</td>
                <td>${STC.esc(n.message)}</td>
                <td>${STC.esc(n.type)}</td>
                <td>${n.read ? 'Read' : 'Unread'}</td>
                <td>${n.created_at}</td>
            </tr>`).join('') || '<tr><td colspan="5" class="text-center">No notifications yet</td></tr>';
    } catch (e) { /* ignore */ }
}

async function sendBroadcastNotification() {
    const body = {
        title: document.getElementById('notifTitle').value,
        message: document.getElementById('notifMessage').value,
        type: document.getElementById('notifType').value
    };
    if (!body.title || !body.message) {
        showError('Title and message are required');
        return;
    }
    const btn = document.getElementById('sendNotificationBtn');
    if (btn) btn.disabled = true;
    try {
        const data = await STC.post('notification_crud.php', 'send', body);
        showSuccess('Notification sent to ' + (data.recipients || 0) + ' user(s)');
        document.getElementById('notifTitle').value = '';
        document.getElementById('notifMessage').value = '';
        loadRecentNotifications();
    } catch (e) {
        showError(e.message);
    } finally {
        if (btn) btn.disabled = false;
    }
}

// ============================================
// AUDIT LOGS
// ============================================
async function initAdminAudit() {
    const loadBtn = document.getElementById('loadAuditBtn');
    if (loadBtn) loadBtn.addEventListener('click', renderAuditLogs);
    await renderAuditLogs();
}

async function renderAuditLogs() {
    const tbody = document.getElementById('auditTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const params = {};
        const userId = document.getElementById('auditUserId');
        const action = document.getElementById('auditAction');
        const fromDate = document.getElementById('auditFrom');
        const toDate = document.getElementById('auditTo');
        if (userId && userId.value) params.user_id = userId.value;
        if (action && action.value) params.action = action.value;
        if (fromDate && fromDate.value) params.from_date = fromDate.value;
        if (toDate && toDate.value) params.to_date = toDate.value;
        const data = await STC.get('audit_crud.php', 'get_logs', params);
        const logs = data.logs || [];
        if (logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">No logs found</td></tr>';
            return;
        }
        tbody.innerHTML = logs.map(l => `
            <tr>
                <td>${l.timestamp}</td>
                <td>${l.user_id ? l.user_id.slice(-6) : 'System'}</td>
                <td>${STC.esc(l.action)}</td>
                <td>${STC.esc(JSON.stringify(l.details || {}).slice(0, 60))}</td>
                <td>${STC.esc(l.ip_address || '-')}</td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

// ============================================
// REPORTS
// ============================================
let reportData = null;

async function initAdminReports() {
    const genBtn = document.getElementById('generateReportBtn');
    if (genBtn) genBtn.addEventListener('click', generateReport);
    const exportBtn = document.getElementById('exportReportBtn');
    if (exportBtn) exportBtn.addEventListener('click', exportReportCSV);
    await generateReport();
}

async function generateReport() {
    const type = document.getElementById('reportType').value;
    const fromDate = document.getElementById('reportFrom').value;
    const toDate = document.getElementById('reportTo').value;
    const params = {};
    if (fromDate) params.from_date = fromDate;
    if (toDate) params.to_date = toDate;
    const actionMap = { transactions: 'transactions', transfers: 'transfers', complaints: 'complaints' };
    const tbody = document.getElementById('reportTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        reportData = await STC.get('report_crud.php', actionMap[type] || 'transactions', params);
        const btn = document.getElementById('exportReportBtn');
        if (btn) btn.disabled = false;
        if (type === 'transactions') {
            document.getElementById('reportSummary').innerHTML = `
                <div class="stat-card income" style="padding: 16px;">
                    <div class="stat-card-title">Total Amount</div>
                    <div class="stat-card-value">${formatCurrency(reportData.totals.total_amount)}</div>
                </div>
                <div class="stat-card expense" style="padding: 16px;">
                    <div class="stat-card-title">Transactions</div>
                    <div class="stat-card-value">${reportData.totals.count}</div>
                </div>`;
            tbody.innerHTML = (reportData.categories || []).map(c => `
                <tr>
                    <td>${STC.esc(c.category)}</td>
                    <td>${c.count}</td>
                    <td>${formatCurrency(c.total)}</td>
                    <td></td>
                </tr>`).join('') || '<tr><td colspan="4" class="text-center">No data</td></tr>';
        } else if (type === 'transfers') {
            document.getElementById('reportSummary').innerHTML = '';
            tbody.innerHTML = (reportData.status_breakdown || []).map(s => `
                <tr>
                    <td>${STC.esc(s.status)}</td>
                    <td>${s.count}</td>
                    <td>${formatCurrency(s.total)}</td>
                    <td></td>
                </tr>`).join('') || '<tr><td colspan="4" class="text-center">No data</td></tr>';
        } else {
            document.getElementById('reportSummary').innerHTML = '';
            tbody.innerHTML = (reportData.status_breakdown || []).map(s => `
                <tr>
                    <td>${STC.esc(s.status)}</td>
                    <td>${s.count}</td>
                    <td></td>
                    <td></td>
                </tr>`).join('') || '<tr><td colspan="4" class="text-center">No data</td></tr>';
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center">${STC.esc(e.message)}</td></tr>`;
        const btn = document.getElementById('exportReportBtn');
        if (btn) btn.disabled = true;
    }
}

function exportReportCSV() {
    if (!reportData) return;
    const type = document.getElementById('reportType').value;
    let rows = [];
    if (type === 'transactions') {
        rows = (reportData.categories || []).map(c => ({ category: c.category, count: c.count, total: c.total }));
    } else {
        rows = (reportData.status_breakdown || []).map(s => ({ status: s.status, count: s.count, total: s.total }));
    }
    if (rows.length === 0) {
        showWarning('No data to export');
        return;
    }
    exportToCSV(rows, 'report.csv');
}

// ============================================
// EXPENSES
// ============================================
async function initAdminExpenses() {
    await renderAdminExpenses();
}

async function renderAdminExpenses() {
    const tbody = document.getElementById('adminExpenseTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const data = await STC.get('expense_crud.php', 'get_all');
        const expenses = data.expenses || [];
        if (expenses.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">No expenses recorded</td></tr>';
            return;
        }
        tbody.innerHTML = expenses.map(e => `
            <tr>
                <td>${e.expense_date}</td>
                <td>${STC.esc(e.category)}</td>
                <td>${STC.esc(e.description)}</td>
                <td>${formatCurrency(e.amount)}</td>
                <td>${STC.badge(e.status)}</td>
                <td>
                    ${e.status === 'pending' ? `
                        <button class="btn btn-sm btn-success" data-exp-approve="${e.expense_id}">Approve</button>
                        <button class="btn btn-sm btn-danger" data-exp-reject="${e.expense_id}">Reject</button>` : '-'}
                </td>
            </tr>`).join('');
        tbody.querySelectorAll('[data-exp-approve]').forEach(btn => {
            btn.addEventListener('click', () => changeExpenseStatus(btn.dataset.expApprove, 'approved'));
        });
        tbody.querySelectorAll('[data-exp-reject]').forEach(btn => {
            btn.addEventListener('click', () => changeExpenseStatus(btn.dataset.expReject, 'rejected'));
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function changeExpenseStatus(id, status) {
    try {
        await STC.post('expense_crud.php', 'update_status', { expense_id: id, status });
        showSuccess('Expense ' + status);
        renderAdminExpenses();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// SYSTEM SETTINGS
// ============================================
async function initAdminSettings() {
    const saveBtn = document.getElementById('saveSettingsBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveSystemSettings);
    await renderSystemSettings();
}

async function renderSystemSettings() {
    const tbody = document.getElementById('settingsTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const data = await STC.get('admin_crud.php', 'get_settings');
        const settings = data.settings || [];
        if (settings.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center">No settings defined</td></tr>';
            return;
        }
        tbody.innerHTML = settings.map(s => `
            <tr>
                <td style="font-weight: 600;">${STC.esc(s.key)}</td>
                <td>
                    <input class="form-control setting-input" data-key="${STC.esc(s.key)}" type="text" value="${STC.esc(s.value)}" style="min-width: 260px;">
                </td>
                <td>${STC.esc(s.description || '-')}</td>
                <td>${STC.esc(s.type || 'text')}</td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function saveSystemSettings() {
    const settings = {};
    document.querySelectorAll('.setting-input').forEach(input => {
        settings[input.dataset.key] = input.value;
    });
    if (Object.keys(settings).length === 0) {
        showWarning('No settings to save');
        return;
    }
    try {
        await STC.post('admin_crud.php', 'save_settings', { settings });
        showSuccess('Settings saved');
    } catch (e) {
        showError(e.message);
    }
}
