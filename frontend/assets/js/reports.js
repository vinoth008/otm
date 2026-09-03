/**
 * reports.js — Reports page logic for SecureSOT
 */

document.addEventListener('DOMContentLoaded', () => {
  const u = Auth.getUser();
  if (!u) { window.location.href = '../auth/login.html'; return; }

  const periodSelect = document.getElementById('report-period');
  const refreshBtn   = document.getElementById('refresh-btn');
  const exportBtn    = document.getElementById('export-csv-btn');

  let summaryData = null;

  async function loadReport(period = 'month') {
    try {
      setReportLoading(true);

      const [summary, trend, topCats, daily] = await Promise.all([
        apiGet(`?module=reports&action=summary&period=${period}`).catch(() => ({ data: {} })),
        apiGet(`?module=reports&action=monthly_trend&months=6`).catch(() => ({ data: [] })),
        apiGet(`?module=reports&action=top_categories&period=${period}`).catch(() => ({ data: [] })),
        apiGet(`?module=reports&action=daily_totals`).catch(() => ({ data: [] })),
      ]);

      summaryData = summary.data;
      renderSummaryCards(summaryData);
      renderTrendChart(trend.data || []);
      renderCategoryChart(topCats.data || []);
      renderDailyChart(daily.data || []);
    } catch (err) {
      showToast(err.message || 'Failed to load report', 'error');
    } finally {
      setReportLoading(false);
    }
  }

  function setReportLoading(loading) {
    ['income-total','expense-total','net-total','savings-rate'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = loading ? '<div class="spinner-border spinner-border-sm"></div>' : '—';
    });
  }

  function renderSummaryCards(data) {
    if (!data) return;
    setText('#income-total',   formatCurrency(data.total_income   || 0));
    setText('#expense-total',  formatCurrency(data.total_expense  || 0));
    setText('#net-total',      formatCurrency(data.net            || 0));
    setText('#savings-rate',   (data.savings_rate || 0) + '%');

    // Color net card
    const netEl = document.getElementById('net-total');
    if (netEl) netEl.style.color = (data.net || 0) >= 0 ? 'var(--success)' : 'var(--danger)';
  }

  function renderTrendChart(trend) {
    const labels   = trend.map(t => t.month);
    const income   = trend.map(t => t.income  || 0);
    const expense  = trend.map(t => t.expense || 0);

    createLineChart('trend-chart', labels, [
      { label: 'Income',  data: income,  color: '#22c55e' },
      { label: 'Expense', data: expense, color: '#ef4444' },
    ]);
  }

  function renderCategoryChart(cats) {
    if (!cats.length) return;
    createHorizontalBarChart(
      'category-chart',
      cats.map(c => c.category),
      cats.map(c => c.total)
    );
  }

  function renderDailyChart(daily) {
    if (!daily.length) return;
    const labels  = daily.map(d => d.date.slice(5)); // MM-DD
    const income  = daily.map(d => d.income  || 0);
    const expense = daily.map(d => d.expense || 0);

    createBarChart('daily-chart', labels, [
      { label: 'Income',  data: income,  color: '#22c55e' },
      { label: 'Expense', data: expense, color: '#ef4444' },
    ]);
  }

  // ── Helper to setText with selector ─────────────────────────────
  function setText(selector, text) {
    const el = document.querySelector(selector);
    if (el) el.textContent = text;
  }

  // ── Export summary ────────────────────────────────────────────
  exportBtn?.addEventListener('click', () => {
    if (!summaryData) { showToast('Load a report first', 'warning'); return; }
    exportToCSV([{
      Period:       periodSelect?.value || 'month',
      'Total Income':  summaryData.total_income,
      'Total Expense': summaryData.total_expense,
      'Net':           summaryData.net,
      'Savings Rate':  summaryData.savings_rate + '%',
      'From':          summaryData.from,
      'To':            summaryData.to,
    }], `report_${Date.now()}.csv`);
  });

  periodSelect?.addEventListener('change', () => loadReport(periodSelect.value));
  refreshBtn?.addEventListener('click',   () => loadReport(periodSelect?.value || 'month'));

  loadReport('month');
});
