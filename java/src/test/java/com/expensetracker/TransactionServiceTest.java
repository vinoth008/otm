package com.expensetracker;

import com.expensetracker.model.Transaction;
import com.expensetracker.service.TransactionService;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.time.LocalDateTime;
import java.util.ArrayList;
import java.util.List;

import static org.junit.jupiter.api.Assertions.*;

/**
 * Unit tests for TransactionService.
 */
public class TransactionServiceTest {

    private TransactionService service;
    private List<Transaction> transactions;

    @BeforeEach
    void setUp() {
        service = new TransactionService();
        transactions = new ArrayList<>();

        transactions.add(createTransaction("t1", "income", 50000, "Salary",
            "bank", "Acme Corp", LocalDateTime.now().minusDays(5)));
        transactions.add(createTransaction("t2", "expense", 1200, "Food",
            "upi", "Swiggy", LocalDateTime.now().minusDays(2)));
        transactions.add(createTransaction("t3", "expense", 3500, "Grocery",
            "debit_card", "BigBasket", LocalDateTime.now().minusDays(1)));
        transactions.add(createTransaction("t4", "expense", 800, "Travel",
            "upi", "Uber", LocalDateTime.now()));
    }

    @Test
    void testCalculateTotalBalance() {
        double balance = service.calculateTotalBalance(transactions);
        assertEquals(50000 - 1200 - 3500 - 800, balance, 0.001);
    }

    @Test
    void testCalculateTotalIncome() {
        assertEquals(50000, service.calculateTotalIncome(transactions), 0.001);
    }

    @Test
    void testCalculateTotalExpense() {
        assertEquals(1200 + 3500 + 800, service.calculateTotalExpense(transactions), 0.001);
    }

    @Test
    void testCalculateSavings() {
        assertEquals(50000 - 5500, service.calculateSavings(transactions), 0.001);
    }

    @Test
    void testValidateTransaction() {
        Transaction invalid = createTransaction("t5", "expense", -100, "Food",
            "upi", "Test", LocalDateTime.now());
        assertThrows(IllegalArgumentException.class, () -> service.validateTransaction(invalid));
    }

    @Test
    void testSearchTransactions() {
        List<Transaction> results = service.searchTransactions(transactions, "food");
        assertEquals(1, results.size());
        assertEquals("t2", results.get(0).getId());
    }

    @Test
    void testSortByAmountDescending() {
        List<Transaction> sorted = service.sortTransactions(transactions, "amount", false);
        assertEquals(50000, sorted.get(0).getAmount(), 0.001);
        assertEquals(800, sorted.get(3).getAmount(), 0.001);
    }

    @Test
    void testPaginate() {
        List<Transaction> page = service.paginate(transactions, 0, 2);
        assertEquals(2, page.size());
        List<Transaction> page2 = service.paginate(transactions, 1, 2);
        assertEquals(2, page2.size());
    }

    @Test
    void testBudgetUsagePercent() {
        assertEquals(50.0, service.calculateBudgetUsagePercent(1000, 500), 0.001);
        assertEquals(150.0, service.calculateBudgetUsagePercent(1000, 1500), 0.001);
    }

    @Test
    void testBudgetAlertLevel() {
        assertEquals("OK", service.getBudgetAlertLevel(40));
        assertEquals("WARNING_75", service.getBudgetAlertLevel(60));
        assertEquals("WARNING_90", service.getBudgetAlertLevel(80));
        assertEquals("LIMIT", service.getBudgetAlertLevel(95));
        assertEquals("OVER", service.getBudgetAlertLevel(105));
    }

    @Test
    void testFinancialScore() {
        int score = service.calculateFinancialScore(transactions);
        assertTrue(score >= 0 && score <= 100);
    }

    @Test
    void testCategoryBreakdown() {
        var breakdown = service.getCategorySpendingBreakdown(transactions);
        assertEquals(3, breakdown.size());
        assertEquals(1200, breakdown.get("Food"), 0.001);
    }

    private Transaction createTransaction(String id, String type, double amount,
            String category, String paymentMethod, String merchant, LocalDateTime date) {
        Transaction txn = new Transaction();
        txn.setId(id);
        txn.setUserId("user1");
        txn.setType(type);
        txn.setAmount(amount);
        txn.setCategory(category);
        txn.setWalletId("wallet1");
        txn.setPaymentMethod(paymentMethod);
        txn.setMerchant(merchant);
        txn.setDescription("Test transaction");
        txn.setDate(date);
        txn.setCreatedAt(LocalDateTime.now());
        return txn;
    }
}