// database/seed_data.js
/**
 * MongoDB Atlas Database Initialization and Seed Data
 * Run this script to set up the database with initial data
 */
// Switch to database
use smart_transaction_control;
// ============================================
// 0. Delete old demo users (non-canonical only)
// ============================================
const oldEmails = [
    'admin@sot.com', 'manager@sot.com', 'staff@sot.com', 'recept@sot.com',
    'cust@sot.com', 'employee@sot.com', 'auditor@sot.com', 'customer2@sot.com',
    'admin@smarttransaction.com', 'manager@smarttransaction.com',
    'employee@smarttransaction.com', 'auditor@smarttransaction.com',
    'test@smarttransaction.com', 'admin@expensetracker.com',
    'user@expensetracker.com', 'user1@example.com', 'user2@example.com'
];

// The CANONICAL demo users (kept by seed_database.php) are:
//   admin1@gmail.com     (admin)
//   staff1@gmail.com     (staff)
//   recept1@gmail.com    (receptionist)
//   customer1@gmail.com  (customer)
//   -> password for all four: Password@123
// Note: seed_database.php (PHP) is the authoritative seeder for these.
// This .js mimics its behavior for reference.
print('Cleaning up old demo users...');
oldEmails.forEach(email => {
    const oldUser = db.users.findOne({ email });
    if (oldUser) {
        const oldId = oldUser._id;
        ['transactions', 'wallets', 'budgets', 'goals', 'notifications', 'notes', 'beneficiaries', 'complaints', 'receipts', 'appointments', 'sessions', 'activity_logs'].forEach(coll => {
            if (db.getCollectionNames().includes(coll)) {
                try { db.getCollection(coll).deleteMany({ user_id: oldId }); } catch (e) {}
            }
        });
        db.users.deleteOne({ _id: oldId });
        print('  [DELETED] ' + email + ' and related data');
    }
});

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
if (db.categories.countDocuments({ is_system: true }) === 0) {
    db.categories.insertMany(systemCategories);
    print('Inserted ' + systemCategories.length + ' system categories');
} else {
    print('System categories already exist, skipping');
}

// ============================================
// 2. Create System Settings
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
    },
    {
        setting_key: 'company_name',
        setting_value: 'SecureSOT Ltd',
        description: 'Company / bank display name',
        updated_at: new Date()
    },
    {
        setting_key: 'support_email',
        setting_value: 'support@securesot.com',
        description: 'Support contact email',
        updated_at: new Date()
    }
];
db.system_settings.insertMany(systemSettings);

// ============================================
// 3. Create Indexes (already in schema, but documenting here)
// ============================================
// Run these in MongoDB shell or through MongoDB Compass
print('Database initialization complete!');
print('Collections created: users, transactions, budgets, goals, wishlist, notes, categories, analytics_cache, notifications, achievements, activity_logs, audit_logs, system_settings, feedback, sessions');
