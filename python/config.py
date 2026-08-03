"""
config.py — Configuration for the Smart Transaction Control Python Analytics Engine.
Loads MongoDB connection settings and shared constants.
"""

import os
from datetime import datetime, timedelta, timezone

# ── MongoDB Configuration ─────────────────────────────────────────
# Default to the same Atlas URI used by the PHP backend.
MONGODB_URI = os.environ.get(
    "MONGODB_URI",
    "mongodb+srv://vinothyokesh008009_db_user:T6AEVJBfBWlhYx8q@expense-tracker.hqmyhrg.mongodb.net/"
)
DB_NAME = os.environ.get("DB_NAME", "smart_transaction_control")

# ── Application Constants ─────────────────────────────────────────
APP_NAME = "Smart Transaction Control"
APP_VERSION = "1.0.0"
TIMEZONE = "Asia/Kolkata"

# ── Analytics Constants ───────────────────────────────────────────
DEFAULT_CURRENCY = "INR"
ANOMALY_ZSCORE_THRESHOLD = 2.0
FREQUENT_MERCHANT_MIN_COUNT = 3
BUDGET_WARNING_THRESHOLDS = [50, 75, 90, 100]
FINANCIAL_HEALTH_WEIGHTS = {
    "savings_rate": 0.30,
    "budget_adherence": 0.25,
    "expense_stability": 0.20,
    "income_stability": 0.15,
    "debt_ratio": 0.10,
}

# ── Category Defaults ─────────────────────────────────────────────
INCOME_CATEGORIES = ["Salary", "Freelance", "Business", "Interest", "Gift", "Refund"]
EXPENSE_CATEGORIES = [
    "Food", "Grocery", "Fuel", "Travel", "Shopping", "Entertainment",
    "Education", "Medical", "Bills", "Rent", "EMI", "Recharge", "Others"
]

# ── Helper: current UTC timestamp ─────────────────────────────────
def utc_now():
    """Return current UTC datetime."""
    return datetime.now(timezone.utc)