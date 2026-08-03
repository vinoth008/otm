"""
main.py — Flask API server for the Smart Transaction Control Python Analytics Engine.
Exposes endpoints for spending prediction, budget prediction, trend analysis,
monthly forecasts, merchant detection, anomaly detection, saving suggestions,
financial health score, category analysis, and weekly/monthly insights.
"""

import json
import os
import sys
from datetime import datetime, timedelta, timezone
from collections import defaultdict

# Ensure the ai package is importable
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from flask import Flask, jsonify, request
from pymongo import MongoClient

from config import MONGODB_URI, DB_NAME
from ai.spending_analysis import (
    analyze_category_spending,
    analyze_weekly_insights,
    analyze_monthly_insights,
    detect_frequent_merchants,
    detect_unusual_expenses,
    calculate_financial_health,
    generate_saving_suggestions,
)
from ai.expense_prediction import predict_next_month_expense, predict_budget_usage
from ai.monthly_forecast import forecast_monthly_spending
from ai.yearly_forecast import forecast_yearly_spending
from ai.anomaly_detection import detect_anomalies
from ai.budget_recommendation import recommend_budget_allocation
from ai.fraud_detection import detect_fraud_patterns

app = Flask(__name__)

# ── MongoDB connection ────────────────────────────────────────────
client = MongoClient(MONGODB_URI)
db = client[DB_NAME]

def get_transactions(user_id, start_date=None, end_date=None):
    """Fetch transactions for a user, optionally filtered by date range."""
    query = {"user_id": user_id, "deleted_at": None}
    if start_date and end_date:
        query["date"] = {"$gte": start_date, "$lte": end_date}
    return list(db.transactions.find(query))


def get_user_id_from_request():
    """Extract user_id from request query params or JSON body."""
    data = request.get_json(silent=True) or {}
    return data.get("user_id") or request.args.get("user_id")


# ── Health check ──────────────────────────────────────────────────
@app.route("/api/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "service": "analytics-engine", "version": "1.0.0"})


# ── Spending Prediction ───────────────────────────────────────────
@app.route("/api/predict/expense", methods=["POST"])
def predict_expense():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = predict_next_month_expense(transactions)
    return jsonify({"success": True, "data": result})


# ── Budget Prediction ─────────────────────────────────────────────
@app.route("/api/predict/budget", methods=["POST"])
def predict_budget():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    category = data.get("category")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = predict_budget_usage(transactions, category)
    return jsonify({"success": True, "data": result})


# ── Monthly Forecast ──────────────────────────────────────────────
@app.route("/api/forecast/monthly", methods=["POST"])
def forecast_monthly():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    months = int(data.get("months", 3))
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = forecast_monthly_spending(transactions, months)
    return jsonify({"success": True, "data": result})


# ── Yearly Forecast ───────────────────────────────────────────────
@app.route("/api/forecast/yearly", methods=["POST"])
def forecast_yearly():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = forecast_yearly_spending(transactions)
    return jsonify({"success": True, "data": result})


# ── Category Spending Analysis ────────────────────────────────────
@app.route("/api/analysis/categories", methods=["POST"])
def category_analysis():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = analyze_category_spending(transactions)
    return jsonify({"success": True, "data": result})


# ── Weekly Insights ───────────────────────────────────────────────
@app.route("/api/insights/weekly", methods=["POST"])
def weekly_insights():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = analyze_weekly_insights(transactions)
    return jsonify({"success": True, "data": result})


# ── Monthly Insights ──────────────────────────────────────────────
@app.route("/api/insights/monthly", methods=["POST"])
def monthly_insights():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = analyze_monthly_insights(transactions)
    return jsonify({"success": True, "data": result})


# ── Frequent Merchant Detection ───────────────────────────────────
@app.route("/api/merchants/frequent", methods=["POST"])
def frequent_merchants():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = detect_frequent_merchants(transactions)
    return jsonify({"success": True, "data": result})


# ── Unusual Expense Detection ─────────────────────────────────────
@app.route("/api/expenses/unusual", methods=["POST"])
def unusual_expenses():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = detect_unusual_expenses(transactions)
    return jsonify({"success": True, "data": result})


# ── Saving Suggestions ────────────────────────────────────────────
@app.route("/api/savings/suggestions", methods=["POST"])
def saving_suggestions():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = generate_saving_suggestions(transactions)
    return jsonify({"success": True, "data": result})


# ── Financial Health Score ────────────────────────────────────────
@app.route("/api/health/score", methods=["POST"])
def health_score():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = calculate_financial_health(transactions)
    return jsonify({"success": True, "data": result})


# ── Anomaly Detection ─────────────────────────────────────────────
@app.route("/api/anomalies", methods=["POST"])
def anomalies():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = detect_anomalies(transactions)
    return jsonify({"success": True, "data": result})


# ── Budget Recommendation ─────────────────────────────────────────
@app.route("/api/budget/recommend", methods=["POST"])
def budget_recommend():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    monthly_income = float(data.get("monthly_income", 0))
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = recommend_budget_allocation(transactions, monthly_income)
    return jsonify({"success": True, "data": result})


# ── Fraud Detection ───────────────────────────────────────────────
@app.route("/api/fraud/detect", methods=["POST"])
def fraud_detect():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = detect_fraud_patterns(transactions)
    return jsonify({"success": True, "data": result})


# ── Expense Trend Analysis ────────────────────────────────────────
@app.route("/api/trends/expense", methods=["POST"])
def expense_trends():
    data = request.get_json(silent=True) or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"success": False, "message": "user_id is required"}), 400
    transactions = get_transactions(user_id)
    result = analyze_expense_trends(transactions)
    return jsonify({"success": True, "data": result})


def analyze_expense_trends(transactions):
    """Analyze expense trends over time (daily, weekly, monthly)."""
    daily = defaultdict(float)
    weekly = defaultdict(float)
    monthly = defaultdict(float)

    for txn in transactions:
        if txn.get("type") != "expense":
            continue
        date = txn.get("date")
        if not date:
            continue
        if isinstance(date, str):
            date = datetime.fromisoformat(date)
        amount = float(txn.get("amount", 0))
        day_key = date.strftime("%Y-%m-%d")
        week_key = f"{date.isocalendar()[0]}-W{date.isocalendar()[1]}"
        month_key = date.strftime("%Y-%m")
        daily[day_key] += amount
        weekly[week_key] += amount
        monthly[month_key] += amount

    return {
        "daily": dict(sorted(daily.items())),
        "weekly": dict(sorted(weekly.items())),
        "monthly": dict(sorted(monthly.items())),
    }


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5000))
    app.run(host="0.0.0.0", port=port, debug=True)