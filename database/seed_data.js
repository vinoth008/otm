// database/seed_data.js
/**
 * MongoDB Atlas Database Initialization and Seed Data
 * Run this script to set up the database with initial data
 */
// Switch to database
use smart_transaction_control;
// ============================================
// 1. Create System Categories
// ============================================
const systemCategories = [
    // Income Categories
    { name: 'Salary', type: 'income', icon: 'wallet', color: '#10b981', is_system: true },
    { name: 'Bonus', type: 'income', icon: 'gift', color: '#34d399', is_system: true },
    { name: 'Investment', type: 'income', icon: 'trending-up', color: '#059669', is_system: true },
    { name: 'Freelance', type: 'income', icon: 'briefcase', color: '#6ee7b7', is_system: true },
    { name: 'Rental', type: 'income', icon: 'home', color: '#a7f3d0', is_system: true },
    { name: 'Other Income', type: 'income', icon: 'plus-circle', color: '#d1fae5', is_system: true },
    // Expense Categories
    { name: 'Food', type: 'expense', icon: 'shopping-cart', color: '#f97316', is_system: true },
    { name: 'Travel', type: 'expense', icon: 'car', color: '#fb923c', is_system: true },
    { name: 'Shopping', type: 'expense', icon: 'bag', color: '#fdba74', is_system: true },
    { name: 'Medical', type: 'expense', icon: 'heart', color: '#ef4444', is_system: true },
    { name: 'Education', type: 'expense', icon: 'book', color: '#f87171', is_system: true },
    { name: 'Entertainment', type: 'expense', icon: 'film', color: '#fca5a5', is_system: true },
    { name: 'Bills & Utilities', type: 'expense', icon: 'zap', color: '#fbbf24', is_system: true },
    { name: 'Rent', type: 'expense', icon: 'home', color: '#f59e0b', is_system: true },
    { name: 'EMI', type: 'expense', icon: 'credit-card', color: '#d97706', is_system: true },
    { name: 'Insurance', type: 'expense', icon: 'shield', color: '#fcd34d', is_system: true },
    { name: 'Subscriptions', type: 'expense', icon: 'repeat', color: '#fde68a', is_system: true },
    { name: 'Fuel', type: 'expense', icon: 'droplet', color: '#f0abfc', is_system: true },
    { name: 'Tax', type: 'expense', icon: 'file-text', color: '#a78bfa', is_system: true },
    { name: 'Loan', type: 'expense', icon: 'hand', color: '#c4b5fd', is_system: true },
    { name: 'Other Expense', type: 'expense', icon: 'minus-circle', color: '#ddd6fe', is_system: true },
];
db.categories.insertMany(systemCategories);
// ============================================
// 2. Create Admin User
// ============================================
const bcrypt = require('bcryptjs'); // Use bcrypt in actual implementation
const adminUser = {
    email: 'admin@smarttransaction.com',
    password_hash: '$2b$10$YourHashedPasswordHere', // Replace with actual bcrypt hash in production
    first_name: 'System',
    last_name: 'Administrator',
    phone: '+91 0000000000',
    role: 'admin',
    status: 'active',
    is_verified: true,
    currency: 'INR',
    theme_preference: 'dark',
    created_at: new Date(),
    updated_at: new Date(),
    last_login: null
};
db.users.insertOne(adminUser);
// ============================================
// 3. Create Sample Users
// ============================================
const sampleUsers = [
    {
        email: 'user1@example.com',
        password_hash: '$2b$10$SampleHash1',
        first_name: 'John',
        last_name: 'Doe',
        phone: '+91 9876543210',
        role: 'customer',
        status: 'active',
        is_verified: true,
        currency: 'INR',
        theme_preference: 'light',
        created_at: new Date('2026-01-15'),
        updated_at: new Date(),
        last_login: new Date()
    },
    {
        email: 'user2@example.com',
        password_hash: '$2b$10$SampleHash2',
        first_name: 'Jane',
        last_name: 'Smith',
        phone: '+91 9876543211',
        role: 'customer',
        status: 'active',
        is_verified: true,
        currency: 'INR',
        theme_preference: 'dark',
        created_at: new Date('2026-02-20'),
        updated_at: new Date(),
        last_login: new Date()
    }
];
db.users.insertMany(sampleUsers);
// ============================================
// 4. Create Sample Transactions
// ============================================
const userId1 = db.users.findOne({ email: 'user1@example.com' })._id;
const sampleTransactions = [
    {
        user_id: userId1,
        type: 'income',
        category: 'Salary',
        amount: 50000,
        currency: 'INR',
        description: 'Monthly salary',
        date: new Date('2026-07-01'),
        payment_method: 'bank_transfer',
        created_at: new Date()
    },
    {
        user_id: userId1,
        type: 'expense',
        category: 'Food',
        amount: 5000,
        currency: 'INR',
        description: 'Groceries and dining',
        date: new Date('2026-07-05'),
        payment_method: 'upi',
        created_at: new Date()
    },
    {
        user_id: userId1,
        type: 'expense',
        category: 'Travel',
        amount: 2000,
        currency: 'INR',
        description: 'Fuel and cab',
        date: new Date('2026-07-10'),
        payment_method: 'card',
        created_at: new Date()
    },
    {
        user_id: userId1,
        type: 'expense',
        category: 'Bills & Utilities',
        amount: 3000,
        currency: 'INR',
        description: 'Electricity and internet',
        date: new Date('2026-07-15'),
        payment_method: 'bank_transfer',
        created_at: new Date()
    },
    {
        user_id: userId1,
        type: 'expense',
        category: 'Shopping',
        amount: 4000,
        currency: 'INR',
        description: 'Clothes and accessories',
        date: new Date('2026-07-20'),
        payment_method: 'card',
        created_at: new Date()
    },
    {
        user_id: userId1,
        type: 'income',
        category: 'Bonus',
        amount: 10000,
        currency: 'INR',
        description: 'Performance bonus',
        date: new Date('2026-07-25'),
        payment_method: 'bank_transfer',
        created_at: new Date()
    }
];
db.transactions.insertMany(sampleTransactions);
// ============================================
// 5. Create Sample Budgets
// ============================================
const sampleBudgets = [
    {
        user_id: userId1,
        category: 'Food',
        monthly_limit: 8000,
        current_spent: 5000,
        period_start: new Date('2026-07-01'),
        period_end: new Date('2026-07-31'),
        warning_threshold: 80,
        is_active: true,
        created_at: new Date(),
        updated_at: new Date()
    },
    {
        user_id: userId1,
        category: 'Travel',
        monthly_limit: 5000,
        current_spent: 2000,
        period_start: new Date('2026-07-01'),
        period_end: new Date('2026-07-31'),
        warning_threshold: 80,
        is_active: true,
        created_at: new Date(),
        updated_at: new Date()
    },
    {
        user_id: userId1,
        category: 'Shopping',
        monthly_limit: 6000,
        current_spent: 4000,
        period_start: new Date('2026-07-01'),
        period_end: new Date('2026-07-31'),
        warning_threshold: 80,
        is_active: true,
        created_at: new Date(),
        updated_at: new Date()
    }
];
db.budgets.insertMany(sampleBudgets);

