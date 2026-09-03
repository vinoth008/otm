/**
 * transactions.js — Transactions page logic for SecureSOT customer portal
 */

document.addEventListener('DOMContentLoaded', () => {
  const u = Auth.getUser();
  if (!u) { window.location.href = '../auth/login.html'; return; }

  // ── State ─────────────────────────────────────────────────────
  let allTransactions = [];
  let filtered        = [];
  let currentPage     = 1;
  const perPage       = 15;
  let wallets         = [];

  // ── Refs ──────────────────────────────────────────────────────
  const tableBody     = document.getElementById('transactions-table-body');
  const totalCount    = document.getElementById('total-count');
  const filterType    = document.getElementById('filter-type');
  const filterWallet  = document.getElementById('filter-wallet');
  const filterDateFrom= document.getElementById('filter-date-from');
  const filterDateTo  = document.getElementById('filter-date-to');
  const searchInput   = document.getElementById('search-input');
  const addTxBtn      = document.getElementById('add-tx-btn');
  const exportBtn     = document.getElementById('export-btn');
  const txModal       = document.getElementById('tx-modal');
  const txForm        = document.getElementById('tx-form');
  const paginationEl  = document.getElementById('pagination');

  // ── Load wallets for dropdowns ─────────────────────────────────
  async function loadWallets() {
    try {
      const res = await apiGet('?module=wallets&action=list');
      wallets = res.data || [];
      if (filterWallet) {
        filterWallet.innerHTML = '<option value="">All Wallets</option>' +
          wallets.map(w => `<option value="${w.id}">${w.name} (${formatCurrency(w.balance)})</option>`).join('');
      }
      const walletSelect = document.getElementById('tx-wallet');
      if (walletSelect) {
        walletSelect.innerHTML = wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('');
      }
    } catch (e) { /* silent */ }
  }

  // ── Load transactions ──────────────────────────────────────────
  async function loadTransactions() {
    try {
      setTableLoading(true);
      const params = new URLSearchParams({ module: 'transactions', action: 'list', limit: 500 });
      const res = await apiGet('?' + params);
      allTransactions = res.data || [];
      applyFilters();
    } catch (err) {
      showToast(err.message || 'Failed to load transactions', 'error');
    } finally {
      setTableLoading(false);
    }
  }

  function setTableLoading(loading) {
    if (tableBody) tableBody.innerHTML = loading
      ? `<tr><td colspan="7" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading…</td></tr>`
      : '';
  }

  // ── Apply filters ──────────────────────────────────────────────
  function applyFilters() {
    const type     = filterType?.value   || '';
    const walletId = filterWallet?.value || '';
    const from     = filterDateFrom?.value || '';
    const to       = filterDateTo?.value   || '';
    const search   = searchInput?.value.toLowerCase() || '';

    filtered = allTransactions.filter(tx => {
      if (type     && tx.type      !== type)     return false;
      if (walletId && tx.wallet_id !== walletId) return false;
      if (from     && tx.date < from)            return false;
      if (to       && tx.date > to)              return false;
      if (search   && !(`${tx.category} ${tx.note} ${tx.reference}`.toLowerCase().includes(search))) return false;
      return true;
    });

    currentPage = 1;
    renderPage();
  }

  // ── Render table ───────────────────────────────────────────────
  function renderPage() {
    if (!tableBody) return;

    const start = (currentPage - 1) * perPage;
    const page  = filtered.slice(start, start + perPage);

    if (!page.length) {
      tableBody.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted">No transactions found</td></tr>';
    } else {
      tableBody.innerHTML = page.map(tx => `
        <tr>
          <td><span class="fw-medium">${tx.reference || '—'}</span></td>
          <td>${formatDate(tx.date)}</td>
          <td>${typeBadge(tx.type)}</td>
          <td><span class="badge bg-secondary">${tx.category}</span></td>
          <td class="${tx.type === 'income' ? 'text-success' : 'text-danger'} fw-bold">
            ${tx.type === 'income' ? '+' : '-'}${formatCurrency(tx.amount)}
          </td>
          <td><small class="text-muted">${tx.note || '—'}</small></td>
          <td>
            <button class="btn btn-sm btn-outline-primary me-1" onclick="editTx('${tx.id}')"><i class="fa-solid fa-pen-to-square"></i></button>
            <button class="btn btn-sm btn-outline-danger"       onclick="deleteTx('${tx.id}')"><i class="fa-solid fa-trash"></i></button>
          </td>
        </tr>`).join('');
    }

    if (totalCount) totalCount.textContent = filtered.length + ' transactions';
    buildPagination('pagination', filtered.length, currentPage, perPage, p => { currentPage = p; renderPage(); });
  }

  // ── Add transaction ────────────────────────────────────────────
  if (txForm) {
    txForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = txForm.querySelector('[type=submit]');
      const originalText = btn.innerHTML;
      setLoading(btn, true);

      const data = {
        type:     document.getElementById('tx-type')?.value,
        amount:   parseFloat(document.getElementById('tx-amount')?.value || 0),
        wallet_id:document.getElementById('tx-wallet')?.value,
        category: document.getElementById('tx-category')?.value,
        note:     document.getElementById('tx-note')?.value,
        date:     document.getElementById('tx-date')?.value || new Date().toISOString().split('T')[0],
      };

      try {
        await apiPost('?module=transactions&action=create', data);
        showToast('Transaction added!', 'success');
        bootstrap.Modal.getInstance(txModal)?.hide();
        txForm.reset();
        loadTransactions();
      } catch (err) {
        showToast(err.message || 'Failed to add transaction', 'error');
      } finally {
        setLoading(btn, false, originalText);
      }
    });
  }

  // ── Delete transaction ─────────────────────────────────────────
  window.deleteTx = async (id) => {
    if (!confirm('Delete this transaction?')) return;
    try {
      await apiPost('?module=transactions&action=delete', { id });
      showToast('Transaction deleted', 'success');
      loadTransactions();
    } catch (err) {
      showToast(err.message || 'Delete failed', 'error');
    }
  };

  window.editTx = (id) => {
    const tx = allTransactions.find(t => t.id === id);
    if (!tx) return;
    document.getElementById('tx-type')?.setAttribute('value', tx.type);
    document.getElementById('tx-amount').value   = tx.amount;
    document.getElementById('tx-category').value = tx.category;
    document.getElementById('tx-note').value     = tx.note || '';
    document.getElementById('tx-date').value     = tx.date;
    if (txModal) new bootstrap.Modal(txModal).show();
  };

  // ── Export ────────────────────────────────────────────────────
  if (exportBtn) {
    exportBtn.addEventListener('click', () => {
      exportToCSV(filtered.map(tx => ({
        Reference: tx.reference, Date: tx.date, Type: tx.type,
        Category: tx.category, Amount: tx.amount, Note: tx.note,
      })), 'transactions.csv');
    });
  }

  // ── Event listeners ────────────────────────────────────────────
  [filterType, filterWallet, filterDateFrom, filterDateTo].forEach(el => el?.addEventListener('change', applyFilters));
  searchInput?.addEventListener('input', debounce(applyFilters, 300));

  if (addTxBtn && txModal) {
    addTxBtn.addEventListener('click', () => {
      txForm?.reset();
      new bootstrap.Modal(txModal).show();
    });
  }

  // ── Init ──────────────────────────────────────────────────────
  loadWallets().then(loadTransactions);
});
