// frontend/js/staff.js
/**
 * Smart Transaction Control - Staff Panel Logic
 * Handles dashboard, customers, transfers, complaints, beneficiaries,
 * receipts, expenses and appointments.
 */
let staffTxActiveId = null;

// ============================================
// DASHBOARD
// ============================================
async function initStaffDashboard() {
    const loading = document.getElementById('dashboardLoading');
    const grid = document.getElementById('dashboardGrid');
    try {
        const [users, transfers, complaints, appointments, receipts] = await Promise.all([
            STC.get('admin_crud.php', 'get_users', { role: 'customer' }).catch(() => ({ users: [] })),
            STC.get('transfer_crud.php', 'all').catch(() => ({ transfers: [] })),
            STC.get('complaint_crud.php', 'summary').catch(() => ({})),
            STC.get('appointment_crud.php', 'get_all').catch(() => ({ appointments: [] })),
            STC.get('receipt_crud.php', 'get_all').catch(() => ({ receipts: [] }))
        ]);
        const customers = users.users || [];
        const transfersList = transfers.transfers || [];
        const appts = appointments.appointments || [];
        const receiptsList = receipts.receipts || [];
        const pendingTransfers = transfersList.filter(t => t.status === 'pending' || t.status === 'scheduled').length;
        const todayAppts = appts.filter(a => a.appointment_date === new Date().toISOString().slice(0, 10)).length;

        document.getElementById('staffTotalCustomers').textContent = customers.length;
        document.getElementById('staffCustomerLine').textContent = 'registered customers';
        document.getElementById('staffPendingTransfers').textContent = pendingTransfers;
        document.getElementById('staffTransferLine').textContent = transfersList.length + ' total transfer requests';
        document.getElementById('staffOpenComplaints').textContent = (complaints.open || 0) + (complaints.in_progress || 0);
        document.getElementById('staffComplaintLine').textContent = (complaints.resolved || 0) + ' resolved';
        document.getElementById('staffTodayAppointments').textContent = todayAppts;
        document.getElementById('staffAppointmentLine').textContent = appts.length + ' total, ' + receiptsList.length + ' receipts issued';

        const recent = [...transfersList].sort((a, b) => b.created_at.localeCompare(a.created_at)).slice(0, 8);
        const tbody = document.getElementById('staffRecentTable');
        if (tbody) {
            tbody.innerHTML = recent.map(t => `
                <tr>
                    <td>${t.created_at}</td>
                    <td>${STC.esc(t.type)}</td>
                    <td>${formatCurrency(t.amount)}</td>
                    <td>${STC.esc(t.description || '-')}</td>
                    <td>${STC.badge(t.status)}</td>
                </tr>`).join('') || '<tr><td colspan="5" class="text-center">No transfers yet</td></tr>';
        }
    } catch (e) {
        showError(e.message);
    } finally {
        if (loading) loading.style.display = 'none';
        if (grid) grid.style.display = 'block';
    }
}

