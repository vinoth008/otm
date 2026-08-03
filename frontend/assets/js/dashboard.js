/**
 * dashboard.js — Dashboard charts and live data
 * Fetches real data from backend API
 */

document.addEventListener('DOMContentLoaded', () => {
  // Guard: check auth
  const user = Auth.getUser();
  if (!user) { Auth.logout(); return; }

  // Render charts if present
  if (typeof Chart !== 'undefined') {
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.borderColor = '#2d2d50';
    Chart.defaults.font.family = 'Inter';
  }

  // Animate stat numbers
  animateCounters();

  // Update greeting
  const greeting = document.getElementById('greeting');
  if (greeting) {
    const hour = new Date().getHours();
    const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    greeting.textContent = `${greet}, ${user.name.split(' ')[0]}! 👋`;
  }

  // Update current date
  document.querySelectorAll('[data-current-date]').forEach(el => {
    el.textContent = new Date().toLocaleDateString('en-IN',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
  });

  // Load real dashboard data
  loadDashboardData(user.role);
});

async function loadDashboardData(role) {
  try {
    const action = role === 'admin' ? 'admin_stats' : 'user_stats';
    const res = await apiGet(`?module=dashboard&action=${action}`);
    const data = res.data || {};

    if (role === 'admin') {
      // Update stat cards
      setStatValue(0, data.total_users || 0);
      setStatValue(1, data.total_transactions || 0);
      setStatValue(2, data.total_volume || 0, '₹', 'K');
      setStatValue(3, 0); // fraud alerts - no backend field yet

      // Update recent transactions table
      renderAdminRecentTransactions(data.recent_transactions || []);
    } else {
      // User dashboard stats
      setStatValue(0, data.total_income || 0, '₹');
      setStatValue(1, data.total_expense || 0, '₹');
      setStatValue(2, data.balance || 0, '₹');
      setStatValue(3, data.budget_alerts || 0);

      // Render recent transactions
      renderRecentTransactions(data.recent_transactions || []);
    }

    // Render charts with real data
    if (typeof Chart !== 'undefined') {
      initCharts(role, data);
    }
  } catch (err) {
    // Fall back to empty charts
    if (typeof Chart !== 'undefined') initCharts(role, {});
  }
}

function setStatValue(index, value, prefix = '', suffix = '') {
  const cards = document.querySelectorAll('.stat-card .stat-value');
  if (!cards[index]) return;
  const el = cards[index];
  const decimals = el.dataset.decimals ? parseInt(el.dataset.decimals) : 0;
  const p = el.dataset.prefix || prefix;
  const s = el.dataset.suffix || suffix;
  if (s === 'K' && value >= 1000) {
    el.textContent = p + (value / 1000).toFixed(decimals) + 'K';
  } else {
    el.textContent = p + Number(value).toLocaleString('en-IN', {minimumFractionDigits: decimals, maximumFractionDigits: decimals}) + s;
  }
}

function renderRecentUsers(users) {
  const tbody = document.querySelector('.table-custom tbody');
  if (!tbody || !users.length) return;
  tbody.innerHTML = users.map(u => {
    const initials = (u.name || 'U').split(' ').map(w => w[0]).join('').slice(0,2).toUpperCase();
    const statusClass = u.status === 'active' ? 'badge-success' : 'badge-danger';
    return `<tr>
      <td><div class="flex items-center gap-2"><div class="avatar" style="width:30px;height:30px;font-size:0.7rem">${initials}</div>${u.name}</div></td>
      <td>${capitalise(u.role)}</td>
      <td>${u.email}</td>
      <td><span class="badge-custom ${statusClass}">${capitalise(u.status)}</span></td>
    </tr>`;
  }).join('');
}

function renderRecentTransactions(txs) {
  const el = document.querySelector('.table-custom tbody');
  if (!el || !txs.length) return;
  el.innerHTML = txs.map(t => {
    const isIncome = t.type === 'income';
    const amount = (isIncome ? '+' : '-') + formatCurrency(t.amount);
    const color = isIncome ? 'var(--success)' : 'var(--danger)';
    return `<tr>
      <td>${t.description || t.category}</td>
      <td>${capitalise(t.type)}</td>
      <td style="color:${color}">${amount}</td>
      <td><span class="badge-custom badge-success">Success</span></td>
    </tr>`;
  }).join('');
}

function renderAdminRecentTransactions(txs) {
  const el = document.querySelector('.table-custom tbody');
  if (!el || !txs.length) return;
  el.innerHTML = txs.map(t => {
    const isIncome = t.type === 'income';
    const amount = (isIncome ? '+' : '-') + formatCurrency(t.amount);
    const color = isIncome ? 'var(--success)' : 'var(--danger)';
    const initials = (t.user_name || 'U').split(' ').map(w => w[0]).join('').slice(0,2).toUpperCase();
    return `<tr>
      <td><div class="flex items-center gap-2"><div class="avatar" style="width:30px;height:30px;font-size:0.7rem">${initials}</div>${t.user_name}</div></td>
      <td>${capitalise(t.type)}</td>
      <td style="color:${color}">${amount}</td>
      <td><span class="badge-custom ${statusClass(t.status)}">${capitalise(t.status)}</span></td>
    </tr>`;
  }).join('');
}

function statusClass(s) {
  return { success:'badge-success', pending:'badge-warning', failed:'badge-danger' }[s] || 'badge-info';
}

function initCharts(role, data) {
  // Revenue Chart
  const revCtx = document.getElementById('revenueChart');
  if (revCtx) {
    const labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const revenue = data.monthly_revenue || [0,0,0,0,0,0,0,0,0,0,0,0];
    const expenses = data.monthly_expenses || [0,0,0,0,0,0,0,0,0,0,0,0];
    new Chart(revCtx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Revenue',
          data: revenue,
          borderColor: '#6366f1',
          backgroundColor: 'rgba(99,102,241,0.08)',
          borderWidth: 2.5,
          tension: 0.4,
          fill: true,
          pointBackgroundColor: '#6366f1',
          pointRadius: 4,
          pointHoverRadius: 7,
        },{
          label: 'Expenses',
          data: expenses,
          borderColor: '#06b6d4',
          backgroundColor: 'rgba(6,182,212,0.05)',
          borderWidth: 2,
          tension: 0.4,
          fill: true,
          pointBackgroundColor: '#06b6d4',
          pointRadius: 4,
          pointHoverRadius: 7,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top', labels: { usePointStyle: true, padding: 16 } } },
        scales: {
          y: { grid: { color: '#2d2d50' }, ticks: { callback: v => '₹'+(v/1000)+'K' } },
          x: { grid: { display: false } }
        }
      }
    });
  }

  // Transactions Donut
  const txCtx = document.getElementById('txTypeChart');
  if (txCtx) {
    const txTypes = data.tx_types || { income: 0, expense: 0, transfer: 0 };
    new Chart(txCtx, {
      type: 'doughnut',
      data: {
        labels: ['Income','Expense','Transfer'],
        datasets: [{
          data: [txTypes.income || 0, txTypes.expense || 0, txTypes.transfer || 0],
          backgroundColor: ['#6366f1','#06b6d4','#10b981'],
          borderColor: '#1e1e35',
          borderWidth: 3,
          hoverOffset: 8,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } },
        cutout: '72%',
      }
    });
  }

  // Bar chart - weekly
  const barCtx = document.getElementById('weeklyChart');
  if (barCtx) {
    const weekly = data.weekly_transactions || [0,0,0,0,0,0,0];
    new Chart(barCtx, {
      type: 'bar',
      data: {
        labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
        datasets: [{
          label: 'Transactions',
          data: weekly,
          backgroundColor: 'rgba(99,102,241,0.7)',
          borderRadius: 6,
          hoverBackgroundColor: '#6366f1',
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { grid: { color: '#2d2d50' }, beginAtZero: true },
          x: { grid: { display: false } }
        }
      }
    });
  }

  // Expense category chart
  const expCtx = document.getElementById('expenseChart');
  if (expCtx) {
    const cats = data.category_breakdown || {};
    const labels = Object.keys(cats);
    const values = Object.values(cats);
    new Chart(expCtx, {
      type: 'bar',
      data: {
        labels: labels.length ? labels : ['No Data'],
        datasets: [{
          label: 'Amount (₹)',
          data: values.length ? values : [0],
          backgroundColor: ['#6366f1','#06b6d4','#10b981','#f59e0b','#ef4444','#8b5cf6'],
          borderRadius: 6,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { grid: { color: '#2d2d50' }, ticks: { callback: v => '₹'+(v/1000)+'K' } },
          x: { grid: { display: false } }
        }
      }
    });
  }
}

function animateCounters() {
  document.querySelectorAll('[data-count]').forEach(el => {
    const target = parseFloat(el.dataset.count);
    const prefix = el.dataset.prefix || '';
    const suffix = el.dataset.suffix || '';
    const decimals = el.dataset.decimals ? parseInt(el.dataset.decimals) : 0;
    let start = 0;
    const step = target / 50;
    const timer = setInterval(() => {
      start = Math.min(start + step, target);
      el.textContent = prefix + start.toFixed(decimals) + suffix;
      if (start >= target) clearInterval(timer);
    }, 20);
  });
}