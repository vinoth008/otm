"""
spending_analysis.py — Smart spending analysis for the Expense Tracker.
Provides category analysis, weekly/monthly insights, frequent merchant detection,
unusual expense detection, financial health scoring, and saving suggestions.
"""

import statistics
from collections import defaultdict
from datetime import datetime, timedelta

# ── Helper: parse date ────────────────────────────────────────────
def _parse_date(value):
    """Parse a date from string or datetime object."""
    if isinstance(value, datetime):
        return value
    if isinstance(value, str):
        try:
            return datetime.fromisoformat(value)
        except ValueError:
            return None
    return None


def _get_expenses(transactions):
    """Filter transactions to expenses only."""
    return [t for t in transactions if t.get("type") == "expense"]


def _get_income(transactions):
    """Filter transactions to income only."""
    return [t for t in transactions if t.get("type") == "income"]


# ── Category Spending Analysis ─────────────────────────────────────
def analyze_category_spending(transactions):
    """
    Analyze spending by category.
    Returns per-category totals, percentages, and average transaction amounts.
    """
    expenses = _get_expenses(transactions)
    category_totals = defaultdict(float)
    category_counts = defaultdict(int)
    category_amounts = defaultdict(list)

    for txn in expenses:
        cat = txn.get("category", "Others")
        amount = float(txn.get("amount", 0))
        category_totals[cat] += amount
        category_counts[cat] += 1
        category_amounts[cat].append(amount)

    total_spent = sum(category_totals.values())
    result = []
    for cat, total in sorted(category_totals.items(), key=lambda x: x[1], reverse=True):
        amounts = category_amounts[cat]
        result.append({
            "category": cat,
            "total": round(total, 2),
            "count": category_counts[cat],
            "percentage": round((total / total_spent * 100), 2) if total_spent > 0 else 0,
            "average": round(statistics.mean(amounts), 2) if amounts else 0,
            "max": round(max(amounts), 2) if amounts else 0,
            "min": round(min(amounts), 2) if amounts else 0,
        })
    return {"total_spent": round(total_spent, 2), "categories": result}


# ── Weekly Insights ────────────────────────────────────────────────
def analyze_weekly_insights(transactions):
    """Generate insights for the current week vs previous week."""
    now = datetime.now()
    # Current week: Monday to Sunday
    current_week_start = now - timedelta(days=now.weekday())
    current_week_start = current_week_start.replace(hour=0, minute=0, second=0, microsecond=0)
    current_week_end = current_week_start + timedelta(days=7)
    # Previous week
    prev_week_start = current_week_start - timedelta(days=7)
    prev_week_end = current_week_start

    current_expenses = []
    prev_expenses = []
    current_income = []
    prev_income = []

    for txn in transactions:
        date = _parse_date(txn.get("date"))
        if not date:
            continue
        amount = float(txn.get("amount", 0))
        txn_type = txn.get("type")
        if current_week_start <= date < current_week_end:
            if txn_type == "expense":
                current_expenses.append(amount)
            elif txn_type == "income":
                current_income.append(amount)
        elif prev_week_start <= date < prev_week_end:
            if txn_type == "expense":
                prev_expenses.append(amount)
            elif txn_type == "income":
                prev_income.append(amount)

    current_expense_total = sum(current_expenses)
    prev_expense_total = sum(prev_expenses)
    current_income_total = sum(current_income)
    prev_income_total = sum(prev_income)

    expense_change = 0
    if prev_expense_total > 0:
        expense_change = round(((current_expense_total - prev_expense_total) / prev_expense_total) * 100, 2)

    income_change = 0
    if prev_income_total > 0:
        income_change = round(((current_income_total - prev_income_total) / prev_income_total) * 100, 2)

    # Top spending category this week
    category_spend = defaultdict(float)
    for txn in transactions:
        date = _parse_date(txn.get("date"))
        if date and current_week_start <= date < current_week_end and txn.get("type") == "expense":
            category_spend[txn.get("category", "Others")] += float(txn.get("amount", 0))

    top_category = max(category_spend.items(), key=lambda x: x[1]) if category_spend else ("None", 0)

    return {
        "week_start": current_week_start.strftime("%Y-%m-%d"),
        "week_end": (current_week_end - timedelta(seconds=1)).strftime("%Y-%m-%d"),
        "current_expense": round(current_expense_total, 2),
        "previous_expense": round(prev_expense_total, 2),
        "expense_change_percent": expense_change,
        "current_income": round(current_income_total, 2),
        "previous_income": round(prev_income_total, 2),
        "income_change_percent": income_change,
        "top_category": top_category[0],
        "top_category_amount": round(top_category[1], 2),
        "transaction_count": len(current_expenses),
        "insight": _generate_weekly_insight(expense_change, income_change, current_expense_total, current_income_total),
    }


def _generate_weekly_insight(expense_change, income_change, expense_total, income_total):
    """Generate a human-readable weekly insight message."""
    if expense_change > 20:
        return f"Your spending increased by {expense_change}% this week. Consider reviewing your expenses."
    elif expense_change < -20:
        return f"Great job! Your spending decreased by {abs(expense_change)}% this week."
    elif income_total > expense_total:
        return f"You saved ₹{round(income_total - expense_total, 2)} this week. Keep it up!"
    else:
        return "Your spending is close to or exceeding your income this week. Plan carefully."