// ============================================
// CUSTOMERS
// ============================================
async function initStaffCustomers() {
    const addBtn = document.getElementById('openAddCustomerBtn');
    if (addBtn) addBtn.addEventListener('click', () => {
        const form = document.getElementById('addCustomerForm');
        if (form) form.reset();
        openModal('addCustomerModal');
    });
    const saveBtn = document.getElementById('saveCustomerBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveCustomer);
    const search = document.getElementById('customerSearch');
    if (search) search.addEventListener('keyup', debounce(renderStaffCustomers, 400));
    await renderStaffCustomers();
}

async function renderStaffCustomers() {
    const tbody = document.getElementById('staffCustomerTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const params = { role: 'customer' };
        const search = document.getElementById('customerSearch');
        if (search && search.value) params.search = search.value;
        const data = await STC.get('admin_crud.php', 'get_users', params);
        const customers = data.users || [];
        if (customers.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">No customers found</td></tr>';
            return;
        }
        tbody.innerHTML = customers.map(u => `
            <tr>
                <td>${STC.esc(u.first_name)} ${STC.esc(u.last_name)}</td>
                <td>${STC.esc(u.email)}</td>
                <td>${STC.esc(u.phone || '-')}</td>
                <td>${STC.badge(u.status)}</td>
                <td>${u.created_at || '-'}</td>
                <td>
                    <button class="btn btn-sm btn-outline" onclick="issueReceiptFor('${u.user_id}')">Receipt</button>
                    <button class="btn btn-sm btn-primary" onclick="bookAppointmentFor('${u.user_id}')">Appointment</button>
                </td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function saveCustomer() {
    const body = {
        first_name: document.getElementById('scFirstName').value,
        last_name: document.getElementById('scLastName').value,
        email: document.getElementById('scEmail').value,
        phone: document.getElementById('scPhone').value,
        password: document.getElementById('scPassword').value,
        role: 'customer'
    };
    try {
        await STC.post('admin_crud.php', 'create_user', body);
        closeModal('addCustomerModal');
        showSuccess('Customer created');
        renderStaffCustomers();
    } catch (e) {
        showError(e.message);
    }
}

function issueReceiptFor(userId) {
    document.getElementById('rrUserId').value = userId;
    openModal('createReceiptModal');
    document.getElementById('rrUserName').textContent = 'Selected customer: ' + userId;
}

function bookAppointmentFor(userId) {
    document.getElementById('saUserId').value = userId;
    openModal('staffAppointmentModal');
}

// ============================================
// TRANSFERS (APPROVE/REJECT)
// ============================================
async function initStaffTransfers() {
    const applyBtn = document.getElementById('applyTransferFilters');
    if (applyBtn) applyBtn.addEventListener('click', renderStaffTransfers);
    await renderStaffTransfers();
}

async function renderStaffTransfers() {
    const tbody = document.getElementById('staffTransferTableBody');
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
                        <button class="btn btn-sm btn-success" data-stx-approve="${t.transfer_id}">Approve</button>
                        <button class="btn btn-sm btn-danger" data-stx-reject="${t.transfer_id}">Reject</button>` : '-'}
                </td>
            </tr>`).join('');
        tbody.querySelectorAll('[data-stx-approve]').forEach(btn => {
            btn.addEventListener('click', () => changeStaffTransferStatus(btn.dataset.stxApprove, 'completed'));
        });
        tbody.querySelectorAll('[data-stx-reject]').forEach(btn => {
            btn.addEventListener('click', () => changeStaffTransferStatus(btn.dataset.stxReject, 'rejected'));
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function changeStaffTransferStatus(id, status) {
    try {
        await STC.post('transfer_crud.php', 'update_status', { transfer_id: id, status });
        showSuccess('Transfer ' + status);
        renderStaffTransfers();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// COMPLAINTS
// ============================================
async function initStaffComplaints() {
    const applyBtn = document.getElementById('applyComplaintFilters');
    if (applyBtn) applyBtn.addEventListener('click', renderStaffComplaints);
    const saveBtn = document.getElementById('saveComplaintResponseBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveStaffComplaintResponse);
    await renderStaffComplaints();
}

async function renderStaffComplaints() {
    const tbody = document.getElementById('staffComplaintTableBody');
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
                <td><button class="btn btn-sm btn-primary" data-sopen="${c.complaint_id}">Handle</button></td>
            </tr>`).join('');
        tbody.querySelectorAll('[data-sopen]').forEach(btn => {
            btn.addEventListener('click', () => openStaffComplaint(btn.dataset.sopen, complaints));
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

function openStaffComplaint(id, list) {
    const c = list.find(x => x.complaint_id === id);
    if (!c) return;
    staffTxActiveId = id;
    document.getElementById('complaintDetailSubject').textContent = c.subject;
    document.getElementById('complaintDetailDescription').textContent = c.description;
    document.getElementById('complaintResponseStatus').value = c.status;
    document.getElementById('complaintResponseText').value = c.response || '';
    openModal('complaintResponseModal');
}

async function saveStaffComplaintResponse() {
    const body = {
        complaint_id: staffTxActiveId,
        status: document.getElementById('complaintResponseStatus').value,
        response: document.getElementById('complaintResponseText').value
    };
    try {
        await STC.post('complaint_crud.php', 'update', body);
        closeModal('complaintResponseModal');
        showSuccess('Complaint updated');
        renderStaffComplaints();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// BENEFICIARIES (ALL)
// ============================================
async function initStaffBeneficiaries() {
    const tbody = document.getElementById('staffBeneficiaryTableBody');
    if (!tbody) return;
    try {
        const data = await STC.get('beneficiary_crud.php', 'get_all').catch(() => ({ beneficiaries: [] }));
        const list = data.beneficiaries || [];
        tbody.innerHTML = list.map(b => `
            <tr>
                <td>${STC.esc(b.name)}</td>
                <td>${STC.esc(b.email || '-')}</td>
                <td>${STC.esc(b.phone || '-')}</td>
                <td>${STC.esc(b.bank_name || '-')}</td>
                <td>${STC.esc(b.account_number || '-')}</td>
                <td>${STC.esc(b.ifsc_code || '-')}</td>
            </tr>`).join('') || '<tr><td colspan="6" class="text-center">No beneficiaries yet</td></tr>';
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

// ============================================
// RECEIPTS
// ============================================
async function initStaffReceipts() {
    const createBtn = document.getElementById('openCreateReceiptBtn');
    if (createBtn) createBtn.addEventListener('click', () => {
        const form = document.getElementById('createReceiptForm');
        if (form) form.reset();
        openModal('createReceiptModal');
    });
    const saveBtn = document.getElementById('saveReceiptBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveReceipt);
    await renderStaffReceipts();
}

async function renderStaffReceipts() {
    const tbody = document.getElementById('staffReceiptTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const data = await STC.get('receipt_crud.php', 'get_all');
        const receipts = data.receipts || [];
        if (receipts.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">No receipts issued</td></tr>';
            return;
        }
        tbody.innerHTML = receipts.map(r => `
            <tr>
                <td>${r.created_at}</td>
                <td>${STC.esc(r.receipt_number)}</td>
                <td>${r.user_id ? r.user_id.slice(-6) : '-'}</td>
                <td>${formatCurrency(r.amount)}</td>
                <td>${STC.esc(r.payment_method)}</td>
                <td>${STC.esc(r.description || '-')}</td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function saveReceipt() {
    const body = {
        user_id: document.getElementById('rrUserId').value,
        amount: document.getElementById('rrAmount').value,
        description: document.getElementById('rrDescription').value,
        payment_method: document.getElementById('rrPaymentMethod').value
    };
    if (!body.user_id || !body.amount) {
        showError('Select a customer and enter an amount');
        return;
    }
    try {
        const data = await STC.post('receipt_crud.php', 'create', body);
        closeModal('createReceiptModal');
        showSuccess('Receipt ' + data.receipt_number + ' generated');
        renderStaffReceipts();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// EXPENSES
// ============================================
async function initStaffExpenses() {
    const addBtn = document.getElementById('openAddExpenseBtn');
    if (addBtn) addBtn.addEventListener('click', () => {
        const form = document.getElementById('addExpenseForm');
        if (form) form.reset();
        document.getElementById('seDate').value = new Date().toISOString().slice(0, 10);
        openModal('addExpenseModal');
    });
    const saveBtn = document.getElementById('saveExpenseBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveStaffExpense);
    await renderStaffExpenses();
}

async function renderStaffExpenses() {
    const tbody = document.getElementById('staffExpenseTableBody');
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
                <td>${e.created_at}</td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function saveStaffExpense() {
    const body = {
        category: document.getElementById('seCategory').value,
        description: document.getElementById('seDescription').value,
        amount: document.getElementById('seAmount').value,
        expense_date: document.getElementById('seDate').value
    };
    try {
        await STC.post('expense_crud.php', 'create', body);
        closeModal('addExpenseModal');
        showSuccess('Expense recorded');
        renderStaffExpenses();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// APPOINTMENTS
// ============================================
async function initStaffAppointments() {
    const applyBtn = document.getElementById('applyAppointmentFilters');
    if (applyBtn) applyBtn.addEventListener('click', renderStaffAppointments);
    const saveBtn = document.getElementById('saveAppointmentBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveStaffAppointment);
    await loadBranches();
    await renderStaffAppointments();
}

async function loadBranches() {
    try {
        const data = await STC.get('appointment_crud.php', 'branches');
        const select = document.getElementById('saBranch');
        if (select && data.branches) {
            select.innerHTML = data.branches.map(b =>
                `<option value="${b.branch_id}">${STC.esc(b.name)}</option>`).join('');
        }
    } catch (e) { /* ignore */ }
}

async function renderStaffAppointments() {
    const tbody = document.getElementById('staffAppointmentTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const params = {};
        const status = document.getElementById('appointmentStatusFilter');
        if (status && status.value) params.status = status.value;
        const data = await STC.get('appointment_crud.php', 'get_all', params);
        const appts = data.appointments || [];
        if (appts.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">No appointments found</td></tr>';
            return;
        }
        tbody.innerHTML = appts.map(a => `
            <tr>
                <td>${a.appointment_date}</td>
                <td>${STC.esc(a.appointment_time)}</td>
                <td>${a.user_id ? a.user_id.slice(-6) : '-'}</td>
                <td>${STC.esc(a.purpose)}</td>
                <td>${STC.badge(a.status)}</td>
                <td>
                    ${a.status === 'pending' || a.status === 'confirmed' ? `
                        <button class="btn btn-sm btn-success" data-sapp="${a.appointment_id}" data-sstatus="confirmed">Confirm</button>
                        <button class="btn btn-sm btn-primary" data-sapp="${a.appointment_id}" data-sstatus="completed">Complete</button>
                        <button class="btn btn-sm btn-danger" data-sapp="${a.appointment_id}" data-sstatus="cancelled">Cancel</button>` : '-'}
                </td>
            </tr>`).join('');
        tbody.querySelectorAll('[data-sapp]').forEach(btn => {
            btn.addEventListener('click', () => changeAppointmentStatus(btn.dataset.sapp, btn.dataset.sstatus));
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function changeAppointmentStatus(id, status) {
    try {
        await STC.post('appointment_crud.php', 'update_status', { appointment_id: id, status });
        showSuccess('Appointment ' + status);
        renderStaffAppointments();
    } catch (e) {
        showError(e.message);
    }
}

async function saveStaffAppointment() {
    const body = {
        appointment_date: document.getElementById('saDate').value,
        appointment_time: document.getElementById('saTime').value,
        purpose: document.getElementById('saPurpose').value,
        branch_id: document.getElementById('saBranch').value,
        user_id: document.getElementById('saUserId').value
    };
    try {
        await STC.post('appointment_crud.php', 'create', body);
        closeModal('staffAppointmentModal');
        showSuccess('Appointment booked');
        renderStaffAppointments();
    } catch (e) {
        showError(e.message);
    }
}
