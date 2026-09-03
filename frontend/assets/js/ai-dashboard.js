/**
 * ai-dashboard.js — AI Insights widget for SecureSOT dashboards
 * Generates smart spending insights from transaction data.
 */

document.addEventListener('DOMContentLoaded', () => {
  const u = Auth.getUser();
  if (!u) return;

  const insightContainer = document.getElementById('ai-insights');
  if (!insightContainer) return;

  async function generateInsights() {
    insightContainer.innerHTML = `
      <div class="d-flex align-items-center gap-2 text-primary mb-3">
        <div class="spinner-border spinner-border-sm"></div>
        <span>Analyzing your finances…</span>
      </div>`;

    try {
      const [txRes, expRes] = await Promise.all([
        apiGet('?module=transactions&action=category_breakdown&period=month').catch(() => ({ data: [] })),
        apiGet('?module=expenses&action=category_stats&period=month').catch(() => ({ data: [] })),
      ]);

      const txCats  = txRes.data  || [];
      const expCats = expRes.data || [];

      const insights = buildInsights(txCats, expCats);
      renderInsights(insights);
    } catch (e) {
      insightContainer.innerHTML = '<p class="text-muted small">Could not generate insights. Check back later.</p>';
    }
  }

  function buildInsights(txCats, expCats) {
    const tips = [];
    const all  = [...txCats, ...expCats];
    const total = all.reduce((s, c) => s + Number(c.total), 0);

    if (!all.length) {
      return [{ icon: '💡', text: 'Add some transactions to get personalized insights!', type: 'info' }];
    }

    // Top spending category
    const topCat = all.sort((a, b) => b.total - a.total)[0];
    if (topCat) {
      const pct = total > 0 ? ((topCat.total / total) * 100).toFixed(0) : 0;
      tips.push({
        icon: '🔥',
        text: `Your top spending category this month is <strong>${topCat.category}</strong> at ${formatCurrency(topCat.total)} (${pct}% of total).`,
        type: pct > 40 ? 'warning' : 'success',
      });
    }

    // Saving tip
    const income = txCats.filter(c => c.type === 'income').reduce((s, c) => s + Number(c.total), 0);
    if (income > 0) {
      const savings  = income - total;
      const saveRate = ((savings / income) * 100).toFixed(0);
      tips.push({
        icon: saveRate >= 20 ? '🎉' : saveRate >= 0 ? '📊' : '⚠️',
        text: saveRate >= 20
          ? `Great job! You're saving ${saveRate}% of your income this month.`
          : saveRate >= 0
          ? `You're saving ${saveRate}% this month. Aim for 20% for a healthy budget.`
          : `You're spending more than you earn this month. Review your expenses.`,
        type: saveRate >= 20 ? 'success' : saveRate >= 0 ? 'info' : 'danger',
      });
    }

    // Diverse categories tip
    if (all.length < 3) {
      tips.push({ icon: '📝', text: 'Track expenses across more categories for better financial visibility.', type: 'info' });
    }

    // Generic tip
    const genericTips = [
      '💰 The 50/30/20 rule: 50% needs, 30% wants, 20% savings.',
      '📅 Set a monthly budget for each category to stay on track.',
      '🔔 Enable reminders to pay bills on time and avoid late fees.',
      '📈 Investing even ₹500/month consistently can grow significantly over time.',
    ];
    tips.push({ icon: '💡', text: genericTips[Math.floor(Math.random() * genericTips.length)], type: 'light' });

    return tips.slice(0, 4);
  }

  function renderInsights(insights) {
    const colorMap = { success: '#22c55e', warning: '#f59e0b', danger: '#ef4444', info: '#3b82f6', light: '#6366f1' };

    insightContainer.innerHTML = `
      <div class="d-flex align-items-center gap-2 mb-3">
        <div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center">
          <i class="fa-solid fa-robot text-white fa-sm"></i>
        </div>
        <div>
          <div class="fw-bold" style="font-size:0.875rem">AI Insights</div>
          <div class="text-muted" style="font-size:0.75rem">Updated just now</div>
        </div>
        <button class="btn btn-link btn-sm p-0 ms-auto text-primary" id="refresh-insights" title="Refresh">
          <i class="fa-solid fa-rotate-right"></i>
        </button>
      </div>
      <div class="d-flex flex-column gap-2">
        ${insights.map(tip => `
          <div class="d-flex gap-2 align-items-start p-2 rounded" style="background:${colorMap[tip.type]}18;border-left:3px solid ${colorMap[tip.type]}">
            <span style="font-size:1.1rem;flex-shrink:0">${tip.icon}</span>
            <p class="mb-0 small">${tip.text}</p>
          </div>`).join('')}
      </div>`;

    document.getElementById('refresh-insights')?.addEventListener('click', generateInsights);
  }

  generateInsights();
});
