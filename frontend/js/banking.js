// frontend/js/banking.js
/**
 * Smart Transaction Control - Customer Banking Logic
 * Handles wallet, transfers, beneficiaries, receipts, notes,
 * complaints and appointments for customers.
 */
let bankActiveNoteId = null;

// ============================================
// WALLET
// ============================================
async function initWallet() {
    const topupBtn = document.getElementById('openTopupBtn');
    if (topupBtn) topupBtn.addEventListener('click', () => {
        const form = document.getElementById('topupForm');
        if (form) form.reset();
        openModal('topupModal');
    });
    const saveBtn = document.getElementById('saveTopupBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveTopup);
    const sendBtn = document.getElementById('walletSendBtn');
    if (sendBtn) sendBtn.addEventListener('click', sendWalletTransfer);
    await loadWallet();
}

async function loadWallet() {
    try {
        const [balance, history] = await Promise.all([
            STC.get('wallet_crud.php', 'get_balance'),
            STC.get('wallet_crud.php', 'history')
        ]);
        document.getElementById('walletBalance').textContent = formatCurrency(balance.balance);
        document.getElementById('walletCurrency').textContent = balance.currency || 'INR';
        document.getElementById('walletIncoming').textContent = balance.incoming_transfers || 0;
        document.getElementById('walletOutgoing').textContent = balance.outgoing_transfers || 0;
        const tbody = document.getElementById('walletHistoryTable');
        if (tbody) {
            const historyList = history.history || [];
            tbody.innerHTML = historyList.map(h => `
                <tr>
                    <td>${h.created_at}</td>
                    <td>${STC.esc(h.type)}</td>
                    <td>${h.direction === 'out' ? 'Outgoing' : 'Incoming'}</td>
                    <td>${STC.esc(h.description || '-')}</td>
                    <td>${h.direction === 'out' ? '-' : '+'}${formatCurrency(h.amount)}</td>
                    <td>${STC.badge(h.status)}</td>
                </tr>`).join('') || '<tr><td colspan="6" class="text-center">No wallet activity yet</td></tr>';
        }
    } catch (e) {
        showError(e.message);
    }
}

async function saveTopup() {
    const body = {
        amount: document.getElementById('topupAmount').value,
        description: document.getElementById('topupDescription').value
    };
    try {
        await STC.post('wallet_crud.php', 'topup', body);
        closeModal('topupModal');
        showSuccess('Wallet topped up');
        loadWallet();
    } catch (e) {
        showError(e.message);
    }
}

async function sendWalletTransfer() {
    const body = {
        recipient_email: document.getElementById('walletRecipientEmail').value,
        amount: document.getElementById('walletTransferAmount').value,
        description: document.getElementById('walletTransferDescription').value
    };
    if (!body.recipient_email || !body.amount) {
        showError('Recipient email and amount are required');
        return;
    }
    try {
        await STC.post('wallet_crud.php', 'transfer', body);
        showSuccess('Transfer completed');
        document.getElementById('walletRecipientEmail').value = '';
        document.getElementById('walletTransferAmount').value = '';
        document.getElementById('walletTransferDescription').value = '';
        loadWallet();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// TRANSFERS (REQUESTS)
// ============================================
async function initTransfers() {
    const createBtn = document.getElementById('openTransferBtn');
    if (createBtn) createBtn.addEventListener('click', () => {
        const form = document.getElementById('createTransferForm');
        if (form) form.reset();
        openModal('createTransferModal');
    });
    const saveBtn = document.getElementById('saveTransferBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveTransferRequest);
    await loadTransfers();
}

async function loadTransfers() {
    try {
        const [data, summary] = await Promise.all([
            STC.get('transfer_crud.php', 'get_all'),
            STC.get('transfer_crud.php', 'summary')
        ]);
        document.getElementById('trPending').textContent = summary.pending || 0;
        document.getElementById('trCompleted').textContent = summary.completed || 0;
        document.getElementById('trScheduled').textContent = summary.scheduled || 0;
        document.getElementById('trTotalSent').textContent = formatCurrency(summary.total_sent || 0);
        const tbody = document.getElementById('transferListTable');
        if (tbody) {
            const list = data.transfers || [];
            tbody.innerHTML = list.map(t => `
                <tr>
                    <td>${t.created_at}</td>
                    <td>${t.direction === 'out' ? 'Sent' : 'Received'}</td>
                    <td>${STC.esc(t.type)}</td>
                    <td>${t.direction === 'out' ? '-' : '+'}${formatCurrency(t.amount)}</td>
                    <td>${STC.esc(t.description || '-')}</td>
                    <td>${STC.badge(t.status)}</td>
                </tr>`).join('') || '<tr><td colspan="6" class="text-center">No transfer requests yet</td></tr>';
        }
    } catch (e) {
        showError(e.message);
    }
}

async function saveTransferRequest() {
    const body = {
        recipient_email: document.getElementById('trRecipientEmail').value,
        amount: document.getElementById('trAmount').value,
        type: document.getElementById('trType').value,
        description: document.getElementById('trDescription').value
    };
    if (body.type === 'scheduled') {
        body.schedule_date = document.getElementById('trScheduleDate').value;
    }
    if (!body.recipient_email || !body.amount) {
        showError('Recipient email and amount are required');
        return;
    }
    try {
        await STC.post('transfer_crud.php', 'create', body);
        closeModal('createTransferModal');
        showSuccess('Transfer request submitted for approval');
        loadTransfers();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// BENEFICIARIES
// ============================================
async function initBeneficiaries() {
    const addBtn = document.getElementById('openAddBeneficiaryBtn');
    if (addBtn) addBtn.addEventListener('click', () => {
        const form = document.getElementById('addBeneficiaryForm');
        if (form) form.reset();
        openModal('addBeneficiaryModal');
    });
    const saveBtn = document.getElementById('saveBeneficiaryBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveBeneficiary);
    await loadBeneficiaries();
}

async function loadBeneficiaries() {
    const tbody = document.getElementById('beneficiaryTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const data = await STC.get('beneficiary_crud.php', 'get_all');
        const list = data.beneficiaries || [];
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center">No beneficiaries added yet</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(b => `
            <tr>
                <td>${STC.esc(b.nickname || b.name)}</td>
                <td>${STC.esc(b.name)}</td>
                <td>${STC.esc(b.email || '-')}</td>
                <td>${STC.esc(b.phone || '-')}</td>
                <td>${STC.esc(b.bank_name || '-')}</td>
                <td>${STC.esc(b.account_number || '-')}</td>
                <td>
                    <button class="btn btn-sm btn-outline" onclick="prefillTransfer('${STC.esc(b.email)}')"><i class="fas fa-paper-plane"></i> Transfer</button>
                    <button class="btn btn-sm btn-danger" data-del-ben="${b.beneficiary_id}"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`).join('');
        tbody.querySelectorAll('[data-del-ben]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Delete this beneficiary?')) return;
                try {
                    await STC.post('beneficiary_crud.php', 'delete', { beneficiary_id: btn.dataset.delBen });
                    showSuccess('Beneficiary deleted');
                    loadBeneficiaries();
                } catch (e) {
                    showError(e.message);
                }
            });
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function saveBeneficiary() {
    const body = {
        name: document.getElementById('bfName').value,
        email: document.getElementById('bfEmail').value,
        phone: document.getElementById('bfPhone').value,
        bank_name: document.getElementById('bfBankName').value,
        account_number: document.getElementById('bfAccountNumber').value,
        ifsc_code: document.getElementById('bfIfsc').value,
        nickname: document.getElementById('bfNickname').value
    };
    try {
        await STC.post('beneficiary_crud.php', 'create', body);
        closeModal('addBeneficiaryModal');
        showSuccess('Beneficiary added');
        loadBeneficiaries();
    } catch (e) {
        showError(e.message);
    }
}

function prefillTransfer(email) {
    window.location.href = 'transfers.html?to=' + encodeURIComponent(email || '');
}

// ============================================
// RECEIPTS
// ============================================
async function initReceipts() {
    const tbody = document.getElementById('receiptTableBody');
    if (!tbody) return;
    try {
        const data = await STC.get('receipt_crud.php', 'get_all');
        const list = data.receipts || [];
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">No receipts issued for you yet</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(r => `
            <tr>
                <td>${r.created_at}</td>
                <td>${STC.esc(r.receipt_number)}</td>
                <td>${formatCurrency(r.amount)}</td>
                <td>${STC.esc(r.payment_method)}</td>
                <td>${STC.esc(r.description || '-')}</td>
                <td><button class="btn btn-sm btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button></td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

// ============================================
// NOTES
// ============================================
async function initNotes() {
    const addBtn = document.getElementById('openAddNoteBtn');
    if (addBtn) addBtn.addEventListener('click', () => {
        const form = document.getElementById('addNoteForm');
        if (form) form.reset();
        openModal('addNoteModal');
    });
    const saveBtn = document.getElementById('saveNoteBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveNote);
    await loadNotes();
}

async function loadNotes() {
    const grid = document.getElementById('notesGrid');
    if (!grid) return;
    grid.innerHTML = '<div class="text-center" style="padding: 40px;"><div class="spinner"></div></div>';
    try {
        const data = await STC.get('notes_crud.php', 'get_all');
        const list = data.notes || [];
        if (list.length === 0) {
            grid.innerHTML = '<div class="card" style="padding: 40px; text-align: center; grid-column: 1 / -1;"><p style="color: var(--text-secondary);">No notes yet. Create your first note!</p></div>';
            return;
        }
        const sorted = [...list].sort((a, b) => (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0));
        grid.innerHTML = sorted.map(n => `
            <div class="card" style="padding: 16px; ${n.pinned ? 'border: 2px solid var(--warning-color);' : ''}">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <h4 style="margin: 0;">${n.pinned ? '<i class="fas fa-thumbtack" style="color: var(--warning-color);"></i> ' : ''}${STC.esc(n.title || 'Untitled')}</h4>
                    <div>
                        <button class="btn btn-sm btn-outline" onclick="openEditNote('${n.note_id}', '${STC.esc(n.title || '')}', '${STC.esc(n.content || '')}')"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-danger" data-del-note="${n.note_id}"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
                <p style="font-size: 0.85rem; color: var(--text-secondary); white-space: pre-wrap;">${STC.esc(n.content || '')}</p>
                <div style="font-size: 0.72rem; color: var(--text-tertiary); margin-top: 8px;">
                    ${STC.esc(n.category)} &middot; Updated ${n.updated_at}
                </div>
            </div>`).join('');
        grid.querySelectorAll('[data-del-note]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Delete this note?')) return;
                try {
                    await STC.post('notes_crud.php', 'delete', { note_id: btn.dataset.delNote });
                    showSuccess('Note deleted');
                    loadNotes();
                } catch (e) {
                    showError(e.message);
                }
            });
        });
    } catch (e) {
        grid.innerHTML = `<div class="card" style="padding: 40px; text-align: center;"><p>${STC.esc(e.message)}</p></div>`;
    }
}

async function saveNote() {
    const body = {
        title: document.getElementById('nfTitle').value,
        content: document.getElementById('nfContent').value,
        category: document.getElementById('nfCategory').value,
        pinned: document.getElementById('nfPinned').checked
    };
    if (!body.title && !body.content) {
        showError('Add a title or some content');
        return;
    }
    try {
        await STC.post('notes_crud.php', 'create', body);
        closeModal('addNoteModal');
        showSuccess('Note saved');
        loadNotes();
    } catch (e) {
        showError(e.message);
    }
}

function openEditNote(id, title, content) {
    bankActiveNoteId = id;
    document.getElementById('nfTitle').value = title;
    document.getElementById('nfContent').value = content;
    openModal('addNoteModal');
    const saveBtn = document.getElementById('saveNoteBtn');
    saveBtn.textContent = 'Update Note';
    saveBtn.onclick = async () => {
        const body = {
            note_id: bankActiveNoteId,
            title: document.getElementById('nfTitle').value,
            content: document.getElementById('nfContent').value,
            category: document.getElementById('nfCategory').value,
            pinned: document.getElementById('nfPinned').checked
        };
        try {
            await STC.post('notes_crud.php', 'update', body);
            closeModal('addNoteModal');
            showSuccess('Note updated');
            loadNotes();
            resetNoteModal();
        } catch (e) {
            showError(e.message);
        }
    };
}

function resetNoteModal() {
    const saveBtn = document.getElementById('saveNoteBtn');
    saveBtn.textContent = 'Save Note';
    saveBtn.onclick = saveNote;
}

// ============================================
// COMPLAINTS
// ============================================
async function initComplaints() {
    const addBtn = document.getElementById('openComplaintBtn');
    if (addBtn) addBtn.addEventListener('click', () => {
        const form = document.getElementById('addComplaintForm');
        if (form) form.reset();
        openModal('addComplaintModal');
    });
    const saveBtn = document.getElementById('saveComplaintBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveComplaint);
    await loadComplaints();
}

async function loadComplaints() {
    const tbody = document.getElementById('complaintTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const data = await STC.get('complaint_crud.php', 'get_all');
        const list = data.complaints || [];
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">No complaints submitted</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(c => `
            <tr>
                <td>${c.created_at}</td>
                <td>${STC.esc(c.subject)}</td>
                <td>${STC.esc(c.category)}</td>
                <td>${STC.badge(c.priority)}</td>
                <td>${STC.badge(c.status)}</td>
                <td>${c.response ? '<span class="badge badge-info">Responded</span>' : '-'}</td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function saveComplaint() {
    const body = {
        subject: document.getElementById('cfSubject').value,
        category: document.getElementById('cfCategory').value,
        description: document.getElementById('cfDescription').value,
        priority: document.getElementById('cfPriority').value
    };
    if (!body.subject || !body.description) {
        showError('Subject and description are required');
        return;
    }
    try {
        await STC.post('complaint_crud.php', 'create', body);
        closeModal('addComplaintModal');
        showSuccess('Complaint submitted. We will get back to you.');
        loadComplaints();
    } catch (e) {
        showError(e.message);
    }
}

// ============================================
// APPOINTMENTS
// ============================================
async function initAppointments() {
    const addBtn = document.getElementById('openAppointmentBtn');
    if (addBtn) addBtn.addEventListener('click', () => {
        const form = document.getElementById('addAppointmentForm');
        if (form) form.reset();
        openModal('addAppointmentModal');
    });
    const saveBtn = document.getElementById('saveAppointmentBtn');
    if (saveBtn) saveBtn.addEventListener('click', saveAppointment);
    await loadAppointmentBranches();
    await loadAppointments();
}

async function loadAppointmentBranches() {
    try {
        const data = await STC.get('appointment_crud.php', 'branches');
        const select = document.getElementById('apBranch');
        if (select && data.branches) {
            select.innerHTML = data.branches.map(b =>
                `<option value="${b.branch_id}">${STC.esc(b.name)} - ${STC.esc(b.city)}</option>`).join('');
        }
    } catch (e) { /* ignore */ }
}

async function loadAppointments() {
    const tbody = document.getElementById('appointmentTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><div class="spinner"></div></td></tr>';
    try {
        const data = await STC.get('appointment_crud.php', 'get_all');
        const list = data.appointments || [];
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">No appointments booked</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(a => `
            <tr>
                <td>${a.appointment_date}</td>
                <td>${STC.esc(a.appointment_time)}</td>
                <td>${STC.esc(a.purpose)}</td>
                <td>${STC.esc(a.notes || '-')}</td>
                <td>${STC.badge(a.status)}</td>
                <td>${a.created_at}</td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center">${STC.esc(e.message)}</td></tr>`;
    }
}

async function saveAppointment() {
    const body = {
        appointment_date: document.getElementById('apDate').value,
        appointment_time: document.getElementById('apTime').value,
        purpose: document.getElementById('apPurpose').value,
        branch_id: document.getElementById('apBranch').value,
        notes: document.getElementById('apNotes').value
    };
    if (!body.appointment_date || !body.appointment_time || !body.purpose || !body.branch_id) {
        showError('Please fill in all required fields');
        return;
    }
    try {
        await STC.post('appointment_crud.php', 'create', body);
        closeModal('addAppointmentModal');
        showSuccess('Appointment booked. The reception desk will confirm shortly.');
        loadAppointments();
    } catch (e) {
        showError(e.message);
    }
}
