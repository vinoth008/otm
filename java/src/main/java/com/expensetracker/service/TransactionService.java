package com.expensetracker.service;

import com.expensetracker.model.Transaction;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.YearMonth;
import java.util.*;
import java.util.stream.Collectors;

/**
 * TransactionService — Core business logic for transaction handling,
 * budget calculations, and analysis.
 */
public class TransactionService {

    /** Supported payment methods */
    public static final Set<String> PAYMENT_METHODS = Set.of(
        "cash", "bank", "credit_card", "debit_card", "upi", "wallet"
    );

    /** Default expense categories */
    public static final Set<String> DEFAULT_EXPENSE_CATEGORIES = Set.of(
        "Food", "Grocery", "Fuel", "Travel", "Shopping", "Entertainment",
        "Education", "Medical", "Bills", "Rent", "EMI", "Recharge", "Others"
    );

    /** Default income categories */
    public static final Set<String> DEFAULT_INCOME_CATEGORIES = Set.of(
        "Salary", "Freelance", "Business", "Interest", "Gift", "Refund"
    );

    /**
     * Validate a transaction before saving.
     * @throws IllegalArgumentException if validation fails
     */
    public void validateTransaction(Transaction txn) {
        if (txn == null) {
            throw new IllegalArgumentException("Transaction cannot be null");
        }
        if (txn.getUserId() == null || txn.getUserId().trim().isEmpty()) {
            throw new IllegalArgumentException("User ID is required");
        }
        if (txn.getAmount() <= 0) {
            throw new IllegalArgumentException("Amount must be positive");
        }
        if (txn.getType() == null ||
            (!txn.getType().equals("income") && !txn.getType().equals("expense"))) {
            throw new IllegalArgumentException("Type must be 'income' or 'expense'");
        }
        if (txn.getCategory() == null || txn.getCategory().trim().isEmpty()) {
            throw new IllegalArgumentException("Category is required");
        }
        if (txn.getPaymentMethod() != null &&
            !PAYMENT_METHODS.contains(txn.getPaymentMethod())) {
            throw new IllegalArgumentException(
                "Invalid payment method. Must be one of: " + PAYMENT_METHODS);
        }
    }

    /**
     * Calculate total balance (income - expense) for a list of transactions.
     */
    public double calculateTotalBalance(List<Transaction> transactions) {
        return transactions.stream()
            .mapToDouble(t -> t.getType().equals("income") ? t.getAmount() : -t.getAmount())
            .sum();
    }

    /**
     * Calculate total income.
     */
    public double calculateTotalIncome(List<Transaction> transactions) {
        return transactions.stream()
            .filter(t -> t.getType().equals("income"))
            .mapToDouble(Transaction::getAmount)
            .sum();
    }

    /**
     * Calculate total expenses.
     */
    public double calculateTotalExpense(List<Transaction> transactions) {
        return transactions.stream()
            .filter(t -> t.getType().equals("expense"))
            .mapToDouble(Transaction::getAmount)
            .sum();
    }

    /**
     * Calculate savings (income - expense).
     */
    public double calculateSavings(List<Transaction> transactions) {
        return calculateTotalIncome(transactions) - calculateTotalExpense(transactions);
    }

    /**
     * Calculate spending for the current day.
     */
    public double calculateDailySpending(List<Transaction> transactions) {
        LocalDate today = LocalDate.now();
        return transactions.stream()
            .filter(t -> t.getType().equals("expense"))
            .filter(t -> t.getDate() != null && t.getDate().toLocalDate().equals(today))
            .mapToDouble(Transaction::getAmount)
            .sum();
    }

    /**
     * Calculate spending for the current week (Monday to Sunday).
     */
    public double calculateWeeklySpending(List<Transaction> transactions) {
        LocalDate today = LocalDate.now();
        LocalDate weekStart = today.minusDays(today.getDayOfWeek().getValue() - 1);
        LocalDate weekEnd = weekStart.plusDays(7);
        return transactions.stream()
            .filter(t -> t.getType().equals("expense"))
            .filter(t -> t.getDate() != null)
            .filter(t -> !t.getDate().toLocalDate().isBefore(weekStart))
            .filter(t -> t.getDate().toLocalDate().isBefore(weekEnd))
            .mapToDouble(Transaction::getAmount)
            .sum();
    }

    /**
     * Calculate spending for the current month.
     */
    public double calculateMonthlySpending(List<Transaction> transactions) {
        YearMonth currentMonth = YearMonth.now();
        return transactions.stream()
            .filter(t -> t.getType().equals("expense"))
            .filter(t -> t.getDate() != null)
            .filter(t -> YearMonth.from(t.getDate()).equals(currentMonth))
            .mapToDouble(Transaction::getAmount)
            .sum();
    }

    /**
     * Calculate remaining budget.
     */
    public double calculateRemainingBudget(double budget, double spent) {
        return budget - spent;
    }

    /**
     * Calculate budget usage percentage (0-100+, may exceed 100 when over budget).
     */
    public double calculateBudgetUsagePercent(double budget, double spent) {
        if (budget <= 0) return 0;
        return (spent / budget) * 100;
    }

    /**
     * Determine budget alert level based on usage percentage.
     * Returns one of: OK, WARNING_50, WARNING_75, WARNING_90, LIMIT, OVER
     */
    public String getBudgetAlertLevel(double usagePercent) {
        if (usagePercent >= 100) return "OVER";
        if (usagePercent >= 90) return "LIMIT";
        if (usagePercent >= 75) return "WARNING_90";
        if (usagePercent >= 50) return "WARNING_75";
        return "OK";
    }

