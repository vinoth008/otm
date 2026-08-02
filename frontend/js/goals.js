// frontend/js/goals.js
const API_BASE = '../../backend/php/';

document.addEventListener('DOMContentLoaded', async function() {
    if (!await requireAuth()) return;
    initSidebar();
    initTheme();
    loadGoals();
});

async function loadGoals() {
    try {
        const response = await fetch(API_BASE + 'goals_crud.php?action=get_all', {
            method: 'GET',
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.success) {
            renderGoals(data.data);
            updateSummary(data.data);
        }
    } catch (error) {
        console.error('Load goals error:', error);
    }
}

function renderGoals(goals) {
    const grid = document.getElementById('goalsGrid');
    if (!goals || goals.length === 0) {
        grid.innerHTML = `
            <div style="grid-column: 1/-1; text-align: center; padding: 60px;">
                <i class="fas fa-bullseye" style="font-size: 4rem; color: var(--text-tertiary);"></i>
                <h3>No goals yet</h3>
                <p>Create your first savings goal to start tracking</p>
                <button class="btn btn-primary" onclick="openAddGoalModal()" style="margin-top: 16px;">
                    <i class="fas fa-plus"></i> Create Goal
                </button>
            </div>
        `;
        return;
    }

    grid.innerHTML = goals.map(goal => {
        const progress = ((goal.current_amount / goal.target_amount) * 100).toFixed(1);
        const remaining = goal.target_amount - goal.current_amount;
        const isCompleted = progress >= 100;
        const icon = goal.icon || 'fa-bullseye';
        const color = getGoalColor(icon);
        const lighterColor = getGoalColor(icon, true);
        return `
            <div class="card goal-card" style="position: relative; overflow: hidden;">
                ${isCompleted ? '<div style="position: absolute; top: 0; right: 0; background: #10b981; color: white; padding: 4px 12px; font-size: 0.75rem; font-weight: 600; border-bottom-left-radius: 8px;">COMPLETED</div>' : ''}
                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 16px;">
                    <div style="width: 64px; height: 64px; border-radius: 16px; background: ${lighterColor}33; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                        <i class="fas ${icon}" style="color: ${color};"></i>
                    </div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 4px; font-size: 1.25rem; color: var(--text-primary);">${goal.name}</h3>
                        <p style="margin: 0; font-size: 0.875rem; color: var(--text-tertiary);">
                            ${goal.description || (goal.target_date ? 'Target: ' + formatDate(goal.target_date) : '')}
                        </p>
                    </div>
                </div>
                <div style="margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 0.875rem; color: var(--text-secondary);">Progress</span>
                        <span style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);">${progress}%</span>
                    </div>
                    <div class="progress-bar" style="height: 12px; border-radius: 6px;">
                        <div class="progress-fill" style="width: ${Math.min(progress, 100)}%; background: linear-gradient(90deg, ${color}, ${lighterColor});"></div>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-tertiary);">Target</div>
                        <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary);">${formatCurrency(goal.target_amount)}</div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-tertiary);">Saved</div>
                        <div style="font-size: 1.125rem; font-weight: 700; color: ${color};">${formatCurrency(goal.current_amount)}</div>
                    </div>
                </div>
                ${remaining > 0 ? `
                    <div style="text-align: center; padding: 12px; background: rgba(79, 70, 229, 0.05); border-radius: 12px; margin-bottom: 16px;">
                        <div style="font-size: 0.75rem; color: var(--text-secondary);">Remaining</div>
                        <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">${formatCurrency(remaining)}</div>
                    </div>
                ` : ''}
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-primary btn-sm" style="flex: 1;" onclick="addContribution('${goal._id}')">
                        <i class="fas fa-plus"></i> Add
                    </button>
                    <button class="btn btn-outline btn-sm" onclick="editGoal('${goal._id}')">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-outline btn-sm" onclick="deleteGoal('${goal._id}')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
    }).join('');
}

function updateSummary(goals) {
    const totalTarget = goals.reduce((sum, g) => sum + g.target_amount, 0);
    const totalSaved = goals.reduce((sum, g) => sum + g.current_amount, 0);
    const progress = totalTarget > 0 ? ((totalSaved / totalTarget) * 100).toFixed(1) : 0;
    document.getElementById('activeGoals').textContent = goals.filter(g => (g.current_amount / g.target_amount) * 100 < 100).length;
    document.getElementById('totalTarget').textContent = formatCurrency(totalTarget);
    document.getElementById('totalSaved').textContent = formatCurrency(totalSaved);
    document.getElementById('overallProgress').textContent = progress + '%';
}

function openAddGoalModal() {
    document.getElementById('addGoalModal').classList.add('active');
}

function closeAddGoalModal() {
    document.getElementById('addGoalModal').classList.remove('active');
    document.getElementById('addGoalForm').reset();
}

async function saveGoal() {
    const name = document.getElementById('goalName').value;
    const target = document.getElementById('goalTarget').value;
    const current = document.getElementById('goalCurrent').value;
    const date = document.getElementById('goalDate').value;
    const icon = document.getElementById('goalIcon').value;
    const description = document.getElementById('goalDescription').value;

    if (!name || !target) {
        alert('Please fill required fields');
        return;
    }
    try {
        const response = await fetch(API_BASE + 'goals_crud.php?action=create', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name,
                target_amount: parseFloat(target),
                current_amount: parseFloat(current) || 0,
                target_date: date || null,
                icon,
                description
            })
        });

        const data = await response.json();
        if (data.success) {
            showSuccess('Goal created successfully!');
            closeAddGoalModal();
            loadGoals();
        } else {
            alert(data.error || 'Failed to create goal');
        }
    } catch (error) {
        alert('Network error');
    }
}

async function deleteGoal(id) {
    if (!confirm('Delete this goal?')) return;
    try {
        await fetch(API_BASE + 'goals_crud.php?action=delete', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        showSuccess('Goal deleted');
        loadGoals();
    } catch (error) {
        alert('Error');
    }
}

function editGoal(id) {
    alert('Edit goal: ' + id);
}

function addContribution(id) {
    alert('Add contribution to: ' + id);
}

// Utilities
function formatCurrency(amount) {
    return '₹' + parseFloat(amount || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    });
}

function getGoalColor(icon, lighter = false) {
    const colors = {
        'fa-car': ['#3b82f6', '#60a5fa'],
        'fa-home': ['#10b981', '#34d399'],
        'fa-plane': ['#f59e0b', '#fbbf24'],
        'fa-laptop': ['#8b5cf6', '#a78bfa'],
        'fa-graduation-cap': ['#06b6d4', '#22d3ee'],
        'fa-heart': ['#ef4444', '#f87171'],
        'fa-gift': ['#ec4899', '#f472b6'],
        'fa-coins': ['#f97316', '#fb923c']
    };
    const c = colors[icon] || ['#4f46e5', '#818cf8'];
    return lighter ? c[1] : c[0];
}

async function requireAuth() {
    if (!await checkAuth()) window.location.href = '../../index.php';
}

async function checkAuth() {
    try {
        const r = await fetch(API_BASE + 'auth.php?action=session_info');
        const d = await r.json();
        return d.data && d.data.is_logged_in;
    } catch (e) {
        return false;
    }
}

function initSidebar() {
    const s = document.getElementById('sidebar');
    const t = document.getElementById('sidebarToggle');
    const c = document.getElementById('sidebarClose');
    const o = document.getElementById('mobileOverlay');
    if (t) t.addEventListener('click', () => s.classList.add('open'));
    if (c) c.addEventListener('click', () => s.classList.remove('open'));
    if (o) o.addEventListener('click', () => s.classList.remove('open'));
}

function initTheme() {
    const t = document.getElementById('themeToggle');
    const h = document.documentElement;
    const saved = localStorage.getItem('theme') || 'light';
    h.setAttribute('data-theme', saved);
    updateThemeIcons(saved);
    if (t) {
        t.addEventListener('click', () => {
            const next = h.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            h.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
            updateThemeIcons(next);
        });
    }
}

function updateThemeIcons(theme) {
    const moon = document.querySelector('#themeToggle .fa-moon');
    const sun = document.querySelector('#themeToggle .fa-sun');
    if (moon) moon.style.display = theme === 'light' ? 'block' : 'none';
    if (sun) sun.style.display = theme === 'light' ? 'none' : 'block';
}

function showSuccess(msg) {
    alert('SUCCESS: ' + msg);
}