// ============================================
// 6. Create Sample Goals
// ============================================
const sampleGoals = [
    {
        user_id: userId1,
        name: 'Emergency Fund',
        target_amount: 100000,
        current_amount: 45000,
        deadline: new Date('2026-12-31'),
        priority: 'high',
        status: 'active',
        notes: 'Build 6 months expense buffer',
        created_at: new Date(),
        updated_at: new Date()
    },
    {
        user_id: userId1,
        name: 'Vacation',
        target_amount: 50000,
        current_amount: 15000,
        deadline: new Date('2027-03-31'),
        priority: 'medium',
        status: 'active',
        notes: 'Trip to Goa',
        created_at: new Date(),
        updated_at: new Date()
    }
];
db.goals.insertMany(sampleGoals);
// ============================================
// 7. Create System Settings
// ============================================
const systemSettings = [
    {
        setting_key: 'app_name',
        setting_value: 'Smart Transaction Control',
        description: 'Application display name',
        updated_at: new Date()
    },
    {
        setting_key: 'default_currency',
        setting_value: 'INR',
        description: 'Default currency for new users',
        updated_at: new Date()
    },
    {
        setting_key: 'max_upload_size',
        setting_value: 5242880,
        description: 'Maximum file upload size in bytes',
        updated_at: new Date()
    },
    {
        setting_key: 'session_timeout',
        setting_value: 3600,
        description: 'Session timeout in seconds',
        updated_at: new Date()
    }
];
db.system_settings.insertMany(systemSettings);
// ============================================
// 8. Create Indexes (already in schema, but documenting here)
// ============================================
// Run these in MongoDB shell or through MongoDB Compass
print('Database initialization complete!');
print('Collections created: users, transactions, budgets, goals, wishlist, notes, categories, analytics_cache, notifications, achievements, activity_logs, audit_logs, system_settings, feedback, sessions');
print('Sample data inserted successfully.');

