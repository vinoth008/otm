"""
monthly_forecast.py — Forecasts monthly spending for upcoming months
using moving averages and trend analysis.
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


def forecast_monthly_spending(transactions, months=3):
    """Forecast spending for the next N months using moving average."""
    monthly = defaultdict(float)
    for txn in transactions:
        if txn.get("type") != "expense":
            continue
        date = _parse_date(txn.get("date"))
        if not date:
            continue
        monthly[date.strftime("%Y-%m")] += float(txn.get("amount", 0))

    if not monthly:
        return {"forecast": [], "message": "No expense data available"}

    sorted_months = sorted(monthly.keys())
    amounts = [monthly[m] for m in sorted_months]

    # Use last 3 months average as baseline
    window = min(3, len(amounts))
    baseline = sum(amounts[-window:]) / window

    # Simple trend: compare last half vs first half
    if len(amounts) >= 4:
        half = len(amounts) // 2
        first_half_avg = sum(amounts[:half]) / half
        second_half_avg = sum(amounts[half:]) / (len(amounts) - half)
        trend_factor = second_half_avg / first_half_avg if first_half_avg > 0 else 1.0
    else:
        trend_factor = 1.0

    # Generate forecast months
    now = datetime.utcnow()
    forecast = []
    for i in range(1, months + 1):
        forecast_month = (now.replace(day=1) + timedelta(days=32 * i)).replace(day=1)
        predicted = baseline * (trend_factor ** i)
        forecast.append({
            "month": forecast_month.strftime("%Y-%m"),
            "predicted_amount": round(predicted, 2),
        })

    return {
        "forecast": forecast,
        "baseline": round(baseline, 2),
        "trend_factor": round(trend_factor, 3),
        "historical_months": sorted_months,
        "historical_amounts": [round(a, 2) for a in amounts],
    }