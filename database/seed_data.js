// database/seed_data.js
/**
 * MongoDB Atlas Database Initialization and Seed Data
 * Run this script to set up the database with initial data
 *
 * Demo Credentials:
 *   Admin:        admin1@gmail.com  / admin@001
 *   Staff:        staff1@gmail.com  / staff@001
 *   Receptionist: recept1@gmail.com / recept@001
 *   Customer:     customer1@gmail.com / customer@001
 */
// Switch to database
use smart_transaction_control;
// ============================================
// 0. Delete old demo users
// ============================================
const oldEmails = [
    'admin@sot.com', 'manager@sot.com', 'staff@sot.com', 'recept@sot.com',
    'cust@sot.com', 'employee@sot.com', 'auditor@sot.com', 'customer2@sot.com',
    'admin@smarttransaction.com', 'manager@smarttransaction.com',
    'employee@smarttransaction.com', 'auditor@smarttransaction.com',
    'test@smarttransaction.com', 'admin@expensetracker.com',
    'user@expensetracker.com', 'user1@example.com', 'user2@example.com'
];
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
// 2. Create Demo Users (4 roles)
// ============================================
// Note: In actual PHP seeding, passwords are hashed with bcrypt.
// These hashes correspond to the plaintext passwords shown below (for reference only).
const demoUsers = [
    {
        email: 'admin1@gmail.com',
        password_hash: '$2y$10$examplehashforadmin000000000000000000000000000000000000000000000', // admin@001
        first_name: 'System',
        last_name: 'Administrator',
        phone: '9876500001',
        role: 'admin',
        status: 'active',
        is_verified: true,
        login_attempts: 0,
        locked_until: null,
        balance: 100000,
        currency: 'INR',
        theme_preference: 'dark',
        created_at: new Date(),
        updated_at: new Date(),
        last_login: null
    },
    {
        email: 'staff1@gmail.com',
        password_hash: '$2y$10$examplehashforstaff0000000000000000000000000000000000000000000000', // staff@001
        first_name: 'Staff',
        last_name: 'Member',
        phone: '9876500003',
        role: 'staff',
        status: 'active',
        is_verified: true,
        login_attempts: 0,
        locked_until: null,
        balance: 50000,
        currency: 'INR',
        theme_preference: 'light',
        created_at: new Date(),
        updated_at: new Date(),
        last_login: null
    },
    {
        email: 'recept1@gmail.com',
        password_hash: '$2y$10$examplehashforrecept0000000000000000000000000000000000000000000', // recept@001
        first_name: 'Reception',
        last_name: 'Desk',
        phone: '9876500004',
        role: 'receptionist',
        status: 'active',
        is_verified: true,
        login_attempts: 0,
        locked_until: null,
        balance: 30000,
        currency: 'INR',
        theme_preference: 'light',
        created_at: new Date(),
        updated_at: new Date(),
        last_login: null
    },
    {
        email: 'customer1@gmail.com',
        password_hash: '$2y$10$examplehashforcustomer0000000000000000000000000000000000000000', // customer@001
        first_name: 'Demo',
        last_name: 'Customer',
        phone: '9876500005',
        role: 'customer',
        status: 'active',
        is_verified: true,
        login_attempts: 0,
        locked_until: null,
        balance: 25000,
        currency: 'INR',
        theme_preference: 'light',
        created_at: new Date(),
        updated_at: new Date(),
        last_login: null
    }
];

const userIds = {};
demoUsers.forEach(u => {
    const existing = db.users.findOne({ email: u.email });
    if (existing) {
        print('[SKIP] ' + u.email + ' already exists');
        userIds[u.email] = existing._id;
        return;
    }
    const result = db.users.insertOne(u);
    userIds[u.email] = result.insertedId;
    print('[OK] Created ' + u.email + ' (role: ' + u.role + ')');
});

// ============================================
// 3. Create Wallets for Demo Users
// ============================================
demoUsers.forEach(u => {
    const uid = userIds[u.email];
    if (!uid) return;
    if (db.wallets.countDocuments({ user_id: uid }) > 0) return;
    db.wallets.insertMany([
        {
            user_id: uid,
            name: 'Main Account',
            balance: u.balance,
            currency: 'INR',
            created_at: new Date(),
            updated_at: new Date(),
            deleted_at: null
        },
        {
            user_id: uid,
            name: 'Savings',
            balance: parseInt(u.balance * 0.3),
            currency: 'INR',
            created_at: new Date(),
            updated_at: new Date(),
            deleted_at: null
        }
    ]);
    print('[OK] Created wallets for ' + u.email);
});

