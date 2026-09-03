/**
 * notes.js — Notes page logic for SecureSOT customer portal
 */

document.addEventListener('DOMContentLoaded', () => {
  const u = Auth.getUser();
  if (!u) { window.location.href = '../auth/login.html'; return; }

  let notes = [];
  const grid     = document.getElementById('notes-grid');
  const addBtn   = document.getElementById('add-note-btn');
  const noteModal= document.getElementById('note-modal');
  const noteForm = document.getElementById('note-form');
  const searchEl = document.getElementById('search-notes');

  const NOTE_COLORS = ['#fefce8','#f0fdf4','#eff6ff','#fdf4ff','#fff7ed','#f0fdfa'];

  async function loadNotes() {
    try {
      if (grid) grid.innerHTML = '<div class="col-12 text-center py-5"><div class="spinner-border text-primary"></div></div>';
      const res = await apiGet('?module=notes&action=list');
      notes = res.data || [];
      renderNotes(notes);
    } catch (err) {
      showToast(err.message || 'Failed to load notes', 'error');
      if (grid) grid.innerHTML = '<div class="col-12 text-center text-muted">Could not load notes</div>';
    }
  }

  function renderNotes(list) {
    if (!grid) return;
    if (!list.length) {
      grid.innerHTML = `
        <div class="col-12 text-center py-5">
          <i class="fa-regular fa-note-sticky fa-3x text-muted mb-3 d-block"></i>
          <p class="text-muted">No notes yet. Click <strong>+ Add Note</strong> to create one.</p>
        </div>`;
      return;
    }

    // Pinned first
    const sorted = [...list].sort((a, b) => (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0));
    grid.innerHTML = sorted.map(n => `
      <div class="col-md-4 col-lg-3 col-sm-6 mb-3">
        <div class="card h-100 shadow-sm border-0" style="background:${n.color};border-radius:12px;">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <h6 class="card-title mb-0 fw-bold text-truncate" style="max-width:160px">${n.title || 'Untitled'}</h6>
              <div class="d-flex gap-1">
                ${n.pinned ? '<i class="fa-solid fa-thumbtack text-warning fa-sm"></i>' : ''}
                <button class="btn btn-link btn-sm p-0 ms-1" onclick="editNote('${n.id}')"><i class="fa-solid fa-pen-to-square text-secondary"></i></button>
                <button class="btn btn-link btn-sm p-0" onclick="deleteNote('${n.id}')"><i class="fa-solid fa-trash text-danger"></i></button>
              </div>
            </div>
            <p class="card-text small text-muted mb-2" style="min-height:40px">${truncate(n.content || '', 100)}</p>
            ${n.tags?.length ? `<div>${n.tags.map(t => `<span class="badge bg-secondary me-1 small">${t}</span>`).join('')}</div>` : ''}
            <div class="mt-2"><small class="text-muted">${formatRelativeTime(n.updated_at)}</small></div>
          </div>
        </div>
      </div>`).join('');
  }

  if (noteForm) {
    noteForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn  = noteForm.querySelector('[type=submit]');
      const orig = btn.innerHTML; setLoading(btn, true);

      const data = {
        title:   document.getElementById('note-title')?.value.trim(),
        content: document.getElementById('note-content')?.value.trim(),
        color:   document.getElementById('note-color')?.value || '#fefce8',
        pinned:  document.getElementById('note-pinned')?.checked ?? false,
        tags:    (document.getElementById('note-tags')?.value || '').split(',').map(t => t.trim()).filter(Boolean),
      };

      try {
        const editId = noteForm.dataset.editId;
        if (editId) { data.id = editId; await apiPost('?module=notes&action=update', data); }
        else { await apiPost('?module=notes&action=create', data); }
        showToast(editId ? 'Note updated!' : 'Note created!', 'success');
        bootstrap.Modal.getInstance(noteModal)?.hide();
        noteForm.reset(); delete noteForm.dataset.editId;
        loadNotes();
      } catch (err) {
        showToast(err.message || 'Failed to save note', 'error');
      } finally {
        setLoading(btn, false, orig);
      }
    });
  }

  window.editNote = (id) => {
    const n = notes.find(x => x.id === id);
    if (!n) return;
    document.getElementById('note-title').value   = n.title;
    document.getElementById('note-content').value = n.content;
    document.getElementById('note-color').value   = n.color;
    if (document.getElementById('note-pinned')) document.getElementById('note-pinned').checked = n.pinned;
    if (document.getElementById('note-tags'))   document.getElementById('note-tags').value    = (n.tags || []).join(', ');
    if (noteForm) noteForm.dataset.editId = id;
    if (noteModal) new bootstrap.Modal(noteModal).show();
  };

  window.deleteNote = async (id) => {
    if (!confirm('Delete this note?')) return;
    try {
      await apiPost('?module=notes&action=delete', { id });
      showToast('Note deleted', 'success');
      loadNotes();
    } catch (err) { showToast(err.message || 'Delete failed', 'error'); }
  };

  searchEl?.addEventListener('input', debounce(() => {
    const q = searchEl.value.toLowerCase();
    renderNotes(notes.filter(n => (n.title + n.content).toLowerCase().includes(q)));
  }, 300));

  addBtn?.addEventListener('click', () => {
    noteForm?.reset(); delete noteForm?.dataset.editId;
    noteModal && new bootstrap.Modal(noteModal).show();
  });

  loadNotes();
});
