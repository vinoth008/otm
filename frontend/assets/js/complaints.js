/**
 * complaints.js — Complaints/support ticket page logic
 */

document.addEventListener('DOMContentLoaded', () => {
  const u = Auth.getUser();
  if (!u) { window.location.href = '../auth/login.html'; return; }

  const isAdmin = ['admin', 'staff'].includes(u.role);
  let complaints = [];
  let currentPage = 1;
  const perPage   = 10;

  const tableBody  = document.getElementById('complaints-table-body');
  const addBtn     = document.getElementById('add-complaint-btn');
  const cModal     = document.getElementById('complaint-modal');
  const cForm      = document.getElementById('complaint-form');
  const filterStatus = document.getElementById('filter-status');
  const openCount  = document.getElementById('open-count');
  const resolvedCount = document.getElementById('resolved-count');

  async function loadComplaints() {
    try {
      tableBody && (tableBody.innerHTML = `<tr><td colspan="6" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading…</td></tr>`);
      const module = isAdmin ? 'complaints&action=list_all' : 'complaints&action=list';
      const res    = await apiGet(`?module=${module}`);
      complaints   = res.data || [];
      updateStats();
      renderTable();
    } catch (err) {
      showToast(err.message || 'Failed to load complaints', 'error');
    }
  }

  function updateStats() {
    if (openCount)     openCount.textContent     = complaints.filter(c => c.status === 'open').length;
    if (resolvedCount) resolvedCount.textContent = complaints.filter(c => c.status === 'resolved').length;
  }

  function renderTable() {
    if (!tableBody) return;
    const status   = filterStatus?.value || '';
    const filtered = status ? complaints.filter(c => c.status === status) : complaints;
    const start    = (currentPage - 1) * perPage;
    const page     = filtered.slice(start, start + perPage);

    if (!page.length) {
      tableBody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted">No complaints found</td></tr>';
    } else {
      tableBody.innerHTML = page.map(c => `
        <tr>
          <td><span class="fw-medium text-primary">#${c.ticket_no}</span></td>
          <td>${truncate(c.subject, 35)}</td>
          <td><span class="badge bg-secondary">${capitalise(c.category)}</span></td>
          <td>${typeBadge(c.status)}</td>
          <td>${typeBadge(c.priority)}</td>
          <td>
            <button class="btn btn-sm btn-outline-primary" onclick="viewComplaint('${c.id}')">
              <i class="fa-solid fa-eye"></i> View
            </button>
            ${isAdmin ? `<button class="btn btn-sm btn-outline-success ms-1" onclick="resolveComplaint('${c.id}')"><i class="fa-solid fa-check"></i></button>` : ''}
          </td>
        </tr>`).join('');
    }
    buildPagination('pagination', filtered.length, currentPage, perPage, p => { currentPage = p; renderTable(); });
  }

  if (cForm) {
    cForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = cForm.querySelector('[type=submit]');
      const orig = btn.innerHTML; setLoading(btn, true);

      const data = {
        subject:     document.getElementById('c-subject')?.value.trim(),
        description: document.getElementById('c-description')?.value.trim(),
        category:    document.getElementById('c-category')?.value,
        priority:    document.getElementById('c-priority')?.value || 'medium',
      };

      try {
        await apiPost('?module=complaints&action=submit', data);
        showToast('Complaint submitted! Ticket number generated.', 'success');
        bootstrap.Modal.getInstance(cModal)?.hide();
        cForm.reset();
        loadComplaints();
      } catch (err) {
        showToast(err.message || 'Failed to submit complaint', 'error');
      } finally {
        setLoading(btn, false, orig);
      }
    });
  }

  window.viewComplaint = (id) => {
    const c = complaints.find(x => x.id === id);
    if (!c) return;
    const detailModal = document.getElementById('detail-modal');
    if (detailModal) {
      const body = detailModal.querySelector('.modal-body');
      if (body) {
        body.innerHTML = `
          <div class="mb-3"><strong>Ticket:</strong> #${c.ticket_no}</div>
          <div class="mb-3"><strong>Subject:</strong> ${c.subject}</div>
          <div class="mb-3"><strong>Description:</strong><p class="text-muted">${c.description}</p></div>
          <div class="mb-3 d-flex gap-3">
            <div><strong>Status:</strong> ${typeBadge(c.status)}</div>
            <div><strong>Priority:</strong> ${typeBadge(c.priority)}</div>
          </div>
          ${c.resolution ? `<div class="alert alert-success"><strong>Resolution:</strong> ${c.resolution}</div>` : ''}
          <div class="mt-3"><strong>Replies (${c.replies?.length || 0}):</strong>
            ${(c.replies || []).map(r => `
              <div class="d-flex gap-2 mt-2">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:32px;height:32px;flex-shrink:0;font-size:12px">${capitalise(r.role[0])}</div>
                <div class="bg-light rounded p-2 flex-grow-1"><small class="text-muted">${r.role} • ${formatRelativeTime(r.created_at)}</small><p class="mb-0 mt-1">${r.message}</p></div>
              </div>`).join('') || '<p class="text-muted mt-2">No replies yet.</p>'}
          </div>`;
      }
      new bootstrap.Modal(detailModal).show();
    }
  };

  window.resolveComplaint = async (id) => {
    const resolution = prompt('Enter resolution note (optional):') ?? '';
    try {
      await apiPost('?module=complaints&action=update_status', { id, status: 'resolved', resolution });
      showToast('Complaint resolved!', 'success');
      loadComplaints();
    } catch (err) { showToast(err.message || 'Failed to update', 'error'); }
  };

  filterStatus?.addEventListener('change', () => { currentPage = 1; renderTable(); });
  addBtn?.addEventListener('click', () => { cForm?.reset(); cModal && new bootstrap.Modal(cModal).show(); });

  loadComplaints();
});
