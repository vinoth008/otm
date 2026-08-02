// frontend/js/receptionist.js
/**
 * Smart Transaction Control - Receptionist Desk Logic
 * Handles dashboard, appointments, customers and receipts.
 */
let recActiveId = null;

// ============================================
// DASHBOARD
// ============================================
async function initReceptionistDashboard() {
    const loading = document.getElementById('dashboardLoading');
    const grid = document.getElementById('dashboardGrid');
    try {
        const [appointments, receipts] = await Promise.all([
            STC.get('appointment_crud.php', 'get_all').catch(() => ({ appointments: [] })),
            STC.get('receipt_crud.php', 'get_all').catch(() => ({ receipts: [] }))
        ]);
        const appts = appointments.appointments || [];
        const receiptsList = receipts.receipts || [];
        const today = new Date().toISOString().slice(0, 10);
        const todayAppts = appts.filter(a => a.appointment_date === today);
        const pending = appts.filter(a => a.status === 'pending').length;

        document.getElementById('recTodayAppointments').textContent = todayAppts.length;
        document.getElementById('recTodayLine').textContent = today + ' schedule';
        document.getElementById('recPendingAppointments').textContent = pending;
        document.getElementById('recPendingLine').textContent = appts.length + ' total appointments';
        document.getElementById('recReceiptsIssued').textContent = receiptsList.length;
        document.getElementById('recReceiptsLine').textContent = 'receipts issued';
        document.getElementById('recTotalAppointments').textContent = appts.length;
        document.getElementById('recTotalLine').textContent = (appts.filter(a => a.status === 'completed').length) + ' completed';

        const tbody = document.getElementById('recAppointmentTable');
        if (tbody) {
            const sorted = [...appts].sort((a, b) => b.created_at.localeCompare(a.created_at)).slice(0, 8);
            tbody.innerHTML = sorted.map(a => `
                <tr>
                    <td>${a.appointment_date}</td>
                    <td>${STC.esc(a.appointment_time)}</td>
                    <td>${STC.esc(a.purpose)}</td>
                    <td>${STC.badge(a.status)}</td>
                    <td>
                        ${a.status === 'pending' || a.status === 'confirmed' ? `
                            <button class="btn btn-sm btn-success" data-rapp="${a.appointment_id}" data-rstatus="confirmed">Confirm</button>
                            <button class="btn btn-sm btn-danger" data-rapp="${a.appointment_id}" data-rstatus="cancelled">Cancel</button>` : '-'}
                    </td>
                </tr>`).join('') || '<tr><td colspan="5" class="text-center">No appointments yet</td></tr>';
            tbody.querySelectorAll('[data-rapp]').forEach(btn => {
                btn.addEventListener('click', () => recChangeStatus(btn.dataset.rapp, btn.dataset.rstatus));
            });
        }
    } catch (e) {
        showError(e.message);
    } finally {
        if (loading) loading.style.display = 'none';
        if (grid) grid.style.display = 'block';
    }
}

async function recChangeStatus(id, status) {
    try {
        await STC.post('appointment_crud.php', 'update_status', { appointment_id: id, status });
        showSuccess('Appointment ' + status);
        initReceptionistDashboard();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// APPOINTMENTS
// ============================================
async function initReceptionistAppointments() {
    const applyBtn = document.getElementById('applyAppointmentFilters');
    if (applyBtn) applyBtn.addEventListener('click', renderRecAppointments);
    const saveBtn = document.getElementById('saveAppointmentBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveRecAppointment);
    await loadRecBranches();
    await renderRecAppointments();
}

async function loadRecBranches() {
    try {
        const data = await STC.get('appointment_crud.php', 'branches');
        const select = document.getElementById('raBranch');
        if (select && data.branches) {
            select.innerHTML = data.branches.map(b =>
                `<option value="${b.branch_id}">${STC.esc(b.name)}</option>`).join('');
        }
    } catch (e) { /* ignore */ }
}

async function renderRecAppointments() {
    const tbody = document.getElementById('recAppointmentTableBody');
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
                        <button class="btn btn-sm btn-success" data-rapp="${a.appointment_id}" data-rstatus="confirmed">Confirm</button>
                        <button class="btn btn-sm btn-primary" data-rapp="${a.appointment_id}" data-rstatus="completed">Complete</button>
                        <button class="btn btn-sm btn-danger" data-rapp="${a.appointment_id}" data-rstatus="cancelled">Cancel</button>` : '-'}
                </td>
            </tr>`).join('');
        tbody.querySelectorAll('[data-rapp]').forEach(btn => {
            btn.addEventListener('click', () => recChangeStatus(btn.dataset.rapp, btn.dataset.rstatus));
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function saveRecAppointment() {
    const body = {
        appointment_date: document.getElementById('raDate').value,
        appointment_time: document.getElementById('raTime').value,
        purpose: document.getElementById('raPurpose').value,
        branch_id: document.getElementById('raBranch').value,
        user_id: document.getElementById('raUserId').value
    };
    try {
        await STC.post('appointment_crud.php', 'create', body);
        closeModal('receptionistAppointmentModal');
        showSuccess('Appointment booked');
        renderRecAppointments();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// CUSTOMERS
// ============================================
async function initReceptionistCustomers() {
    const search = document.getElementById('customerSearch');
    if (search) search.addEventListener('keyup', debounce(renderRecCustomers, 400));
    await renderRecCustomers();
}

async function renderRecCustomers() {
    const tbody = document.getElementById('recCustomerTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const params = { role: 'customer' };
        const search = document.getElementById('customerSearch');
        if (search && search.value) params.search = search.value;
        const data = await STC.get('admin_crud.php', 'get_users', params);
        const customers = data.users || [];
        if (customers.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">No customers found</td></tr>';
            return;
        }
        tbody.innerHTML = customers.map(u => `
            <tr>
                <td>${STC.esc(u.first_name)} ${STC.esc(u.last_name)}</td>
                <td>${STC.esc(u.email)}</td>
                <td>${STC.esc(u.phone || '-')}</td>
                <td>${STC.badge(u.status)}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="recIssueReceipt('${u.user_id}')"><i class="fas fa-receipt"></i> Receipt</button>
                </td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

function recIssueReceipt(userId) {
    document.getElementById('rrUserId').value = userId;
    openModal('createReceiptModal');
}

// ============================================
// RECEIPTS
// ============================================
async function initReceptionistReceipts() {
    const saveBtn = document.getElementById('saveReceiptBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveRecReceipt);
    await renderRecReceipts();
}

async function renderRecReceipts() {
    const tbody = document.getElementById('recReceiptTableBody');
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

async function saveRecReceipt() {
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
        renderRecReceipts();
    } catch (e) {
        showError(e.message);
    }
}
