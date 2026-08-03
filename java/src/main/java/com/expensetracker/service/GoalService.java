package com.expensetracker.service;

import java.time.LocalDate;
import java.time.temporal.ChronoUnit;
import java.util.List;

/**
 * GoalService — Business logic for savings goals tracking.
 */
public class GoalService {

    /** Savings goal model */
    public static class Goal {
        private String id;
        private String userId;
        private String name;
        private double targetAmount;
        private double savedAmount;
        private LocalDate targetDate;
        private String status; // "active", "completed", "paused"
        private String createdAt;

        public Goal() {}

        public Goal(String id, String userId, String name,
                    double targetAmount, double savedAmount, LocalDate targetDate) {
            this.id = id;
            this.userId = userId;
            this.name = name;
            this.targetAmount = targetAmount;
            this.savedAmount = savedAmount;
            this.targetDate = targetDate;
            this.status = "active";
        }

        public String getId() { return id; }
        public void setId(String id) { this.id = id; }

        public String getUserId() { return userId; }
        public void setUserId(String userId) { this.userId = userId; }

        public String getName() { return name; }
        public void setName(String name) { this.name = name; }

        public double getTargetAmount() { return targetAmount; }
        public void setTargetAmount(double targetAmount) { this.targetAmount = targetAmount; }

        public double getSavedAmount() { return savedAmount; }
        public void setSavedAmount(double savedAmount) { this.savedAmount = savedAmount; }

        public LocalDate getTargetDate() { return targetDate; }
        public void setTargetDate(LocalDate targetDate) { this.targetDate = targetDate; }

        public String getStatus() { return status; }
        public void setStatus(String status) { this.status = status; }

        public String getCreatedAt() { return createdAt; }
        public void setCreatedAt(String createdAt) { this.createdAt = createdAt; }
    }

    /**
     * Validate a savings goal.
     * @throws IllegalArgumentException if validation fails
     */
    public void validateGoal(Goal goal) {
        if (goal == null) {
            throw new IllegalArgumentException("Goal cannot be null");
        }
        if (goal.getUserId() == null || goal.getUserId().trim().isEmpty()) {
            throw new IllegalArgumentException("User ID is required");
        }
        if (goal.getName() == null || goal.getName().trim().isEmpty()) {
            throw new IllegalArgumentException("Goal name is required");
        }
        if (goal.getTargetAmount() <= 0) {
            throw new IllegalArgumentException("Target amount must be positive");
        }
        if (goal.getSavedAmount() < 0) {
            throw new IllegalArgumentException("Saved amount cannot be negative");
        }
        if (goal.getTargetDate() == null) {
            throw new IllegalArgumentException("Target date is required");
        }
    }

    /**
     * Calculate goal progress percentage (0-100+).
     */
    public double calculateProgress(Goal goal) {
        if (goal.getTargetAmount() <= 0) return 0;
        return (goal.getSavedAmount() / goal.getTargetAmount()) * 100;
    }

    /**
     * Calculate remaining amount to reach the goal.
     */
    public double calculateRemainingAmount(Goal goal) {
        return Math.max(0, goal.getTargetAmount() - goal.getSavedAmount());
    }

    /**
     * Calculate days remaining until the target date.
     */
    public long calculateDaysRemaining(Goal goal) {
        if (goal.getTargetDate() == null) return 0;
        return ChronoUnit.DAYS.between(LocalDate.now(), goal.getTargetDate());
    }

    /**
     * Calculate required monthly savings to reach the goal on time.
     */
    public double calculateRequiredMonthlySavings(Goal goal) {
        double remaining = calculateRemainingAmount(goal);
        long daysRemaining = calculateDaysRemaining(goal);
        if (daysRemaining <= 0) return remaining;
        double monthsRemaining = daysRemaining / 30.0;
        return monthsRemaining > 0 ? remaining / monthsRemaining : remaining;
    }

    /**
     * Estimate completion date based on current monthly savings.
     * @param monthlySavings amount saved per month
     * @return estimated completion date, or null if savings is 0
     */
    public LocalDate estimateCompletionDate(Goal goal, double monthlySavings) {
        double remaining = calculateRemainingAmount(goal);
        if (monthlySavings <= 0) return null;
        double monthsNeeded = Math.ceil(remaining / monthlySavings);
        return LocalDate.now().plusMonths((long) monthsNeeded);
    }

    /**
     * Add a contribution to the goal.
     * @throws IllegalArgumentException if amount invalid
     */
    public void addContribution(Goal goal, double amount) {
        if (amount <= 0) {
            throw new IllegalArgumentException("Contribution must be positive");
        }
        goal.setSavedAmount(goal.getSavedAmount() + amount);
        if (goal.getSavedAmount() >= goal.getTargetAmount()) {
            goal.setStatus("completed");
        }
    }

    /**
     * Find goals that are at risk of not being completed on time.
     */
    public List<Goal> getAtRiskGoals(List<Goal> goals, double defaultMonthlySavings) {
        return goals.stream()
            .filter(g -> {
                double required = calculateRequiredMonthlySavings(g);
                return required > 0 && required > defaultMonthlySavings && g.getStatus().equals("active");
            })
            .toList();
    }
}