    /**
     * Filter transactions by date range.
     */
    public List<Transaction> filterByDateRange(
            List<Transaction> transactions, LocalDateTime start, LocalDateTime end) {
        return transactions.stream()
            .filter(t -> t.getDate() != null)
            .filter(t -> !t.getDate().isBefore(start))
            .filter(t -> !t.getDate().isAfter(end))
            .collect(Collectors.toList());
    }

    /**
     * Search transactions by keyword (matches merchant, description, category, tags).
     */
    public List<Transaction> searchTransactions(
            List<Transaction> transactions, String keyword) {
        if (keyword == null || keyword.trim().isEmpty()) {
            return transactions;
        }
        String query = keyword.toLowerCase().trim();
        return transactions.stream()
            .filter(t ->
                (t.getMerchant() != null && t.getMerchant().toLowerCase().contains(query)) ||
                (t.getDescription() != null && t.getDescription().toLowerCase().contains(query)) ||
                (t.getCategory() != null && t.getCategory().toLowerCase().contains(query)) ||
                (t.getTags() != null && t.getTags().stream()
                    .anyMatch(tag -> tag.toLowerCase().contains(query)))
            )
            .collect(Collectors.toList());
    }

    /**
     * Sort transactions by a given field.
     * @param sortBy one of "date", "amount", "category", "merchant"
     * @param ascending sort order
     */
    public List<Transaction> sortTransactions(
            List<Transaction> transactions, String sortBy, boolean ascending) {
        Comparator<Transaction> comparator;
        switch (sortBy == null ? "date" : sortBy.toLowerCase()) {
            case "amount":
                comparator = Comparator.comparingDouble(Transaction::getAmount);
                break;
            case "category":
                comparator = Comparator.comparing(
                    Transaction::getCategory, Comparator.nullsLast(String::compareTo));
                break;
            case "merchant":
                comparator = Comparator.comparing(
                    Transaction::getMerchant, Comparator.nullsLast(String::compareTo));
                break;
            case "date":
            default:
                comparator = Comparator.comparing(
                    Transaction::getDate, Comparator.nullsLast(LocalDateTime::compareTo));
                break;
        }
        if (!ascending) {
            comparator = comparator.reversed();
        }
        return transactions.stream().sorted(comparator).collect(Collectors.toList());
    }

    /**
     * Paginate a list of transactions.
     * @param page 0-based page number
     * @param size page size
     */
    public List<Transaction> paginate(List<Transaction> transactions, int page, int size) {
        if (page < 0 || size <= 0) {
            throw new IllegalArgumentException("Invalid pagination parameters");
        }
        int fromIndex = page * size;
        if (fromIndex >= transactions.size()) {
            return Collections.emptyList();
        }
        int toIndex = Math.min(fromIndex + size, transactions.size());
        return new ArrayList<>(transactions.subList(fromIndex, toIndex));
    }

    /**
     * Calculate a financial health score (0-100).
     */
    public int calculateFinancialScore(List<Transaction> transactions) {
        double income = calculateTotalIncome(transactions);
        double expense = calculateTotalExpense(transactions);
        if (income <= 0) return 0;

        // Savings rate contributes 60 points (30% savings = full marks)
        double savingsRate = (income - expense) / income;
        double savingsScore = Math.min(60, Math.max(0, savingsRate * 200));

        // Budget discipline contributes 40 points
        double expenseToIncomeRatio = expense / income;
        double disciplineScore = Math.min(40, Math.max(0, (1 - expenseToIncomeRatio) * 60));

        return (int) Math.round(savingsScore + disciplineScore);
    }

    /**
     * Get spending breakdown by category.
     * Returns map of category -> total spent.
     */
    public Map<String, Double> getCategorySpendingBreakdown(List<Transaction> transactions) {
        return transactions.stream()
            .filter(t -> t.getType().equals("expense"))
            .collect(Collectors.groupingBy(
                Transaction::getCategory,
                Collectors.summingDouble(Transaction::getAmount)
            ));
    }

    /**
     * Detect potential anomalies — transactions significantly above average.
     * @param thresholdZScore z-score threshold (default 2.0)
     */
    public List<Transaction> detectAnomalies(
            List<Transaction> transactions, double thresholdZScore) {
        List<Transaction> expenses = transactions.stream()
            .filter(t -> t.getType().equals("expense"))
            .collect(Collectors.toList());

        if (expenses.size() < 5) return Collections.emptyList();

        double mean = expenses.stream()
            .mapToDouble(Transaction::getAmount).average().orElse(0);
        double variance = expenses.stream()
            .mapToDouble(t -> Math.pow(t.getAmount() - mean, 2))
            .average().orElse(0);
        double stdDev = Math.sqrt(variance);

        if (stdDev == 0) return Collections.emptyList();

        return expenses.stream()
            .filter(t -> Math.abs((t.getAmount() - mean) / stdDev) >= thresholdZScore)
            .collect(Collectors.toList());
    }

    /**
     * Suggest savings based on spending patterns.
     * Returns list of suggestion strings.
     */
    public List<String> generateSavingSuggestions(Map<String, Double> categorySpending) {
        List<String> suggestions = new ArrayList<>();
        double total = categorySpending.values().stream().mapToDouble(Double::doubleValue).sum();
        if (total <= 0) return suggestions;

        Set<String> discretionary = Set.of("Entertainment", "Shopping", "Recharge");
        for (Map.Entry<String, Double> entry : categorySpending.entrySet()) {
            String category = entry.getKey();
            double amount = entry.getValue();
            double percent = (amount / total) * 100;
            if (discretionary.contains(category) && percent > 20) {
                suggestions.add(String.format(
                    "%s spending is %.1f%% of total (₹%.2f). Try reducing by 10%% to save ₹%.2f per month.",
                    category, percent, amount, amount * 0.1));
            }
        }
        return suggestions;
    }
}