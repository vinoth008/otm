"""
expense_prediction.py — Predicts future expenses and budget usage
using historical transaction data and simple linear regression.
"""

import statistics
from collections import defaultdict
from datetime import datetime, timedelta


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


def _monthly_totals(transactions, txn_type="expense"):
    """Group transaction amounts by month (YYYY-MM)."""
    monthly = defaultdict(float)
    for txn in transactions:
        if txn.get("type") != txn_type:
            continue
        date = _parse_date(txn.get("date"))
        if not date:
            continue
        month_key = date.strftime("%Y-%m")
        monthly[month_key] += float(txn.get("amount", 0))
    return dict(sorted(monthly.items()))


def _linear_regression(x_values, y_values):
    """Simple linear regression. Returns (slope, intercept)."""
    n = len(x_values)
    if n < 2:
        return 0, (y_values[0] if y_values else 0)
    x_mean = sum(x_values) / n
    y_mean = sum(y_values) / n
    numerator = sum((x - x_mean) * (y - y_mean) for x, y in zip(x_values, y_values))
    denominator = sum((x - x_mean) ** 2 for x in x_values)
    if denominator == 0:
        return 0, y_mean
    slope = numerator / denominator
    intercept = y_mean - slope * x_mean
    return slope, intercept


def predict_next_month_expense(transactions):
    """
    Predict next month's total expense using linear regression
    on historical monthly totals.
    """
    monthly = _monthly_totals(transactions, "expense")
    if not monthly:
        return {
            "predicted_amount": 0,
            "confidence": 0,
            "historical_months": [],
            "message": "No expense data available for prediction",
        }

    months = list(monthly.keys())
    amounts = list(monthly.values())
    x_values = list(range(len(amounts)))
    slope, intercept = _linear_regression(x_values, amounts)

    next_x = len(amounts)
    predicted = max(0, slope * next_x + intercept)

    # Confidence based on data volume and variance
    if len(amounts) >= 3:
        stdev = statistics.stdev(amounts)
        mean = statistics.mean(amounts)
        cv = stdev / mean if mean > 0 else 1
        confidence = max(0, min(100, round(100 - cv * 50)))
    else:
        confidence = 30

    return {
        "predicted_amount": round(predicted, 2),
        "confidence": confidence,
        "historical_months": months,
        "historical_amounts": [round(a, 2) for a in amounts],
        "trend": "increasing" if slope > 0 else ("decreasing" if slope < 0 else "stable"),
        "message": f"Predicted next month expense: ₹{round(predicted, 2)}",
    }


def predict_budget_usage(transactions, category=None):
    """
    Predict how much of a budget will be used based on current spending trends.
    """
    expenses = [t for t in transactions if t.get("type") == "expense"]
    if category:
        expenses = [t for t in expenses if t.get("category") == category]

    if not expenses:
        return {
            "predicted_usage": 0,
            "current_spend": 0,
            "category": category or "all",
            "message": "No expense data available",
        }

    # Current month spend
    now = datetime.utcnow()
    month_start = now.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    current_month_spend = 0
    for txn in expenses:
        date = _parse_date(txn.get("date"))
        if date and date >= month_start:
            current_month_spend += float(txn.get("amount", 0))

    # Days elapsed in month
    days_elapsed = now.day
    days_in_month = 30  # approximation

    # Projected spend for the full month
    if days_elapsed > 0:
        daily_avg = current_month_spend / days_elapsed
        projected = daily_avg * days_in_month
    else:
        projected = current_month_spend

    return {
        "predicted_usage": round(projected, 2),
        "current_spend": round(current_month_spend, 2),
        "category": category or "all",
        "days_elapsed": days_elapsed,
        "daily_average": round(current_month_spend / days_elapsed, 2) if days_elapsed > 0 else 0,
        "message": f"Projected monthly spend: ₹{round(projected, 2)}",
    }