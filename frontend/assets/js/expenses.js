/**
 * expenses.js — Expenses page logic for SecureSOT customer portal
 */

document.addEventListener('DOMContentLoaded', () => {
  const u = Auth.getUser();
  if (!u) { window.location.href = '../auth/login.html'; return; }

  let expenses    = [];
  let currentPage = 1;
  const perPage   = 20;

  const tableBody    = document.getElementById('expenses-table-body');
  const totalEl      = document.getElementById('total-expenses');
  const monthlyEl    = document.getElementById('monthly-total');
  const filterCat    = document.getElementById('filter-category');
  const filterFrom   = document.getElementById('filter-date-from');
  const filterTo     = document.getElementById('filter-date-to');
  const searchInput  = document.getElementById('search-input');
  const addBtn       = document.getElementById('add-expense-btn');
  const expModal     = document.getElementById('expense-modal');
  const expForm      = document.getElementById('expense-form');

  async function loadExpenses() {
    try {
      tableBody && (tableBody.innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading…</td></tr>');
      const res = await apiGet('?module=expenses&action=list&limit=500');
      expenses = res.data || [];
      renderSummary();
      renderTable();
    } catch (err) {
      showToast(err.message || 'Failed to load expenses', 'error');
    }
  }

  function renderSummary() {
    const total   = expenses.reduce((s, e) => s + Number(e.amount), 0);
    const month   = expenses.filter(e => e.date?.startsWith(new Date().toISOString().slice(0, 7)));
    const mTotal  = month.reduce((s, e) => s + Number(e.amount), 0);
    if (totalEl)   totalEl.textContent   = formatCurrency(total);
    if (monthlyEl) monthlyEl.textContent = formatCurrency(mTotal);

    // Category chart
    const cats = {};
    expenses.forEach(e => { cats[e.category] = (cats[e.category] || 0) + Number(e.amount); });
    const sorted = Object.entries(cats).sort((a, b) => b[1] - a[1]).slice(0, 8);
    if (document.getElementById('cat-chart')) {
      createDoughnutChart('cat-chart', sorted.map(c => c[0]), sorted.map(c => c[1]));
    }
  }

  function getFiltered() {
    const cat    = filterCat?.value || '';
    const from   = filterFrom?.value || '';
    const to     = filterTo?.value   || '';
    const search = searchInput?.value.toLowerCase() || '';

    return expenses.filter(e => {
      if (cat    && e.category !== cat)                                     return false;
      if (from   && e.date < from)                                          return false;
      if (to     && e.date > to)                                            return false;
      if (search && !(e.category + ' ' + e.description).toLowerCase().includes(search)) return false;
      return true;
    });
  }

  function renderTable() {
    if (!tableBody) return;
    const filtered = getFiltered();
    const start    = (currentPage - 1) * perPage;
    const page     = filtered.slice(start, start + perPage);

    if (!page.length) {
      tableBody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted">No expenses found</td></tr>';
    } else {
      tableBody.innerHTML = page.map(e => `
        <tr>
          <td>${formatDate(e.date)}</td>
          <td><span class="badge bg-secondary">${e.category}</span></td>
          <td>${truncate(e.description || '—', 40)}</td>
          <td class="text-danger fw-bold">${formatCurrency(e.amount)}</td>
          <td>${e.is_recurring ? '<span class="badge bg-info">Recurring</span>' : '—'}</td>
          <td>
            <button class="btn btn-sm btn-outline-primary me-1" onclick="editExpense('${e.id}')"><i class="fa-solid fa-pen-to-square"></i></button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteExpense('${e.id}')"><i class="fa-solid fa-trash"></i></button>
          </td>
        </tr>`).join('');
    }
    buildPagination('pagination', filtered.length, currentPage, perPage, p => { currentPage = p; renderTable(); });
  }

  if (expForm) {
    expForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = expForm.querySelector('[type=submit]');
      const orig = btn.innerHTML; setLoading(btn, true);

      const data = {
        amount:      parseFloat(document.getElementById('exp-amount')?.value || 0),
        category:    document.getElementById('exp-category')?.value,
        description: document.getElementById('exp-description')?.value,
        date:        document.getElementById('exp-date')?.value || new Date().toISOString().split('T')[0],
        is_recurring:(document.getElementById('exp-recurring')?.checked ?? false),
      };

      try {
        const action = expForm.dataset.editId ? 'update' : 'create';
        if (action === 'update') data.id = expForm.dataset.editId;
        await apiPost(`?module=expenses&action=${action}`, data);
        showToast(action === 'create' ? 'Expense added!' : 'Expense updated!', 'success');
        bootstrap.Modal.getInstance(expModal)?.hide();
        expForm.reset(); delete expForm.dataset.editId;
        loadExpenses();
      } catch (err) {
        showToast(err.message || 'Failed to save expense', 'error');
      } finally {
        setLoading(btn, false, orig);
      }
    });
  }

  window.deleteExpense = async (id) => {
    if (!confirm('Delete this expense?')) return;
    try {
      await apiPost('?module=expenses&action=delete', { id });
      showToast('Expense deleted', 'success');
      loadExpenses();
    } catch (err) { showToast(err.message || 'Delete failed', 'error'); }
  };

  window.editExpense = (id) => {
    const e = expenses.find(ex => ex.id === id);
    if (!e) return;
    document.getElementById('exp-amount').value      = e.amount;
    document.getElementById('exp-category').value    = e.category;
    document.getElementById('exp-description').value = e.description || '';
    document.getElementById('exp-date').value        = e.date;
    if (expForm) expForm.dataset.editId = id;
    if (expModal) new bootstrap.Modal(expModal).show();
  };

  [filterCat, filterFrom, filterTo].forEach(el => el?.addEventListener('change', () => { currentPage = 1; renderTable(); }));
  searchInput?.addEventListener('input', debounce(() => { currentPage = 1; renderTable(); }, 300));
  addBtn?.addEventListener('click', () => {
    expForm?.reset(); delete expForm?.dataset.editId;
    expModal && new bootstrap.Modal(expModal).show();
  });

  loadExpenses();
});