# ── Monthly Insights ───────────────────────────────────────────────
def analyze_monthly_insights(transactions):
    """Generate insights for the current month."""
    now = datetime.utcnow()
    month_start = now.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    next_month = (month_start + timedelta(days=32)).replace(day=1)
    prev_month_start = (month_start - timedelta(days=1)).replace(day=1)
    prev_month_end = month_start

    current_expenses = []
    prev_expenses = []
    current_income = []
    prev_income = []

    for txn in transactions:
        date = _parse_date(txn.get("date"))
        if not date:
            continue
        amount = float(txn.get("amount", 0))
        txn_type = txn.get("type")
        if month_start <= date < next_month:
            if txn_type == "expense":
                current_expenses.append(amount)
            elif txn_type == "income":
                current_income.append(amount)
        elif prev_month_start <= date < prev_month_end:
            if txn_type == "expense":
                prev_expenses.append(amount)
            elif txn_type == "income":
                prev_income.append(amount)

    current_expense_total = sum(current_expenses)
    prev_expense_total = sum(prev_expenses)
    current_income_total = sum(current_income)
    prev_income_total = sum(prev_income)

    expense_change = 0
    if prev_expense_total > 0:
        expense_change = round(((current_expense_total - prev_expense_total) / prev_expense_total) * 100, 2)

    # Daily average spending
    days_elapsed = (now - month_start).days + 1
    daily_avg = round(current_expense_total / days_elapsed, 2) if days_elapsed > 0 else 0

    # Projected monthly spend
    projected = round(daily_avg * 30, 2) if daily_avg > 0 else 0

    # Category breakdown
    category_spend = defaultdict(float)
    for txn in transactions:
        date = _parse_date(txn.get("date"))
        if date and month_start <= date < next_month and txn.get("type") == "expense":
            category_spend[txn.get("category", "Others")] += float(txn.get("amount", 0))

    top_categories = sorted(category_spend.items(), key=lambda x: x[1], reverse=True)[:5]

    return {
        "month": month_start.strftime("%Y-%m"),
        "current_expense": round(current_expense_total, 2),
        "previous_expense": round(prev_expense_total, 2),
        "expense_change_percent": expense_change,
        "current_income": round(current_income_total, 2),
        "previous_income": round(prev_income_total, 2),
        "income_change_percent": income_change,
        "daily_average": daily_avg,
        "projected_monthly_expense": projected,
        "top_categories": [{"category": c, "amount": round(a, 2)} for c, a in top_categories],
        "transaction_count": len(current_expenses),
        "insight": _generate_monthly_insight(expense_change, current_expense_total, current_income_total, projected),
    }


def _generate_monthly_insight(expense_change, expense_total, income_total, projected):
    """Generate a human-readable monthly insight message."""
    if expense_change > 15:
        return f"Spending is up {expense_change}% vs last month. Projected total: ₹{projected}."
    elif expense_change < -15:
        return f"Spending is down {abs(expense_change)}% vs last month. Great control!"
    elif income_total > expense_total:
        return f"You're saving ₹{round(income_total - expense_total, 2)} this month. Excellent!"
    else:
        return "Expenses exceed income this month. Consider cutting discretionary spending."


# ── Frequent Merchant Detection ────────────────────────────────────
def detect_frequent_merchants(transactions, min_count=3):
    """Detect merchants the user frequently transacts with."""
    merchant_stats = defaultdict(lambda: {"count": 0, "total": 0.0, "categories": set()})

    for txn in transactions:
        merchant = txn.get("merchant") or txn.get("description") or "Unknown"
        amount = float(txn.get("amount", 0))
        merchant_stats[merchant]["count"] += 1
        merchant_stats[merchant]["total"] += amount
        merchant_stats[merchant]["categories"].add(txn.get("category", "Others"))

    frequent = []
    for merchant, stats in merchant_stats.items():
        if stats["count"] >= min_count:
            frequent.append({
                "merchant": merchant,
                "count": stats["count"],
                "total_spent": round(stats["total"], 2),
                "average": round(stats["total"] / stats["count"], 2),
                "categories": list(stats["categories"]),
            })

    frequent.sort(key=lambda x: x["count"], reverse=True)
    return {"frequent_merchants": frequent}


