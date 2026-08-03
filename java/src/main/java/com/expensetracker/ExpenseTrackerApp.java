package com.expensetracker;

import com.expensetracker.model.Transaction;
import com.expensetracker.service.*;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.time.LocalDateTime;
import java.util.ArrayList;
import java.util.List;

/**
 * ExpenseTrackerApp — Main entry point for the Java business logic module.
 * Demonstrates the core services: transactions, wallets, budgets, goals, reminders.
 */
public class ExpenseTrackerApp {

    private static final Logger logger = LoggerFactory.getLogger(ExpenseTrackerApp.class);

    public static void main(String[] args) {
        logger.info("Smart Transaction Control with Expense Tracker - Java Business Logic");

        // Initialize services
        TransactionService transactionService = new TransactionService();
        WalletService walletService = new WalletService();
        BudgetService budgetService = new BudgetService();
        GoalService goalService = new GoalService();
        ReminderService reminderService = new ReminderService();
        ReportService reportService = new ReportService();

        // Create sample transactions
        List<Transaction> transactions = new ArrayList<>();
        transactions.add(createTransaction("txn1", "user1", "income", 50000,
            "Salary", "wallet1", "bank", "Acme Corp", "Monthly salary", LocalDateTime.now().minusDays(5)));
        transactions.add(createTransaction("txn2", "user1", "expense", 1200,
            "Food", "wallet1", "upi", "Swiggy", "Lunch", LocalDateTime.now().minusDays(2)));
        transactions.add(createTransaction("txn3", "user1", "expense", 3500,
            "Grocery", "wallet1", "debit_card", "BigBasket", "Weekly groceries", LocalDateTime.now().minusDays(1)));
        transactions.add(createTransaction("txn4", "user1", "expense", 800,
            "Travel", "wallet1", "upi", "Uber", "Airport ride", LocalDateTime.now()));

        // Demonstrate transaction service
        logger.info("Total balance: ₹{}", transactionService.calculateTotalBalance(transactions));
        logger.info("Total income: ₹{}", transactionService.calculateTotalIncome(transactions));
        logger.info("Total expense: ₹{}", transactionService.calculateTotalExpense(transactions));
        logger.info("Savings: ₹{}", transactionService.calculateSavings(transactions));
        logger.info("Daily spending: ₹{}", transactionService.calculateDailySpending(transactions));
        logger.info("Weekly spending: ₹{}", transactionService.calculateWeeklySpending(transactions));
        logger.info("Monthly spending: ₹{}", transactionService.calculateMonthlySpending(transactions));
        logger.info("Financial score: {}", transactionService.calculateFinancialScore(transactions));

        // Search and sort
        List<Transaction> searchResults = transactionService.searchTransactions(transactions, "food");
        logger.info("Search 'food' found {} transactions", searchResults.size());

        List<Transaction> sorted = transactionService.sortTransactions(transactions, "amount", false);
        logger.info("Sorted by amount (desc): first = ₹{}", sorted.get(0).getAmount());

        // Wallet operations
        WalletService.Wallet wallet1 = new WalletService.Wallet("wallet1", "user1", "Main Wallet", 25000);
        WalletService.Wallet wallet2 = new WalletService.Wallet("wallet2", "user1", "Savings", 100000);
        walletService.transferBetweenWallets(wallet1, wallet2, 5000);
        logger.info("After transfer: wallet1 = ₹{}, wallet2 = ₹{}",
            wallet1.getBalance(), wallet2.getBalance());

        // Budget operations
        BudgetService.Budget monthlyBudget = new BudgetService.Budget(
            "budget1", "user1", "monthly", null, 30000, "2026-08");
        double spent = transactionService.calculateMonthlySpending(transactions);
        double usage = budgetService.calculateUsagePercent(monthlyBudget.getAmount(), spent);
        logger.info("Monthly budget usage: {}%", String.format("%.1f", usage));
        logger.info("Budget alert: {}", budgetService.getAlertLevel(usage));

        // Goal operations
        GoalService.Goal goal = new GoalService.Goal(
            "goal1", "user1", "Emergency Fund", 100000, 25000,
            java.time.LocalDate.now().plusMonths(12));
        logger.info("Goal progress: {}%", String.format("%.1f", goalService.calculateProgress(goal)));
        logger.info("Goal remaining: ₹{}", goalService.calculateRemainingAmount(goal));
        logger.info("Required monthly savings: ₹{}",
            String.format("%.2f", goalService.calculateRequiredMonthlySavings(goal)));

        // Reminder
        ReminderService.Reminder reminder = new ReminderService.Reminder(
            "rem1", "user1", "Electricity Bill", "electricity", 1500, 15, true, "monthly");
        logger.info("Electricity bill due in {} days", reminderService.daysUntilDue(reminder));

        // Anomaly detection
        List<Transaction> anomalies = transactionService.detectAnomalies(transactions, 2.0);
        logger.info("Detected {} anomalies", anomalies.size());

        // Saving suggestions
        var categorySpending = transactionService.getCategorySpendingBreakdown(transactions);
        List<String> suggestions = transactionService.generateSavingSuggestions(categorySpending);
        suggestions.forEach(s -> logger.info("Suggestion: {}", s));

        logger.info("Java business logic module initialized successfully.");
    }

    private static Transaction createTransaction(String id, String userId, String type,
            double amount, String category, String walletId, String paymentMethod,
            String merchant, String description, LocalDateTime date) {
        Transaction txn = new Transaction();
        txn.setId(id);
        txn.setUserId(userId);
        txn.setType(type);
        txn.setAmount(amount);
        txn.setCategory(category);
        txn.setWalletId(walletId);
        txn.setPaymentMethod(paymentMethod);
        txn.setMerchant(merchant);
        txn.setDescription(description);
        txn.setDate(date);
        txn.setCreatedAt(LocalDateTime.now());
        return txn;
    }
}