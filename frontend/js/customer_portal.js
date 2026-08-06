.0// Customer Portal SPA
let pbChart = null;

document.addEventListener('DOMContentLoaded', async () => {
    await STC.boot({
        title: 'Customer Portal', page: 'portal.html', roles: ['customer'],
        init: async (s) => {
            bindTransferForm();
            bindToggles();
            pbLoadProfile(s);
            await Promise.all([pbLoadDashboard(), pbLoadTransferHistory(), pbLoadAllTransactions()]);
        }
    });
});

function switchView(n) {
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    const t = document.getElementById('view-' + n);
    if (t) t.classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
window.switchView = switchView;
// dashboard section
async function pbLoadDashboard() {
    try {
        const [summary, pending, notifs] = await Promise.all([
            STC.get('transaction_crud.php', 'summary'),
            STC.get('approval_crud.php', 'pending_count').catch(() => ({ pending_count: 0 })),
            STC.get('notification_crud.php', 'get_all', { limit: 5 }).catch(() => ({ notifications: [] }))
        ]);
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
        const fmt = (n) => '₹' + parseFloat(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
        set('pbBalance', fmt(summary.balance ?? summary.total_savings ?? 0));
        set('pbIncome', fmt(summary.total_income ?? 0));
        set('pbExpense', fmt(summary.total_expense ?? 0));
        set('pbIncomeCount', (summary.income_count || 0) + ' transactions');
        set('pbExpenseCount', (summary.expense_count || 0) + ' transactions');
        set('pbPending', pending.pending_count || 0);
        set('pbAccount', 'Account #' + ((window.STC_SESSION || {}).account_number || '------'));
        pbRenderRecent(summary.recent_transactions || []);
        pbRenderNotifs(notifs.notifications || []);
        pbRenderChart(summary);
    } catch (e) { console.error(e); STC.showError('Failed to load dashboard'); }
}
function pbRenderRecent(list) {
    const tb = document.getElementById('pbRecentTx');
    if (!tb) return;
    if (!list.length) { tb.innerHTML = '<tr><td colspan="6" class="text-center text-secondary py-4">No transactions yet</td></tr>'; return; }
    const fmt = (n) => '₹' + parseFloat(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
    tb.innerHTML = list.slice(0, 6).map(t => {
        const isIn = ['income', 'deposit'].includes(t.type);
        return '<tr><td>' + STC.esc(t.date || '') + '</td><td>' + STC.esc(t.type || '') + '</td><td>' + STC.esc(t.description || t.category || 'Transfer') + '</td><td>' + STC.esc((t.payment_method || '—')).toUpperCase() + '</td><td>' + STC.badge(t.status || 'approved') + '</td><td style="text-align:right" class="' + (isIn ? 'text-success' : 'text-danger') + '">' + (isIn ? '+' : '-') + fmt(t.amount) + '</td></tr>';
    }).join('');
}
function pbRenderNotifs(list) {
    const box = document.getElementById('pbNotifications');
    if (!box) return;
    if (!list || !list.length) { box.innerHTML = '<p class="text-secondary">No notifications</p>'; return; }
    box.innerHTML = list.map(n => '<div style="padding:8px 10px;border-radius:8px;margin-bottom:6px;background:' + (n.read ? 'transparent' : 'rgba(16,185,129,0.08)') + ';border-left:3px solid ' + (n.read ? 'var(--border-color)' : 'var(--success)') + '"><div style="font-weight:600">' + STC.esc(n.title || '') + '</div><div style="color:var(--text-secondary)">' + STC.esc(n.message || '') + '</div></div>').join('');
}
function bindTransferForm() {
    const form = document.getElementById('pbTransferForm');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('pbSubmitBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        try {
            const method = document.getElementById('pbMethod').value;
            const amount = parseFloat(document.getElementById('pbAmount').value);
            const email = document.getElementById('pbRecipientEmail').value.trim();
            if (!amount || amount <= 0) throw new Error('Enter a valid amount');
            if (!email) throw new Error('Enter recipient email');
            if (method === 'internal') {
                await STC.post('transfer_crud.php', 'create', { amount: amount, recipient_email: email, type: 'internal', description: document.getElementById('pbDescription').value.trim() || 'Transfer' });
                STC.showToast('Transfer completed successfully', 'success');
            } else {
                await STC.post('approval_crud.php', 'create_request', {
                    type: 'transfer', amount: amount, payment_method: method, description: document.getElementById('pbDescription').value.trim() || 'Transfer',
                    recipient_name: document.getElementById('pbBeneficiaryName').value.trim(),
                    account_number: document.getElementById('pbAccountNumber').value.trim(),
                    ifsc_code: document.getElementById('pbIfsc').value.trim().toUpperCase()
                });
                STC.showToast('Request submitted - awaiting Admin/Staff approval', 'success');
            }
            pbResetTransferForm();
            await Promise.all([pbLoadDashboard(), pbLoadTransferHistory(), pbLoadAllTransactions()]);
        } catch (err) { STC.showError(err.message || 'Transfer failed'); }
        finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Transfer'; }
    });
}
function bindToggles() {
    const ms = document.getElementById('pbMethod');
    if (ms) ms.addEventListener('change', function() {
        const v = ms.value;
        const bf = document.getElementById('pbBankFields'); const note = document.getElementById('pbApprovalNote');
        if (bf) bf.style.display = (v === 'neft' || v === 'imps') ? 'block' : 'none';
        if (note) note.style.display = (v === 'neft' || v === 'imps') ? 'block' : 'none';
    });
    const fs = document.getElementById('pbFrequency');
    if (fs) fs.addEventListener('change', function() {
        const d = document.getElementById('pbScheduleDate');
        if (d) d.style.display = fs.value === 'scheduled' ? 'block' : 'none';
    });
}
function pbResetTransferForm() {
    const form = document.getElementById('pbTransferForm');
    if (form) form.reset();
    const bf = document.getElementById('pbBankFields'); const note = document.getElementById('pbApprovalNote'); const d = document.getElementById('pbScheduleDate');
    if (bf) bf.style.display = 'none'; if (note) note.style.display = 'none'; if (d) d.style.display = 'none';
}
window.pbResetTransferForm = pbResetTransferForm;
function pbRenderChart(summary) {
    const canvas = document.getElementById('pbCashflowChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (pbChart) pbChart.destroy();
    const months = summary.monthly_income || summary.monthly || null;
    if (!Array.isArray(months) || !months.length) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#94a3b8'; ctx.font = '14px Inter'; ctx.textAlign = 'center';
        ctx.fillText('No data for chart', canvas.width / 2, canvas.height / 2);
        return;
    }
    pbChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: months.map(m => m.month || m._id || ''),
            datasets: [
                { label: 'Income', data: months.map(m => m.income || 0), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', tension: 0.4, fill: true },
                { label: 'Expense', data: months.map(m => m.expense || 0), borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.1)', tension: 0.4, fill: true }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
}

async function pbLoadTransferHistory() {
    const tb = document.getElementById('pbTransferHistory');
    if (!tb) return;
    try {
        const [transfers, requests] = await Promise.all([
            STC.get('transfer_crud.php', 'get_all').catch(() => ({ transfers: [] })),
            STC.get('approval_crud.php', 'my_transactions', { type: 'transfer' }).catch(() => ({ transactions: [] }))
        ]);
        const list = [
            ...(transfers.transfers || []).map(t => ({ date: t.created_at || '', method: t.type === 'internal' ? 'internal' : t.type, recipient: t.recipient || t.description || '', amount: t.amount, status: t.status || 'approved', reason: t.remarks || '' })),
            ...(requests.transactions || []).map(t => ({ date: t.date || t.created_at || '', method: t.payment_method || 'neft', recipient: t.recipient_name || t.description || '', amount: t.amount, status: t.status || 'pending', reason: t.rejection_reason || t.modification_message || '' }))
        ].sort((a, b) => (a.date < b.date ? 1 : -1)).slice(0, 20);
        if (!list.length) { tb.innerHTML = '<tr><td colspan="6" class="text-center text-secondary py-4">No transfers yet</td></tr>'; return; }
        tb.innerHTML = list.map(t => '<tr><td>' + STC.esc(t.date) + '</td><td>' + STC.esc(t.method).toUpperCase() + '</td><td>' + STC.esc(t.recipient) + '</td><td>₹' + parseFloat(t.amount || 0).toLocaleString('en-IN') + '</td><td>' + STC.badge(t.status) + '</td><td style="font-size:0.78rem;color:var(--text-secondary)">' + STC.esc(t.reason || '—') + '</td></tr>').join('');
    } catch (e) { tb.innerHTML = '<tr><td colspan="6" class="text-center text-secondary py-4">Failed to load</td></tr>'; }
}

async function pbLoadAllTransactions() {
    const tb = document.getElementById('pbAllTx');
    if (!tb) return;
    const status = (document.getElementById('pbTxStatus') || {}).value || 'all';
    try {
        const data = await STC.get('approval_crud.php', 'my_transactions', { limit: 100, ...(status !== 'all' ? { status: status } : {}) });
        const list = data.transactions || [];
        if (!list.length) { tb.innerHTML = '<tr><td colspan="8" class="text-center text-secondary py-4">No transactions found</td></tr>'; return; }
        tb.innerHTML = list.map(t => {
            const isIn = ['income', 'deposit'].includes(t.type);
            const id = t.transaction_id || t._id;
            const canCancel = t.status === 'pending';
            const actions = canCancel ? '<button class="btn btn-sm btn-outline" onclick="pbCancelRequest(\'' + id + '\')" title="Cancel"><i class="fas fa-times"></i></button>' : (t.rejection_reason ? '<i class="fas fa-info-circle" style="color:#ef4444" title="' + STC.esc(t.rejection_reason) + '"></i>' : '—');
            return '<tr><td>' + STC.esc(t.date || t.created_at || '') + '</td><td>' + STC.esc(t.type || '') + '</td><td>' + STC.esc(t.category || '') + '</td><td>' + STC.esc(t.description || '') + '</td><td>' + STC.esc((t.payment_method || '—')).toUpperCase() + '</td><td>' + STC.badge(t.status) + '</td><td style="text-align:right" class="' + (isIn ? 'text-success' : 'text-danger') + '">' + (isIn ? '+' : '-') + '₹' + parseFloat(t.amount || 0).toLocaleString('en-IN') + '</td><td style="text-align:center">' + actions + '</td></tr>';
        }).join('');
    } catch (e) { tb.innerHTML = '<tr><td colspan="8" class="text-center text-secondary py-4">Failed to load</td></tr>'; }
}
window.pbLoadAllTransactions = pbLoadAllTransactions;

async function pbCancelRequest(id) {
    try {
        await STC.post('approval_crud.php', 'cancel_pending', { transaction_id: id });
        STC.showToast('Request cancelled', 'success');
        await Promise.all([pbLoadAllTransactions(), pbLoadDashboard()]);
    } catch (e) { STC.showError(e.message); }
}
window.pbCancelRequest = pbCancelRequest;

function pbLoadProfile(s) {
    const setTxt = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
    s = s || {};
    setTxt('pbFullName', (s.first_name || '') + ' ' + (s.last_name || ''));
    setTxt('pbEmail', s.email || '');
    setTxt('pbAvatar', (s.first_name || 'U').charAt(0).toUpperCase());
    setVal('pbFirstName', s.first_name || '');
    setVal('pbPhone', s.phone || '');
}

async function pbSaveProfile() {
    try {
        const body = { first_name: document.getElementById('pbFirstName').value.trim() };
        const phone = document.getElementById('pbPhone').value.trim();
        if (phone) body.phone = phone;
        await STC.post('user_crud.php', 'update_profile', body);
        STC.showToast('Profile updated', 'success');
    } catch (e) { STC.showError(e.message); }
}
window.pbSaveProfile = pbSaveProfile;
