"""
fraud_detection.py — Detects potential fraud patterns in transactions
like rapid successive spending, unusual hour activity, and amount anomalies.
"""

import statistics
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


def detect_fraud_patterns(transactions):
    """Detect possible fraud patterns in transaction data."""
    expenses = [t for t in transactions if t.get("type") == "expense"]
    risks = []
    risk_score = 0

    if len(expenses) < 5:
        return {
            "risk_level": "low",
            "risk_score": 0,
            "flags": [],
            "message": "Not enough data for fraud detection",
        }

    # ── Flag 1: Multiple transactions same day same merchant ──────
    same_day_merchant = defaultdict(int)
    for txn in expenses:
        date = _parse_date(txn.get("date"))
        if not date:
            continue
        key = (date.strftime("%Y-%m-%d"), txn.get("merchant", "Unknown"))
        same_day_merchant[key] += 1

    for (day, merchant), count in same_day_merchant.items():
        if count >= 3:
            risks.append({
                "type": "rapid_successive",
                "description": f"{count} transactions with {merchant} on {day}",
                "severity": "medium",
            })
            risk_score += 20

    # ── Flag 2: Amounts significantly above average ───────────────
    amounts = [float(t.get("amount", 0)) for t in expenses]
    mean = statistics.mean(amounts)
    stdev = statistics.stdev(amounts) if len(amounts) > 1 else 0
    for txn in expenses:
        amount = float(txn.get("amount", 0))
        if stdev > 0 and amount > mean + 3 * stdev:
            risks.append({
                "type": "large_amount",
                "description": f"Amount ₹{round(amount, 2)} is unusually large ({txn.get('merchant', 'Unknown')})",
                "severity": "high",
            })
            risk_score += 30

    # ── Flag 3: Late night transactions ───────────────────────────
    late_night_count = 0
    for txn in expenses:
        txn_time = txn.get("time") or txn.get("created_at")
        if txn_time:
            try:
                if isinstance(txn_time, str):
                    txn_time = datetime.fromisoformat(txn_time)
                if isinstance(txn_time, datetime) and txn_time.hour >= 23 or (isinstance(txn_time, datetime) and txn_time.hour <= 4):
                    late_night_count += 1
            except (ValueError, TypeError):
                pass

    if late_night_count >= 3:
        risks.append({
            "type": "late_night_activity",
            "description": f"{late_night_count} transactions between 11 PM and 4 AM",
            "severity": "medium",
        })
        risk_score += 15

    # ── Determine risk level ──────────────────────────────────────
    if risk_score >= 60:
        risk_level = "high"
    elif risk_score >= 30:
        risk_level = "medium"
    else:
        risk_level = "low"

    return {
        "risk_level": risk_level,
        "risk_score": min(100, risk_score),
        "flags": risks,
        "message": f"Fraud risk level: {risk_level}",
    }