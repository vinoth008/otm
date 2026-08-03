package com.expensetracker.service;

import java.util.ArrayList;
import java.util.List;

/**
 * WalletService — Business logic for wallet management,
 * transfers between wallets, and balance tracking.
 */
public class WalletService {

    /** Wallet model as a simple inner class for portability */
    public static class Wallet {
        private String id;
        private String userId;
        private String name;
        private double balance;
        private String currency = "INR";
        private boolean isDefault;
        private String createdAt;

        public Wallet() {}

        public Wallet(String id, String userId, String name, double balance) {
            this.id = id;
            this.userId = userId;
            this.name = name;
            this.balance = balance;
        }

        public String getId() { return id; }
        public void setId(String id) { this.id = id; }

        public String getUserId() { return userId; }
        public void setUserId(String userId) { this.userId = userId; }

        public String getName() { return name; }
        public void setName(String name) { this.name = name; }

        public double getBalance() { return balance; }
        public void setBalance(double balance) { this.balance = balance; }

        public String getCurrency() { return currency; }
        public void setCurrency(String currency) { this.currency = currency; }

        public boolean isDefault() { return isDefault; }
        public void setDefault(boolean isDefault) { this.isDefault = isDefault; }

        public String getCreatedAt() { return createdAt; }
        public void setCreatedAt(String createdAt) { this.createdAt = createdAt; }
    }

    /**
     * Validate wallet data.
     * @throws IllegalArgumentException if validation fails
     */
    public void validateWallet(Wallet wallet) {
        if (wallet == null) {
            throw new IllegalArgumentException("Wallet cannot be null");
        }
        if (wallet.getUserId() == null || wallet.getUserId().trim().isEmpty()) {
            throw new IllegalArgumentException("User ID is required");
        }
        if (wallet.getName() == null || wallet.getName().trim().isEmpty()) {
            throw new IllegalArgumentException("Wallet name is required");
        }
        if (wallet.getBalance() < 0) {
            throw new IllegalArgumentException("Wallet balance cannot be negative");
        }
    }

    /**
     * Transfer money between two wallets.
     * @param fromWallet source wallet
     * @param toWallet destination wallet
     * @param amount amount to transfer
     * @throws IllegalArgumentException if transfer is invalid
     */
    public void transferBetweenWallets(Wallet fromWallet, Wallet toWallet, double amount) {
        if (fromWallet == null || toWallet == null) {
            throw new IllegalArgumentException("Both wallets are required");
        }
        if (fromWallet.getId().equals(toWallet.getId())) {
            throw new IllegalArgumentException("Cannot transfer to the same wallet");
        }
        if (amount <= 0) {
            throw new IllegalArgumentException("Transfer amount must be positive");
        }
        if (fromWallet.getBalance() < amount) {
            throw new IllegalArgumentException("Insufficient balance in source wallet");
        }

        fromWallet.setBalance(fromWallet.getBalance() - amount);
        toWallet.setBalance(toWallet.getBalance() + amount);
    }

    /**
     * Add money to a wallet.
     */
    public void credit(Wallet wallet, double amount) {
        if (amount <= 0) {
            throw new IllegalArgumentException("Credit amount must be positive");
        }
        wallet.setBalance(wallet.getBalance() + amount);
    }

    /**
     * Deduct money from a wallet.
     * @throws IllegalArgumentException if insufficient balance
     */
    public void debit(Wallet wallet, double amount) {
        if (amount <= 0) {
            throw new IllegalArgumentException("Debit amount must be positive");
        }
        if (wallet.getBalance() < amount) {
            throw new IllegalArgumentException("Insufficient balance");
        }
        wallet.setBalance(wallet.getBalance() - amount);
    }

    /**
     * Calculate total balance across all wallets.
     */
    public double getTotalBalance(List<Wallet> wallets) {
        return wallets.stream()
            .mapToDouble(Wallet::getBalance)
            .sum();
    }

    /**
     * Find the default wallet, or the first one if none is marked default.
     */
    public Wallet getDefaultWallet(List<Wallet> wallets) {
        return wallets.stream()
            .filter(Wallet::isDefault)
            .findFirst()
            .orElse(wallets.isEmpty() ? null : wallets.get(0));
    }
}