# ── Unusual Expense Detection ──────────────────────────────────────
def detect_unusual_expenses(transactions, zscore_threshold=2.0):
    """Detect expenses that are statistically unusual compared to the user's normal spending."""
    expenses = _get_expenses(transactions)
    if len(expenses) < 5:
        return {"unusual_expenses": [], "message": "Not enough data to detect unusual expenses"}

    amounts = [float(t.get("amount", 0)) for t in expenses]
    mean = statistics.mean(amounts)
    stdev = statistics.stdev(amounts) if len(amounts) > 1 else 0

    if stdev == 0:
        return {"unusual_expenses": [], "message": "No variance in spending"}

    unusual = []
    for txn in expenses:
        amount = float(txn.get("amount", 0))
        zscore = (amount - mean) / stdev
        if abs(zscore) >= zscore_threshold:
            unusual.append({
                "id": str(txn.get("_id", "")),
                "amount": round(amount, 2),
                "category": txn.get("category", "Others"),
                "merchant": txn.get("merchant", ""),
                "date": str(txn.get("date", "")),
                "zscore": round(zscore, 2),
                "reason": "Unusually high" if zscore > 0 else "Unusually low",
            })

    unusual.sort(key=lambda x: x["zscore"], reverse=True)
    return {"unusual_expenses": unusual, "mean": round(mean, 2), "stdev": round(stdev, 2)}


# ── Financial Health Score ─────────────────────────────────────────
def calculate_financial_health(transactions):
    """Calculate a financial health score (0-100) based on multiple factors."""
    expenses = _get_expenses(transactions)
    income = _get_income(transactions)

    if not income:
        return {"score": 0, "grade": "F", "factors": {}, "message": "No income data available"}

    total_income = sum(float(t.get("amount", 0)) for t in income)
    total_expense = sum(float(t.get("amount", 0)) for t in expenses)

    # Savings rate (30% weight)
    savings_rate = (total_income - total_expense) / total_income if total_income > 0 else 0
    savings_score = min(100, max(0, savings_rate * 200))  # 50% savings = 100

    # Expense stability (20% weight) — lower variance = better
    if len(expenses) >= 3:
        amounts = [float(t.get("amount", 0)) for t in expenses]
        cv = statistics.stdev(amounts) / statistics.mean(amounts) if statistics.mean(amounts) > 0 else 1
        stability_score = max(0, min(100, 100 - cv * 50))
    else:
        stability_score = 50

    # Income stability (15% weight)
    if len(income) >= 2:
        income_amounts = [float(t.get("amount", 0)) for t in income]
        income_cv = statistics.stdev(income_amounts) / statistics.mean(income_amounts) if statistics.mean(income_amounts) > 0 else 1
        income_stability = max(0, min(100, 100 - income_cv * 50))
    else:
        income_stability = 50

    # Budget adherence (25% weight) — assume 50% default if no budget data
    budget_adherence = 50

    # Debt ratio (10% weight) — assume no debt data, give neutral score
    debt_ratio_score = 100

    # Weighted score
    score = round(
        savings_score * 0.30 +
        budget_adherence * 0.25 +
        stability_score * 0.20 +
        income_stability * 0.15 +
        debt_ratio_score * 0.10
    )

    # Grade
    if score >= 80:
        grade = "Excellent"
    elif score >= 60:
        grade = "Good"
    elif score >= 40:
        grade = "Fair"
    else:
        grade = "Poor"

    return {
        "score": score,
        "grade": grade,
        "factors": {
            "savings_rate": round(savings_rate * 100, 2),
            "savings_score": round(savings_score, 2),
            "expense_stability_score": round(stability_score, 2),
            "income_stability_score": round(income_stability, 2),
            "budget_adherence_score": budget_adherence,
            "debt_ratio_score": debt_ratio_score,
        },
        "message": f"Your financial health is {grade} (Score: {score}/100)",
    }


# ── Saving Suggestions ─────────────────────────────────────────────
def generate_saving_suggestions(transactions):
    """Generate personalized saving suggestions based on spending patterns."""
    expenses = _get_expenses(transactions)
    if not expenses:
        return {"suggestions": [], "message": "No expense data available"}

    # Category analysis
    category_totals = defaultdict(float)
    for txn in expenses:
        category_totals[txn.get("category", "Others")] += float(txn.get("amount", 0))

    total_spent = sum(category_totals.values())
    suggestions = []

    # Suggest reducing top discretionary categories
    discretionary = ["Entertainment", "Shopping", "Food", "Recharge"]
    for cat in discretionary:
        if cat in category_totals:
            cat_total = category_totals[cat]
            pct = (cat_total / total_spent * 100) if total_spent > 0 else 0
            if pct > 20:
                suggestions.append({
                    "category": cat,
                    "current_spend": round(cat_total, 2),
                    "percentage": round(pct, 2),
                    "suggestion": f"Your {cat} spending is {pct:.1f}% of total. Try reducing by 10% to save ₹{round(cat_total * 0.1, 2)}.",
                    "potential_savings": round(cat_total * 0.1, 2),
                })

    # Suggest setting a daily budget
    if len(expenses) >= 5:
        daily_spend = total_spent / 30  # approximate
        suggestions.append({
            "category": "Daily Budget",
            "suggestion": f"Set a daily budget of ₹{round(daily_spend, 2)} to control daily spending.",
            "potential_savings": round(daily_spend * 0.05, 2),
        })

    # Suggest automating savings
    suggestions.append({
        "category": "Automated Savings",
        "suggestion": "Set up an automatic transfer of 10% of income to savings on payday.",
        "potential_savings": 0,
    })

    return {"suggestions": suggestions}