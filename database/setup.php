<?php
// database/setup.php
/**
 * Smart Transaction Control - Database Setup & Seed Script
 * Run from CLI: php database/setup.php
 * Creates collections, indexes, system categories, a test user and sample data.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/php/security.php';

echo "Smart Transaction Control - Database Setup\n";
echo "==========================================\n";

$db = getMongoDB();
if (!$db) {
    fwrite(STDERR, "FATAL: Could not connect to MongoDB Atlas.\n");
    exit(1);
}
echo "Connected to MongoDB Atlas (DB: " . DB_NAME . ")\n\n";

// 1. Create collections (implicit on first insert, but explicit here)
$collections = [
    'users', 'transactions', 'budgets', 'goals', 'wishlist', 'notes',
    'categories', 'analytics_cache', 'notifications', 'achievements',
    'activity_logs', 'audit_logs', 'system_settings', 'feedback', 'sessions',
    'roles', 'branches', 'wallets', 'transfers', 'beneficiaries',
    'complaints', 'receipts', 'appointments', 'expenses'
];
foreach ($collections as $name) {
    try {
        $db->createCollection($name);
        echo "  [OK] Collection created: $name\n";
    } catch (Exception $e) {
        echo "  [SKIP] Collection exists: $name\n";
    }
}
echo "\n";

// 2. Create indexes
echo "Creating indexes...\n";
$db->users->createIndex(['email' => 1], ['unique' => true]);
$db->users->createIndex(['role' => 1, 'status' => 1]);
$db->transactions->createIndex(['user_id' => 1, 'date' => -1]);
$db->transactions->createIndex(['user_id' => 1, 'type' => 1]);
$db->budgets->createIndex(['user_id' => 1, 'category' => 1]);
$db->goals->createIndex(['user_id' => 1, 'status' => 1]);
$db->categories->createIndex(['user_id' => 1, 'type' => 1]);
$db->categories->createIndex(['is_system' => 1]);
$db->activity_logs->createIndex(['user_id' => 1, 'timestamp' => -1]);
$db->notifications->createIndex(['user_id' => 1, 'is_read' => 1, 'created_at' => -1]);
$db->system_settings->createIndex(['setting_key' => 1], ['unique' => true]);
$db->sessions->createIndex(['session_id' => 1], ['unique' => true]);
$db->sessions->createIndex(['expires_at' => 1], ['expireAfterSeconds' => 0]);
$db->roles->createIndex(['role_code' => 1], ['unique' => true]);
$db->branches->createIndex(['branch_code' => 1], ['unique' => true]);
$db->wallets->createIndex(['user_id' => 1], ['unique' => true]);
$db->transfers->createIndex(['from_user_id' => 1, 'created_at' => -1]);
$db->transfers->createIndex(['status' => 1, 'created_at' => -1]);
$db->beneficiaries->createIndex(['user_id' => 1, 'created_at' => -1]);
$db->complaints->createIndex(['user_id' => 1, 'created_at' => -1]);
$db->complaints->createIndex(['status' => 1, 'created_at' => -1]);
$db->receipts->createIndex(['user_id' => 1, 'created_at' => -1]);
$db->appointments->createIndex(['branch_id' => 1, 'date' => 1]);
$db->appointments->createIndex(['status' => 1, 'created_at' => -1]);
$db->expenses->createIndex(['user_id' => 1, 'date' => -1]);
echo "  [OK] All indexes created\n\n";

// 3. System categories
echo "Seeding system categories...\n";
$systemCategories = [
    ['name' => 'Salary', 'type' => 'income', 'icon' => 'wallet', 'color' => '#10b981', 'is_system' => true],
    ['name' => 'Bonus', 'type' => 'income', 'icon' => 'gift', 'color' => '#34d399', 'is_system' => true],
    ['name' => 'Investment', 'type' => 'income', 'icon' => 'trending-up', 'color' => '#059669', 'is_system' => true],
    ['name' => 'Freelance', 'type' => 'income', 'icon' => 'briefcase', 'color' => '#6ee7b7', 'is_system' => true],
    ['name' => 'Rental', 'type' => 'income', 'icon' => 'home', 'color' => '#a7f3d0', 'is_system' => true],
    ['name' => 'Other Income', 'type' => 'income', 'icon' => 'plus-circle', 'color' => '#d1fae5', 'is_system' => true],
    ['name' => 'Food', 'type' => 'expense', 'icon' => 'shopping-cart', 'color' => '#f97316', 'is_system' => true],
    ['name' => 'Travel', 'type' => 'expense', 'icon' => 'car', 'color' => '#fb923c', 'is_system' => true],
    ['name' => 'Shopping', 'type' => 'expense', 'icon' => 'bag', 'color' => '#fdba74', 'is_system' => true],
    ['name' => 'Medical', 'type' => 'expense', 'icon' => 'heart', 'color' => '#ef4444', 'is_system' => true],
    ['name' => 'Education', 'type' => 'expense', 'icon' => 'book', 'color' => '#f87171', 'is_system' => true],
    ['name' => 'Entertainment', 'type' => 'expense', 'icon' => 'film', 'color' => '#fca5a5', 'is_system' => true],
    ['name' => 'Bills & Utilities', 'type' => 'expense', 'icon' => 'zap', 'color' => '#fbbf24', 'is_system' => true],
    ['name' => 'Rent', 'type' => 'expense', 'icon' => 'home', 'color' => '#f59e0b', 'is_system' => true],
    ['name' => 'EMI', 'type' => 'expense', 'icon' => 'credit-card', 'color' => '#d97706', 'is_system' => true],
    ['name' => 'Insurance', 'type' => 'expense', 'icon' => 'shield', 'color' => '#fcd34d', 'is_system' => true],
    ['name' => 'Subscriptions', 'type' => 'expense', 'icon' => 'repeat', 'color' => '#fde68a', 'is_system' => true],
    ['name' => 'Fuel', 'type' => 'expense', 'icon' => 'droplet', 'color' => '#f0abfc', 'is_system' => true],
    ['name' => 'Tax', 'type' => 'expense', 'icon' => 'file-text', 'color' => '#a78bfa', 'is_system' => true],
    ['name' => 'Loan', 'type' => 'expense', 'icon' => 'hand', 'color' => '#c4b5fd', 'is_system' => true],
    ['name' => 'Other Expense', 'type' => 'expense', 'icon' => 'minus-circle', 'color' => '#ddd6fe', 'is_system' => true],
];
$existingCategory = $db->categories->countDocuments(['is_system' => true]);
if ($existingCategory == 0) {
    foreach ($systemCategories as $cat) {
        $cat['created_at'] = phpDateToMongo();
        $db->categories->insertOne($cat);
    }
    echo "  [OK] Inserted " . count($systemCategories) . " system categories\n";
} else {
    echo "  [SKIP] System categories already present ($existingCategory)\n";
}
echo "\n";

// 4. Delete old demo users & related data
echo "Cleaning up old demo users...\n";
$oldEmails = [
    'admin@sot.com', 'manager@sot.com', 'staff@sot.com', 'recept@sot.com',
    'cust@sot.com', 'employee@sot.com', 'auditor@sot.com', 'customer2@sot.com',
    'admin@smarttransaction.com', 'manager@smarttransaction.com',
    'employee@smarttransaction.com', 'auditor@smarttransaction.com',
    'test@smarttransaction.com', 'admin@expensetracker.com',
    'user@expensetracker.com', 'user1@example.com', 'user2@example.com'
];
foreach ($oldEmails as $oldEmail) {
    $oldUser = $db->users->findOne(['email' => $oldEmail]);
    if ($oldUser) {
        $oldId = $oldUser['_id'];
        foreach (['transactions', 'wallets', 'budgets', 'goals', 'notifications', 'notes', 'beneficiaries', 'complaints', 'receipts', 'appointments', 'sessions', 'activity_logs'] as $collName) {
            $coll = $db->$collName ?? null;
            if ($coll) {
                try { $coll->deleteMany(['user_id' => $oldId]); } catch (Exception $e) {}
            }
        }
        $db->users->deleteOne(['_id' => $oldId]);
        echo "  [DELETED] {$oldEmail} and related data\n";
    }
}

// 4b. Seed demo users (4 roles)
echo "Seeding users...\n";
$demoUsers = [
    ['email' => 'admin1@gmail.com',    'password' => 'admin@001',    'first_name' => 'System',    'last_name' => 'Administrator', 'phone' => '9876500001', 'role' => 'admin',        'balance' => 100000, 'theme' => 'dark'],
    ['email' => 'staff1@gmail.com',    'password' => 'staff@001',    'first_name' => 'Staff',     'last_name' => 'Member',       'phone' => '9876500003', 'role' => 'staff',         'balance' => 50000,  'theme' => 'light'],
    ['email' => 'recept1@gmail.com',   'password' => 'recept@001',   'first_name' => 'Reception', 'last_name' => 'Desk',         'phone' => '9876500004', 'role' => 'receptionist',  'balance' => 30000,  'theme' => 'light'],
    ['email' => 'customer1@gmail.com', 'password' => 'customer@001', 'first_name' => 'Demo',      'last_name' => 'Customer',     'phone' => '9876500005', 'role' => 'customer',      'balance' => 25000,  'theme' => 'light'],
];

$userId = null;
foreach ($demoUsers as $du) {
    $existing = $db->users->findOne(['email' => $du['email']]);
    if ($existing) {
        echo "  [SKIP] {$du['email']} already exists\n";
        if ($du['role'] === 'customer') $userId = $existing['_id'];
        continue;
    }
    $result = $db->users->insertOne([
        'email' => $du['email'],
        'password_hash' => hashPassword($du['password']),
        'first_name' => $du['first_name'],
        'last_name' => $du['last_name'],
        'phone' => $du['phone'],
        'role' => $du['role'],
        'status' => 'active',
        'is_verified' => true,
        'login_attempts' => 0,
        'locked_until' => null,
        'balance' => $du['balance'],
        'currency' => 'INR',
        'theme_preference' => $du['theme'],
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'last_login' => null,
        'deleted_at' => null
    ]);
    if ($du['role'] === 'customer') $userId = $result->getInsertedId();
    echo "  [OK] Created {$du['email']} / {$du['password']} (role: {$du['role']})\n";
}
echo "\n";

// 4c. Roles (MINI_PROJECT RBAC)
echo "Seeding roles...\n";
$roles = [
    ['role_code' => 'ADMIN', 'role_name' => 'Administrator', 'permissions' => ['MANAGE_USERS', 'APPROVE_TX', 'MANAGE_EXPENSES', 'MANAGE_CATEGORIES', 'MANAGE_DEPARTMENTS', 'VIEW_REPORTS', 'VIEW_ANALYTICS', 'MANAGE_SETTINGS', 'MANAGE_ANNOUNCEMENTS', 'EXPORT_REPORTS', 'BACKUP_DB', 'RESTORE_DB', 'VIEW_ALL']],
    ['role_code' => 'MANAGER', 'role_name' => 'Manager', 'permissions' => ['APPROVE_TX', 'VIEW_DEPARTMENT_EXPENSES', 'VIEW_REPORTS', 'VIEW_DASHBOARD', 'MANAGE_PROFILE']],
    ['role_code' => 'EMPLOYEE', 'role_name' => 'Employee', 'permissions' => ['MANAGE_OWN_EXPENSES', 'VIEW_OWN_EXPENSES', 'UPLOAD_BILLS', 'VIEW_REPORTS', 'VIEW_DASHBOARD', 'MANAGE_PROFILE']],
    ['role_code' => 'AUDITOR', 'role_name' => 'Auditor', 'permissions' => ['VIEW_ALL', 'VIEW_REPORTS', 'EXPORT_REPORTS', 'VIEW_AUDIT_LOGS', 'VIEW_ANALYTICS', 'SEARCH_RECORDS', 'FILTER_RECORDS']],
];
$roleCount = $db->roles->countDocuments([]);
if ($roleCount == 0) {
    foreach ($roles as $role) {
        $db->roles->insertOne([
            'role_code' => $role['role_code'],
            'role_name' => $role['role_name'],
            'permissions' => $role['permissions'],
            'is_system' => true,
            'created_at' => phpDateToMongo()
        ]);
    }
    echo "  [OK] Inserted " . count($roles) . " roles\n";
} else {
    echo "  [SKIP] Roles already present ($roleCount)\n";
}
echo "\n";

// 4d. Branches (MINI_PROJECT)
echo "Seeding branches...\n";
$branches = [
    ['branch_code' => 'BR001', 'branch_name' => 'Main Branch', 'address_line1' => 'T. Nagar Main Road', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'pincode' => '600017', 'phone' => '0441111111', 'email' => 'main@smarttransaction.com', 'status' => 'active'],
    ['branch_code' => 'BR002', 'branch_name' => 'North Branch', 'address_line1' => 'Anna Nagar', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'pincode' => '600040', 'phone' => '0442222222', 'email' => 'north@smarttransaction.com', 'status' => 'active'],
];
$branchCount = $db->branches->countDocuments([]);
if ($branchCount == 0) {
    foreach ($branches as $b) {
        $db->branches->insertOne([
            'branch_code' => $b['branch_code'],
            'branch_name' => $b['branch_name'],
            'address_line1' => $b['address_line1'],
            'city' => $b['city'],
            'state' => $b['state'],
            'pincode' => $b['pincode'],
            'phone' => $b['phone'],
            'email' => $b['email'],
            'status' => $b['status'],
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo()
        ]);
    }
    echo "  [OK] Inserted " . count($branches) . " branches\n";
} else {
    echo "  [SKIP] Branches already present ($branchCount)\n";
}
echo "\n";

// 4e. Wallets (MINI_PROJECT digital wallet)
echo "Seeding wallets...\n";
$walletUsers = [
    ['email' => 'admin1@gmail.com',    'balance' => 100000],
    ['email' => 'staff1@gmail.com',    'balance' => 50000],
    ['email' => 'recept1@gmail.com',   'balance' => 30000],
    ['email' => 'customer1@gmail.com', 'balance' => 25000],
];
$walletCount = $db->wallets->countDocuments([]);
if ($walletCount == 0) {
    foreach ($walletUsers as $wu) {
        $u = $db->users->findOne(['email' => $wu['email']]);
        if (!$u) continue;
        $db->wallets->insertOne([
            'user_id' => $u['_id'],
            'balance' => $wu['balance'],
            'currency' => 'INR',
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo()
        ]);
    }
    echo "  [OK] Inserted " . count($walletUsers) . " wallets\n";
} else {
    echo "  [SKIP] Wallets already present ($walletCount)\n";
}
echo "\n";

// 5. Sample transactions
echo "Seeding transactions...\n";
$txCount = $db->transactions->countDocuments(['user_id' => $userId]);
if ($txCount == 0) {
    $now = new DateTime();
    $now->modify('-5 days'); // ensure some fall in current month
    $sampleTransactions = [
        ['type' => 'income', 'category' => 'Salary', 'amount' => 50000, 'description' => 'Monthly salary', 'date' => (clone $now)->modify('-20 days'), 'payment_method' => 'bank_transfer'],
        ['type' => 'expense', 'category' => 'Food', 'amount' => 5000, 'description' => 'Groceries and dining', 'date' => (clone $now)->modify('-16 days'), 'payment_method' => 'upi'],
        ['type' => 'expense', 'category' => 'Travel', 'amount' => 2000, 'description' => 'Fuel and cab', 'date' => (clone $now)->modify('-12 days'), 'payment_method' => 'card'],
        ['type' => 'expense', 'category' => 'Bills & Utilities', 'amount' => 3000, 'description' => 'Electricity and internet', 'date' => (clone $now)->modify('-8 days'), 'payment_method' => 'bank_transfer'],
        ['type' => 'expense', 'category' => 'Shopping', 'amount' => 4000, 'description' => 'Clothes and accessories', 'date' => (clone $now)->modify('-4 days'), 'payment_method' => 'card'],
        ['type' => 'income', 'category' => 'Bonus', 'amount' => 10000, 'description' => 'Performance bonus', 'date' => clone $now, 'payment_method' => 'bank_transfer'],
    ];
    foreach ($sampleTransactions as $t) {
        $db->transactions->insertOne([
            'user_id' => $userId,
            'type' => $t['type'],
            'category' => $t['category'],
            'amount' => $t['amount'],
            'currency' => 'INR',
            'description' => $t['description'],
            'date' => phpDateToMongo($t['date']),
            'payment_method' => $t['payment_method'],
            'is_recurring' => false,
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo(),
            'deleted_at' => null
        ]);
    }
    echo "  [OK] Inserted " . count($sampleTransactions) . " transactions\n";
} else {
    echo "  [SKIP] Transactions already present ($txCount)\n";
}
echo "\n";

// 6. Sample budgets
echo "Seeding budgets...\n";
$budgetCount = $db->budgets->countDocuments(['user_id' => $userId]);
if ($budgetCount == 0) {
    $monthStart = new DateTime('first day of this month');
    $monthEnd = new DateTime('last day of this month');
    $sampleBudgets = [
        ['category' => 'Food', 'monthly_limit' => 8000, 'current_spent' => 5000, 'warning_threshold' => 80],
        ['category' => 'Travel', 'monthly_limit' => 5000, 'current_spent' => 2000, 'warning_threshold' => 80],
        ['category' => 'Shopping', 'monthly_limit' => 6000, 'current_spent' => 4000, 'warning_threshold' => 80],
    ];
    foreach ($sampleBudgets as $b) {
        $db->budgets->insertOne([
            'user_id' => $userId,
            'category' => $b['category'],
            'monthly_limit' => $b['monthly_limit'],
            'current_spent' => $b['current_spent'],
            'period_start' => phpDateToMongo($monthStart),
            'period_end' => phpDateToMongo($monthEnd),
            'warning_threshold' => $b['warning_threshold'],
            'is_active' => true,
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo()
        ]);
    }
    echo "  [OK] Inserted " . count($sampleBudgets) . " budgets\n";
} else {
    echo "  [SKIP] Budgets already present ($budgetCount)\n";
}
echo "\n";

// 7. Sample goals
echo "Seeding goals...\n";
$goalCount = $db->goals->countDocuments(['user_id' => $userId]);
if ($goalCount == 0) {
    $sampleGoals = [
        ['name' => 'Emergency Fund', 'target_amount' => 100000, 'current_amount' => 45000, 'icon' => 'fa-heart', 'description' => 'Build 6 months expense buffer'],
        ['name' => 'Vacation', 'target_amount' => 50000, 'current_amount' => 15000, 'icon' => 'fa-plane', 'description' => 'Trip to Goa'],
    ];
    foreach ($sampleGoals as $g) {
        $db->goals->insertOne([
            'user_id' => $userId,
            'name' => $g['name'],
            'target_amount' => $g['target_amount'],
            'current_amount' => $g['current_amount'],
            'target_date' => phpDateToMongo((new DateTime())->modify('+6 months')),
            'icon' => $g['icon'],
            'description' => $g['description'],
            'status' => 'active',
            'created_at' => phpDateToMongo(),
            'updated_at' => phpDateToMongo()
        ]);
    }
    echo "  [OK] Inserted " . count($sampleGoals) . " goals\n";
} else {
    echo "  [SKIP] Goals already present ($goalCount)\n";
}
echo "\n";

// 8. System settings
echo "Seeding system settings...\n";
$settings = [
    ['setting_key' => 'app_name', 'setting_value' => 'Smart Transaction Control'],
    ['setting_key' => 'default_currency', 'setting_value' => 'INR'],
    ['setting_key' => 'max_upload_size', 'setting_value' => 5242880],
    ['setting_key' => 'session_timeout', 'setting_value' => 3600],
];
$settingsCount = $db->system_settings->countDocuments([]);
if ($settingsCount == 0) {
    foreach ($settings as $s) {
        $db->system_settings->insertOne([
            'setting_key' => $s['setting_key'],
            'setting_value' => $s['setting_value'],
            'description' => '',
            'updated_at' => phpDateToMongo()
        ]);
    }
    echo "  [OK] Inserted " . count($settings) . " system settings\n";
} else {
    echo "  [SKIP] System settings already present ($settingsCount)\n";
}

echo "\n==========================================\n";
echo "Setup complete!\n";
echo "Demo Accounts (login page auto-fills these):\n";
echo "Admin:        admin1@gmail.com    / admin@001\n";
echo "Staff:        staff1@gmail.com    / staff@001\n";
echo "Receptionist: recept1@gmail.com   / recept@001\n";
echo "Customer:     customer1@gmail.com / customer@001\n";
?>
