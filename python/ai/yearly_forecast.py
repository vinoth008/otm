"""
yearly_forecast.py — Forecasts yearly spending based on historical data.
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


def forecast_yearly_spending(transactions):
    """Forecast total spending for the current year based on YTD data."""
    now = datetime.utcnow()
    year_start = now.replace(month=1, day=1, hour=0, minute=0, second=0, microsecond=0)

    yearly_spend = 0.0
    for txn in transactions:
        if txn.get("type") != "expense":
            continue
        date = _parse_date(txn.get("date"))
        if not date:
            continue
        if date >= year_start:
            yearly_spend += float(txn.get("amount", 0))

    # Days elapsed in year
    days_elapsed = (now - year_start).days + 1
    days_in_year = 365

    if days_elapsed > 0:
        daily_avg = yearly_spend / days_elapsed
        projected = daily_avg * days_in_year
    else:
        projected = yearly_spend

    return {
        "year": now.year,
        "ytd_spend": round(yearly_spend, 2),
        "projected_yearly_spend": round(projected, 2),
        "days_elapsed": days_elapsed,
        "daily_average": round(daily_avg, 2) if days_elapsed > 0 else 0,
        "message": f"Projected yearly spend: ₹{round(projected, 2)}",
    }