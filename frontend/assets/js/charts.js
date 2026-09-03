/**
 * charts.js — Chart.js wrapper utilities for SecureSOT dashboards
 * Requires Chart.js to be loaded before this file.
 */

// ── Default palette ────────────────────────────────────────────────
const CHART_COLORS = [
  '#6366f1', '#8b5cf6', '#ec4899', '#f43f5e', '#f97316',
  '#eab308', '#22c55e', '#14b8a6', '#3b82f6', '#06b6d4',
];

const CHART_DEFAULTS = {
  font: { family: 'Inter, sans-serif', size: 12 },
  responsive: true,
  maintainAspectRatio: false,
  animation: { duration: 600, easing: 'easeOutQuart' },
};

// ── Apply global defaults ──────────────────────────────────────────
if (typeof Chart !== 'undefined') {
  Chart.defaults.font.family = CHART_DEFAULTS.font.family;
  Chart.defaults.font.size   = CHART_DEFAULTS.font.size;
  Chart.defaults.color       = '#94a3b8';
}

/**
 * Create (or update) a doughnut/pie chart.
 * @param {string} canvasId   The canvas element ID
 * @param {string[]} labels
 * @param {number[]} values
 * @param {object}  opts      Additional Chart.js options
 */
function createDoughnutChart(canvasId, labels, values, opts = {}) {
  const ctx = document.getElementById(canvasId);
  if (!ctx) return null;
  if (ctx._chartInstance) ctx._chartInstance.destroy();

  const chart = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: CHART_COLORS.slice(0, values.length),
        borderColor: 'transparent',
        hoverOffset: 8,
        borderRadius: 4,
      }],
    },
    options: {
      ...CHART_DEFAULTS,
      cutout: '70%',
      plugins: {
        legend: { position: 'bottom', labels: { padding: 16, boxWidth: 12 } },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.label}: ₹${Number(ctx.raw).toLocaleString('en-IN')}`,
          },
        },
        ...((opts.plugins) || {}),
      },
      ...opts,
    },
  });
  ctx._chartInstance = chart;
  return chart;
}

/**
 * Create (or update) a bar chart.
 */
function createBarChart(canvasId, labels, datasets, opts = {}) {
  const ctx = document.getElementById(canvasId);
  if (!ctx) return null;
  if (ctx._chartInstance) ctx._chartInstance.destroy();

  const chart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: datasets.map((ds, i) => ({
        backgroundColor: ds.color ?? CHART_COLORS[i % CHART_COLORS.length],
        borderRadius: 6,
        borderSkipped: false,
        ...ds,
      })),
    },
    options: {
      ...CHART_DEFAULTS,
      scales: {
        x: { grid: { display: false }, ticks: { color: '#94a3b8' } },
        y: {
          beginAtZero: true,
          grid: { color: 'rgba(148,163,184,0.1)' },
          ticks: { color: '#94a3b8', callback: v => '₹' + Number(v).toLocaleString('en-IN') },
        },
      },
      plugins: {
        legend: { position: 'top' },
        tooltip: { callbacks: { label: c => ` ${c.dataset.label}: ₹${Number(c.raw).toLocaleString('en-IN')}` } },
      },
      ...opts,
    },
  });
  ctx._chartInstance = chart;
  return chart;
}

/**
 * Create (or update) a line chart.
 */
function createLineChart(canvasId, labels, datasets, opts = {}) {
  const ctx = document.getElementById(canvasId);
  if (!ctx) return null;
  if (ctx._chartInstance) ctx._chartInstance.destroy();

  const chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: datasets.map((ds, i) => ({
        borderColor: ds.color ?? CHART_COLORS[i % CHART_COLORS.length],
        backgroundColor: (ds.color ?? CHART_COLORS[i % CHART_COLORS.length]) + '22',
        tension: 0.4,
        fill: true,
        pointRadius: 4,
        pointHoverRadius: 7,
        ...ds,
      })),
    },
    options: {
      ...CHART_DEFAULTS,
      scales: {
        x: { grid: { display: false }, ticks: { color: '#94a3b8' } },
        y: {
          beginAtZero: true,
          grid: { color: 'rgba(148,163,184,0.1)' },
          ticks: { color: '#94a3b8', callback: v => '₹' + Number(v).toLocaleString('en-IN') },
        },
      },
      plugins: {
        legend: { position: 'top' },
        tooltip: { mode: 'index', intersect: false, callbacks: { label: c => ` ${c.dataset.label}: ₹${Number(c.raw).toLocaleString('en-IN')}` } },
      },
      ...opts,
    },
  });
  ctx._chartInstance = chart;
  return chart;
}

/**
 * Create a horizontal bar chart (good for category rankings).
 */
function createHorizontalBarChart(canvasId, labels, values, opts = {}) {
  const ctx = document.getElementById(canvasId);
  if (!ctx) return null;
  if (ctx._chartInstance) ctx._chartInstance.destroy();

  const chart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: CHART_COLORS.slice(0, values.length),
        borderRadius: 4,
      }],
    },
    options: {
      ...CHART_DEFAULTS,
      indexAxis: 'y',
      scales: {
        x: {
          beginAtZero: true,
          grid: { color: 'rgba(148,163,184,0.1)' },
          ticks: { color: '#94a3b8', callback: v => '₹' + Number(v).toLocaleString('en-IN') },
        },
        y: { grid: { display: false }, ticks: { color: '#94a3b8' } },
      },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: c => ` ₹${Number(c.raw).toLocaleString('en-IN')}` } },
      },
      ...opts,
    },
  });
  ctx._chartInstance = chart;
  return chart;
}

/**
 * Destroy all chart instances on a page (useful before re-rendering).
 */
function destroyAllCharts() {
  document.querySelectorAll('canvas').forEach(canvas => {
    if (canvas._chartInstance) {
      canvas._chartInstance.destroy();
      canvas._chartInstance = null;
    }
  });
}