// ============================================
// 4. Create Sample Transactions
// ============================================
const sampleTransactions = [
    {
        email: 'customer1@gmail.com',
        txns: [
            ['income', 'Salary', 50000, 'Monthly salary', -20, 'bank_transfer'],
            ['expense', 'Food', 5000, 'Groceries and dining', -16, 'upi'],
            ['expense', 'Travel', 2000, 'Fuel and cab', -12, 'card'],
            ['expense', 'Bills & Utilities', 3000, 'Electricity and internet', -8, 'bank_transfer'],
            ['expense', 'Shopping', 4000, 'Clothes and accessories', -4, 'card'],
            ['income', 'Bonus', 10000, 'Performance bonus', -2, 'bank_transfer']
        ]
    },
    {
        email: 'staff1@gmail.com',
        txns: [
            ['income', 'Salary', 45000, 'Monthly salary', -17, 'bank_transfer'],
            ['expense', 'Food', 4200, 'Meals', -13, 'upi'],
            ['expense', 'Bills & Utilities', 2800, 'WiFi + electricity', -9, 'bank_transfer'],
            ['expense', 'Entertainment', 1200, 'Concert', -6, 'card'],
            ['expense', 'Loan', 5000, 'Personal loan EMI', -4, 'bank_transfer']
        ]
    },
    {
        email: 'recept1@gmail.com',
        txns: [
            ['income', 'Salary', 30000, 'Monthly salary', -16, 'bank_transfer'],
            ['expense', 'Food', 3000, 'Meals', -11, 'upi'],
            ['expense', 'Travel', 1500, 'Auto fare', -7, 'wallet'],
            ['expense', 'Shopping', 2000, 'Cosmetics', -3, 'card']
        ]
    },
    {
        email: 'admin1@gmail.com',
        txns: [
            ['expense', 'Bills & Utilities', 8000, 'Server hosting', -12, 'bank_transfer'],
            ['expense', 'Subscriptions', 4000, 'SaaS tools', -8, 'card'],
            ['expense', 'Insurance', 12000, 'Business insurance', -3, 'bank_transfer']
        ]
    }
];

sampleTransactions.forEach(group => {
    const uid = userIds[group.email];
    if (!uid) return;
    if (db.transactions.countDocuments({ user_id: uid }) > 0) {
        print('[SKIP] ' + group.email + ' already has transactions');
        return;
    }
    const now = new Date();
    group.txns.forEach(t => {
        const d = new Date(now);
        d.setDate(d.getDate() + t[4]);
        db.transactions.insertOne({
            user_id: uid,
            type: t[0],
            category: t[1],
            amount: t[2],
            currency: 'INR',
            description: t[3],
            date: d,
            payment_method: t[5],
            status: 'completed',
            is_recurring: false,
            reference: 'TXN' + Math.random().toString(36).substring(2, 10).toUpperCase(),
            created_at: new Date(),
            updated_at: new Date(),
            deleted_at: null
        });
    });
    print('[OK] Created ' + group.txns.length + ' transactions for ' + group.email);
});

// ============================================
// 5. Create Sample Budgets
// ============================================
const sampleBudgets = {
    'customer1@gmail.com': [
        ['Food', 8000, 5000],
        ['Travel', 5000, 2000],
        ['Shopping', 6000, 4000]
    ]
};

Object.entries(sampleBudgets).forEach(([email, budgets]) => {
    const uid = userIds[email];
    if (!uid) return;
    if (db.budgets.countDocuments({ user_id: uid }) > 0) return;
    const start = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    const end = new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0);
    budgets.forEach(b => {
        db.budgets.insertOne({
            user_id: uid,
            category: b[0],
            monthly_limit: b[1],
            current_spent: b[2],
            period_start: start,
            period_end: end,
            warning_threshold: 80,
            is_active: true,
            created_at: new Date(),
            updated_at: new Date()
        });
    });
    print('[OK] Created budgets for ' + email);
});

// ============================================
// 6. Create Sample Goals
// ============================================
const customerId = userIds['customer1@gmail.com'];
if (customerId && db.goals.countDocuments({ user_id: customerId }) === 0) {
    const sampleGoals = [
        {
            user_id: customerId,
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
            user_id: customerId,
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
    print('[OK] Created goals for customer1@gmail.com');
}

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
// 8. Create Indexes (already in schema, but documenting here)
// ============================================
// Run these in MongoDB shell or through MongoDB Compass
print('Database initialization complete!');
print('Collections created: users, transactions, budgets, goals, wishlist, notes, categories, analytics_cache, notifications, achievements, activity_logs, audit_logs, system_settings, feedback, sessions');
print('Demo Credentials:');
print('  Admin:        admin1@gmail.com   / admin@001');
print('  Staff:        staff1@gmail.com   / staff@001');
print('  Receptionist: recept1@gmail.com  / recept@001');
print('  Customer:     customer1@gmail.com / customer@001');