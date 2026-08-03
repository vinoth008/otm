package com.expensetracker.service;

import java.time.YearMonth;
import java.util.ArrayList;
import java.util.List;

/**
 * BudgetService — Business logic for budget management,
 * progress tracking, and alert generation.
 */
public class BudgetService {

    /** Budget model */
    public static class Budget {
        private String id;
        private String userId;
        private String type; // "monthly", "category", "daily"
        private String category; // for category budgets
        private double amount;
        private String period; // "YYYY-MM" for monthly, "YYYY-MM-DD" for daily
        private String createdAt;

        public Budget() {}

        public Budget(String id, String userId, String type, String category, double amount, String period) {
            this.id = id;
            this.userId = userId;
            this.type = type;
            this.category = category;
            this.amount = amount;
            this.period = period;
        }

        public String getId() { return id; }
        public void setId(String id) { this.id = id; }

        public String getUserId() { return userId; }
        public void setUserId(String userId) { this.userId = userId; }

        public String getType() { return type; }
        public void setType(String type) { this.type = type; }

        public String getCategory() { return category; }
        public void setCategory(String category) { this.category = category; }

        public double getAmount() { return amount; }
        public void setAmount(double amount) { this.amount = amount; }

        public String getPeriod() { return period; }
        public void setPeriod(String period) { this.period = period; }

        public String getCreatedAt() { return createdAt; }
        public void setCreatedAt(String createdAt) { this.createdAt = createdAt; }
    }

    /** Budget alert levels */
    public static final String ALERT_OK = "OK";
    public static final String ALERT_50 = "50%";
    public static final String ALERT_75 = "75%";
    public static final String ALERT_90 = "90%";
    public static final String ALERT_100 = "100%";
    public static final String ALERT_OVER = "OVER";

    /**
     * Validate budget data.
     * @throws IllegalArgumentException if validation fails
     */
    public void validateBudget(Budget budget) {
        if (budget == null) {
            throw new IllegalArgumentException("Budget cannot be null");
        }
        if (budget.getUserId() == null || budget.getUserId().trim().isEmpty()) {
            throw new IllegalArgumentException("User ID is required");
        }
        if (budget.getAmount() <= 0) {
            throw new IllegalArgumentException("Budget amount must be positive");
        }
        if (budget.getType() == null ||
            (!budget.getType().equals("monthly") &&
             !budget.getType().equals("category") &&
             !budget.getType().equals("daily"))) {
            throw new IllegalArgumentException("Budget type must be monthly, category, or daily");
        }
        if (budget.getType().equals("category") &&
            (budget.getCategory() == null || budget.getCategory().trim().isEmpty())) {
            throw new IllegalArgumentException("Category is required for category budgets");
        }
    }

    /**
     * Calculate budget usage percentage.
     * @return percentage used (0-100+, may exceed 100 when over budget)
     */
    public double calculateUsagePercent(double budgetAmount, double spent) {
        if (budgetAmount <= 0) return 0;
        return (spent / budgetAmount) * 100;
    }

    /**
     * Calculate remaining budget.
     */
    public double calculateRemaining(double budgetAmount, double spent) {
        return budgetAmount - spent;
    }

    /**
     * Determine the current alert level for a budget.
     * @param usagePercent percentage of budget used
     * @return one of ALERT_OK, ALERT_50, ALERT_75, ALERT_90, ALERT_100, ALERT_OVER
     */
    public String getAlertLevel(double usagePercent) {
        if (usagePercent > 100) return ALERT_OVER;
        if (usagePercent >= 100) return ALERT_100;
        if (usagePercent >= 90) return ALERT_90;
        if (usagePercent >= 75) return ALERT_75;
        if (usagePercent >= 50) return ALERT_50;
        return ALERT_OK;
    }

    /**
     * Generate a human-readable alert message for a budget.
     */
    public String generateAlertMessage(Budget budget, double spent) {
        double usagePercent = calculateUsagePercent(budget.getAmount(), spent);
        String level = getAlertLevel(usagePercent);
        String budgetLabel = budget.getType().equals("category")
            ? budget.getCategory() + " budget"
            : budget.getType() + " budget";

        switch (level) {
            case ALERT_50:
                return String.format("%s is 50%% used (₹%.2f of ₹%.2f).",
                    budgetLabel, spent, budget.getAmount());
            case ALERT_75:
                return String.format("%s is 75%% used (₹%.2f of ₹%.2f).",
                    budgetLabel, spent, budget.getAmount());
            case ALERT_90:
                return String.format("%s is 90%% used (₹%.2f of ₹%.2f).",
                    budgetLabel, spent, budget.getAmount());
            case ALERT_100:
                return String.format("%s has reached its limit (₹%.2f).",
                    budgetLabel, budget.getAmount());
            case ALERT_OVER:
                return String.format("%s is over budget by ₹%.2f!",
                    budgetLabel, spent - budget.getAmount());
            default:
                return String.format("%s is on track (₹%.2f of ₹%.2f used).",
                    budgetLabel, spent, budget.getAmount());
        }
    }

    /**
     * Get the current period key (YYYY-MM for monthly, YYYY-MM-DD for daily).
     */
    public String getCurrentPeriod(String type) {
        if (type.equals("daily")) {
            return java.time.LocalDate.now().toString();
        }
        return YearMonth.now().toString();
    }

    /**
     * Filter budgets for the current period.
     */
    public List<Budget> getActiveBudgets(List<Budget> budgets) {
        List<Budget> active = new ArrayList<>();
        for (Budget budget : budgets) {
            String currentPeriod = getCurrentPeriod(budget.getType());
            if (budget.getPeriod() == null || budget.getPeriod().equals(currentPeriod)) {
                active.add(budget);
            }
        }
        return active;
    }
}