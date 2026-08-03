"""
budget_recommendation.py — Recommends optimal budget allocation
based on income and historical spending patterns (50/30/20 rule).
"""

from collections import defaultdict
from datetime import datetime, timedelta


def _parse_date(value):
    if isinstance(value, datetime):
        return value
    if isinstance(value, str):
        try:
            return datetime.fromisoformat(value)
        except ValueError:
            return None
    return None


def recommend_budget_allocation(transactions, monthly_income):
    """Recommend budget allocation using the 50/30/20 rule adapted to categories."""
    if monthly_income <= 0:
        return {"error": "Monthly income must be positive"}

    # Categorize expenses into needs, wants, savings
    needs_categories = {"Food", "Grocery", "Rent", "Bills", "Medical", "EMI", "Education", "Fuel"}
    wants_categories = {"Shopping", "Entertainment", "Travel", "Recharge", "Others"}

    expenses = [t for t in transactions if t.get("type") == "expense"]
    needs_spend = 0.0
    wants_spend = 0.0

    for txn in expenses:
        cat = txn.get("category", "Others")
        amount = float(txn.get("amount", 0))
        if cat in needs_categories:
            needs_spend += amount
        elif cat in wants_categories:
            wants_spend += amount
        else:
            wants_spend += amount

    total_spend = needs_spend + wants_spend

    # 50/30/20 rule
    needs_budget = monthly_income * 0.50
    wants_budget = monthly_income * 0.30
    savings_budget = monthly_income * 0.20

    # Category-level recommendations based on historical proportions
    category_totals = defaultdict(float)
    for txn in expenses:
        category_totals[txn.get("category", "Others")] += float(txn.get("amount", 0))

    category_recommendations = []
    for cat, total in sorted(category_totals.items(), key=lambda x: x[1], reverse=True):
        pct_of_income = (total / monthly_income * 100) if monthly_income > 0 else 0
        recommendation = "Within budget" if pct_of_income <= 20 else "Consider reducing"
        category_recommendations.append({
            "category": cat,
            "monthly_average": round(total / 3, 2) if len(expenses) > 0 else 0,
            "percent_of_income": round(pct_of_income, 2),
            "recommendation": recommendation,
        })

    return {
        "monthly_income": round(monthly_income, 2),
        "recommended_allocation": {
            "needs_50": round(needs_budget, 2),
            "wants_30": round(wants_budget, 2),
            "savings_20": round(savings_budget, 2),
        },
        "current_spending": {
            "needs": round(needs_spend, 2),
            "wants": round(wants_spend, 2),
            "total": round(total_spend, 2),
        },
        "category_recommendations": category_recommendations,
        "tips": [
            "Try to keep needs under 50% of income",
            "Limit wants to 30% of income",
            "Save at least 20% of income every month",
        ],
    }