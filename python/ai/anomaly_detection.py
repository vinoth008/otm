"""
anomaly_detection.py — Detects anomalies in spending patterns
using statistical methods (z-score, IQR).
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


def detect_anomalies(transactions, threshold=2.0):
    """Detect anomalous transactions using z-score analysis."""
    expenses = [t for t in transactions if t.get("type") == "expense"]
    if len(expenses) < 5:
        return {"anomalies": [], "message": "Not enough data for anomaly detection"}

    amounts = [float(t.get("amount", 0)) for t in expenses]
    mean = statistics.mean(amounts)
    stdev = statistics.stdev(amounts) if len(amounts) > 1 else 0

    if stdev == 0:
        return {"anomalies": [], "message": "No variance in spending"}

    anomalies = []
    for txn in expenses:
        amount = float(txn.get("amount", 0))
        zscore = (amount - mean) / stdev
        if abs(zscore) >= threshold:
            anomalies.append({
                "id": str(txn.get("_id", "")),
                "amount": round(amount, 2),
                "category": txn.get("category", "Others"),
                "merchant": txn.get("merchant", ""),
                "date": str(txn.get("date", "")),
                "zscore": round(zscore, 2),
                "severity": "high" if abs(zscore) >= 3 else "medium",
            })

    anomalies.sort(key=lambda x: x["zscore"], reverse=True)
    return {
        "anomalies": anomalies,
        "mean": round(mean, 2),
        "stdev": round(stdev, 2),
        "threshold": threshold,
    }