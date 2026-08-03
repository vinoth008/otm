package com.expensetracker.service;

import java.time.LocalDate;
import java.time.temporal.ChronoUnit;
import java.util.List;

/**
 * ReminderService — Business logic for bill reminders and recurring transactions.
 */
public class ReminderService {

    /** Reminder model */
    public static class Reminder {
        private String id;
        private String userId;
        private String title;
        private String type; // electricity, water, internet, gas, mobile, credit_card, emi, insurance, custom
        private double amount;
        private int dueDay; // day of month (1-31)
        private boolean recurring;
        private String frequency; // daily, weekly, monthly, yearly
        private boolean active;
        private String createdAt;

        public Reminder() {}

        public Reminder(String id, String userId, String title, String type,
                        double amount, int dueDay, boolean recurring, String frequency) {
            this.id = id;
            this.userId = userId;
            this.title = title;
            this.type = type;
            this.amount = amount;
            this.dueDay = dueDay;
            this.recurring = recurring;
            this.frequency = frequency;
            this.active = true;
        }

        public String getId() { return id; }
        public void setId(String id) { this.id = id; }

        public String getUserId() { return userId; }
        public void setUserId(String userId) { this.userId = userId; }

        public String getTitle() { return title; }
        public void setTitle(String title) { this.title = title; }

        public String getType() { return type; }
        public void setType(String type) { this.type = type; }

        public double getAmount() { return amount; }
        public void setAmount(double amount) { this.amount = amount; }

        public int getDueDay() { return dueDay; }
        public void setDueDay(int dueDay) { this.dueDay = dueDay; }

        public boolean isRecurring() { return recurring; }
        public void setRecurring(boolean recurring) { this.recurring = recurring; }

        public String getFrequency() { return frequency; }
        public void setFrequency(String frequency) { this.frequency = frequency; }

        public boolean isActive() { return active; }
        public void setActive(boolean active) { this.active = active; }

        public String getCreatedAt() { return createdAt; }
        public void setCreatedAt(String createdAt) { this.createdAt = createdAt; }
    }

    /** Supported reminder types */
    public static final List<String> REMINDER_TYPES = List.of(
        "electricity", "water", "internet", "gas", "mobile_recharge",
        "credit_card", "emi", "insurance", "rent", "custom"
    );

    /**
     * Validate a reminder.
     * @throws IllegalArgumentException if validation fails
     */
    public void validateReminder(Reminder reminder) {
        if (reminder == null) {
            throw new IllegalArgumentException("Reminder cannot be null");
        }
        if (reminder.getUserId() == null || reminder.getUserId().trim().isEmpty()) {
            throw new IllegalArgumentException("User ID is required");
        }
        if (reminder.getTitle() == null || reminder.getTitle().trim().isEmpty()) {
            throw new IllegalArgumentException("Reminder title is required");
        }
        if (reminder.getAmount() < 0) {
            throw new IllegalArgumentException("Amount cannot be negative");
        }
        if (reminder.getDueDay() < 1 || reminder.getDueDay() > 31) {
            throw new IllegalArgumentException("Due day must be between 1 and 31");
        }
        if (reminder.getFrequency() != null &&
            !List.of("daily", "weekly", "monthly", "yearly").contains(reminder.getFrequency())) {
            throw new IllegalArgumentException("Invalid frequency");
        }
    }

    /**
     * Calculate days until the next due date for a reminder.
     */
    public long daysUntilDue(Reminder reminder) {
        LocalDate today = LocalDate.now();
        int currentDay = today.getDayOfMonth();
        int dueDay = reminder.getDueDay();

        LocalDate nextDue;
        if (dueDay >= currentDay) {
            nextDue = today.withDayOfMonth(Math.min(dueDay, today.lengthOfMonth()));
        } else {
            nextDue = today.plusMonths(1).withDayOfMonth(Math.min(dueDay, today.plusMonths(1).lengthOfMonth()));
        }
        return ChronoUnit.DAYS.between(today, nextDue);
    }

    /**
     * Check if a reminder is due within the next N days.
     */
    public boolean isDueWithin(Reminder reminder, int days) {
        return daysUntilDue(reminder) <= days;
    }

    /**
     * Get reminders that are due within the next N days.
     */
    public List<Reminder> getUpcomingReminders(List<Reminder> reminders, int days) {
        return reminders.stream()
            .filter(Reminder::isActive)
            .filter(r -> isDueWithin(r, days))
            .toList();
    }

    /**
     * Generate the next due date for a recurring reminder.
     */
    public LocalDate getNextDueDate(Reminder reminder) {
        LocalDate today = LocalDate.now();
        int dueDay = reminder.getDueDay();
        if (dueDay >= today.getDayOfMonth()) {
            return today.withDayOfMonth(Math.min(dueDay, today.lengthOfMonth()));
        }
        return today.plusMonths(1).withDayOfMonth(Math.min(dueDay, today.plusMonths(1).lengthOfMonth()));
    }
}