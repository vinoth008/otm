<?php
declare(strict_types=1);
/**
 * SeedDatabase — Clean, structure and seed the Smart Transaction Control DB.
 *
 * Run: php database/seed_database.php
 *
 * What it does:
 *   1. Creates all collections + indexes referenced by the app (incl. achievements).
 *   2. Removes ALL user data, then seeds the 4 canonical demo users
 *      (admin1/staff1/recept1/customer1@gmail.com) with wallets.
 *   3. Keeps/repairs system data: system categories, system settings,
 *      roles, branches.
 *   4. Seeds a baseline set of achievements for each demo user so the
 *      achievements feature has working data.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../backend/config/database.php';

$client = getMongoClient();
if (!$client) {
    fwrite(STDERR, "FATAL: Cannot connect to MongoDB Atlas.\n");
    exit(1);
}
$db = $client->selectDatabase(DB_NAME);
echo "Connected to: " . DB_NAME . "\n\n";

$now = new MongoDB\BSON\UTCDateTime();

/* ------------------------------------------------------------------ *
 * 1. COLLECTIONS + INDEXES
 * ------------------------------------------------------------------ */
// name => list of index specs. Each spec: ['key'=>[...], 'unique'=>bool,
// 'sparse'=>bool, 'expireAfterSeconds'=>int]
$collections = [
    'users' => [
        ['key' => ['email' => 1], 'unique' => true],
        ['key' => ['role' => 1, 'status' => 1]],
        ['key' => ['account_number' => 1], 'unique' => true, 'sparse' => true],
        ['key' => ['deleted_at' => 1]],
        ['key' => ['created_at' => -1]],
    ],
    'transactions' => [
        ['key' => ['user_id' => 1, 'date' => -1]],
        ['key' => ['user_id' => 1, 'type' => 1]],
        ['key' => ['user_id' => 1, 'status' => 1, 'deleted_at' => 1]],
        ['key' => ['status' => 1, 'created_at' => -1]],
        ['key' => ['category' => 1]],
        ['key' => ['created_at' => -1]],
    ],
    'activity_logs' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
        ['key' => ['action' => 1, 'created_at' => -1]],
        ['key' => ['created_at' => -1]],
    ],
    'audit_logs' => [
        ['key' => ['admin_id' => 1, 'created_at' => -1]],
        ['key' => ['created_at' => -1]],
    ],
    'login_history' => [
        ['key' => ['email' => 1, 'attempt_time' => -1]],
        ['key' => ['user_id' => 1, 'attempt_time' => -1]],
        ['key' => ['ip_address' => 1]],
        ['key' => ['success' => 1, 'attempt_time' => -1]],
        ['key' => ['attempt_time' => -1]],
    ],
    'login_attempts' => [
        ['key' => ['email' => 1, 'created_at' => -1]],
        ['key' => ['ip_address' => 1, 'created_at' => -1]],
        ['key' => ['created_at' => -1], 'expireAfterSeconds' => 900],
    ],
    'notifications' => [
        ['key' => ['user_id' => 1, 'is_read' => 1, 'created_at' => -1]],
        ['key' => ['type' => 1, 'created_at' => -1]],
    ],
    'categories' => [
        ['key' => ['user_id' => 1, 'name' => 1]],
        ['key' => ['user_id' => 1, 'type' => 1]],
        ['key' => ['is_system' => 1]],
    ],
    'wallets' => [
        ['key' => ['user_id' => 1, 'deleted_at' => 1]],
        ['key' => ['user_id' => 1, 'name' => 1], 'unique' => true, 'partialFilterExpression' => ['deleted_at' => null]],
    ],
    'wallet_transfers' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
        ['key' => ['from_wallet_id' => 1]],
        ['key' => ['to_wallet_id' => 1]],
    ],
    'transfers' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
        ['key' => ['status' => 1, 'created_at' => -1]],
    ],
    'budgets' => [
        ['key' => ['user_id' => 1, 'category' => 1]],
        ['key' => ['user_id' => 1, 'is_active' => 1]],
    ],
    'goals' => [
        ['key' => ['user_id' => 1, 'status' => 1]],
    ],
    'wishlist' => [
        ['key' => ['user_id' => 1]],
    ],
    'notes' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
    ],
    'expenses' => [
        ['key' => ['user_id' => 1, 'date' => -1]],
        ['key' => ['user_id' => 1, 'category' => 1]],
    ],
    'beneficiaries' => [
        ['key' => ['user_id' => 1, 'account_number' => 1]],
        ['key' => ['user_id' => 1, 'created_at' => -1]],
    ],
    'complaints' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
        ['key' => ['status' => 1, 'created_at' => -1]],
    ],
    'receipts' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
        ['key' => ['transaction_id' => 1]],
    ],
    'appointments' => [
        ['key' => ['user_id' => 1, 'appointment_date' => -1]],
        ['key' => ['status' => 1]],
    ],
    'branches' => [
        ['key' => ['branch_code' => 1], 'unique' => true],
        ['key' => ['city' => 1, 'state' => 1]],
    ],
    'roles' => [
        ['key' => ['role_code' => 1], 'unique' => true],
    ],
    'departments' => [
        ['key' => ['department_code' => 1], 'unique' => true, 'sparse' => true],
    ],
    'otp_verifications' => [
        ['key' => ['user_id' => 1, 'otp_purpose' => 1]],
        ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
        ['key' => ['created_at' => 1]],
    ],
    'otp_records' => [
        ['key' => ['email' => 1, 'purpose' => 1, 'created_at' => -1]],
        ['key' => ['email' => 1, 'otp_code' => 1, 'is_used' => 1]],
        ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
    ],
    'email_verifications' => [
        ['key' => ['user_id' => 1]],
        ['key' => ['token_hash' => 1]],
        ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
    ],
    'password_resets' => [
        ['key' => ['user_id' => 1, 'used' => 1]],
        ['key' => ['token_hash' => 1]],
        ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
    ],
    'sessions' => [
        ['key' => ['session_id' => 1], 'unique' => true, 'sparse' => true],
        ['key' => ['user_id' => 1]],
        ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
    ],
    'analytics_cache' => [
        ['key' => ['user_id' => 1, 'cache_type' => 1, 'period_start' => 1]],
    ],
    'recurring_transactions' => [
        ['key' => ['user_id' => 1, 'is_active' => 1]],
        ['key' => ['next_run_date' => 1]],
    ],
    'reminders' => [
        ['key' => ['user_id' => 1, 'due_date' => 1]],
        ['key' => ['user_id' => 1, 'is_active' => 1, 'is_paid' => 1]],
    ],
    'feedback' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
        ['key' => ['status' => 1]],
    ],
    'achievements' => [
        ['key' => ['user_id' => 1, 'achievement_type' => 1], 'unique' => true, 'partialFilterExpression' => ['deleted_at' => null]],
        ['key' => ['user_id' => 1, 'unlocked_at' => -1]],
    ],
    'rate_limits' => [
        ['key' => ['user_id' => 1, 'endpoint' => 1, 'window_start' => 1]],
        ['key' => ['ip_address' => 1, 'endpoint' => 1, 'window_start' => 1]],
    ],
    'error_logs' => [
        ['key' => ['created_at' => -1]],
        ['key' => ['level' => 1, 'created_at' => -1]],
    ],
    'system_settings' => [
        ['key' => ['setting_key' => 1], 'unique' => true],
    ],
];

foreach ($collections as $name => $indexes) {
    $coll = $db->selectCollection($name);
    try {
        foreach ($indexes as $index) {
            $options = [];
            foreach (['unique', 'sparse'] as $flag) {
                if (!empty($index[$flag])) {
                    $options[$flag] = true;
                }
            }
            if (!empty($index['partialFilterExpression'])) {
                $options['partialFilterExpression'] = $index['partialFilterExpression'];
            }
            if (!empty($index['expireAfterSeconds'])) {
                $options['expireAfterSeconds'] = $index['expireAfterSeconds'];
            }
            $coll->createIndex($index['key'], $options);
        }
        echo "[OK] Collection '{$name}' + indexes ready\n";
    } catch (Throwable $e) {
        echo "[WARN] Collection '{$name}': " . $e->getMessage() . "\n";
    }
}

/* ------------------------------------------------------------------ *
 * 2. CLEAN ALL USER DATA
 * ------------------------------------------------------------------ */
$userDataCollections = [
    'users', 'activity_logs', 'audit_logs', 'login_history', 'login_attempts',
    'notifications', 'transactions', 'wallets', 'wallet_transfers', 'transfers',
    'budgets', 'goals', 'wishlist', 'notes', 'expenses', 'beneficiaries',
    'complaints', 'receipts', 'appointments', 'otp_verifications', 'otp_records',
    'email_verifications', 'password_resets', 'sessions', 'analytics_cache',
    'recurring_transactions', 'reminders', 'feedback', 'achievements', 'rate_limits',
    'error_logs',
];
echo "\n--- Cleaning user data ---\n";
foreach ($userDataCollections as $name) {
    try {
        $del = $db->selectCollection($name)->deleteMany([]);
        echo "[CLEANED] {$name} (" . $del->getDeletedCount() . " removed)\n";
    } catch (Throwable $e) {
        echo "[WARN] clean {$name}: " . $e->getMessage() . "\n";
    }
}

// Custom categories (non-system) belong to users -> remove, keep system only
$db->categories->deleteMany(['is_system' => ['$ne' => true]]);
echo "[CLEANED] categories (kept system only)\n";

/* ------------------------------------------------------------------ *
 * 3. SEED SYSTEM DATA (upsert so re-runs are idempotent)
 * ------------------------------------------------------------------ */
echo "\n--- Seeding system data ---\n";

// Roles
$roles = [
    ['role_code' => 'ADMIN', 'role_name' => 'Administrator'],
    ['role_code' => 'STAFF', 'role_name' => 'Staff'],
    ['role_code' => 'RECEPTIONIST', 'role_name' => 'Receptionist'],
    ['role_code' => 'CUSTOMER', 'role_name' => 'Customer'],
];
foreach ($roles as $role) {
    $db->roles->updateOne(
        ['role_code' => $role['role_code']],
        ['$set' => ['role_name' => $role['role_name'], 'updated_at' => $now]],
        ['upsert' => true]
    );
}
echo "[SEED] roles (4)\n";

// Branches
$branches = [
    ['branch_code' => 'BR001', 'branch_name' => 'Main Branch', 'address_line1' => '1 MG Road', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'pincode' => '600017', 'phone' => '+91 44 0000 0001', 'email' => 'main@securesot.com', 'status' => 'active'],
    ['branch_code' => 'BR002', 'branch_name' => 'North Branch', 'address_line1' => '22 Anna Salai', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'pincode' => '600002', 'phone' => '+91 44 0000 0002', 'email' => 'north@securesot.com', 'status' => 'active'],
    ['branch_code' => 'BR003', 'branch_name' => 'South Branch', 'address_line1' => '15 Ashok Nagar', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'pincode' => '600083', 'phone' => '+91 44 0000 0003', 'email' => 'south@securesot.com', 'status' => 'active'],
];
foreach ($branches as $branch) {
    $set = $branch;
    $set['updated_at'] = $now;
    $db->branches->updateOne(['branch_code' => $branch['branch_code']], ['$set' => $set, '$setOnInsert' => ['created_at' => $now]], ['upsert' => true]);
}
echo "[SEED] branches (3)\n";

// System categories
$systemCategories = [
    ['name' => 'Salary', 'type' => 'income', 'icon' => 'wallet', 'color' => '#10b981'],
    ['name' => 'Bonus', 'type' => 'income', 'icon' => 'gift', 'color' => '#34d399'],
    ['name' => 'Investment', 'type' => 'income', 'icon' => 'trending-up', 'color' => '#059669'],
    ['name' => 'Freelance', 'type' => 'income', 'icon' => 'briefcase', 'color' => '#6ee7b7'],
    ['name' => 'Rental', 'type' => 'income', 'icon' => 'home', 'color' => '#a7f3d0'],
    ['name' => 'Other Income', 'type' => 'income', 'icon' => 'plus-circle', 'color' => '#d1fae5'],
    ['name' => 'Food', 'type' => 'expense', 'icon' => 'shopping-cart', 'color' => '#f97316'],
    ['name' => 'Travel', 'type' => 'expense', 'icon' => 'car', 'color' => '#fb923c'],
    ['name' => 'Shopping', 'type' => 'expense', 'icon' => 'bag', 'color' => '#fdba74'],
    ['name' => 'Medical', 'type' => 'expense', 'icon' => 'heart', 'color' => '#ef4444'],
    ['name' => 'Education', 'type' => 'expense', 'icon' => 'book', 'color' => '#f87171'],
    ['name' => 'Entertainment', 'type' => 'expense', 'icon' => 'film', 'color' => '#fca5a5'],
    ['name' => 'Bills & Utilities', 'type' => 'expense', 'icon' => 'zap', 'color' => '#fbbf24'],
    ['name' => 'Rent', 'type' => 'expense', 'icon' => 'home', 'color' => '#f59e0b'],
    ['name' => 'EMI', 'type' => 'expense', 'icon' => 'credit-card', 'color' => '#d97706'],
    ['name' => 'Insurance', 'type' => 'expense', 'icon' => 'shield', 'color' => '#fcd34d'],
    ['name' => 'Subscriptions', 'type' => 'expense', 'icon' => 'repeat', 'color' => '#fde68a'],
    ['name' => 'Fuel', 'type' => 'expense', 'icon' => 'droplet', 'color' => '#f0abfc'],
    ['name' => 'Tax', 'type' => 'expense', 'icon' => 'file-text', 'color' => '#a78bfa'],
    ['name' => 'Loan', 'type' => 'expense', 'icon' => 'hand', 'color' => '#c4b5fd'],
    ['name' => 'Other Expense', 'type' => 'expense', 'icon' => 'minus-circle', 'color' => '#ddd6fe'],
];
foreach ($systemCategories as $cat) {
    $db->categories->updateOne(
        ['name' => $cat['name'], 'type' => $cat['type'], 'is_system' => true],
        ['$set' => ['icon' => $cat['icon'], 'color' => $cat['color'], 'is_system' => true, 'created_at' => $now]],
        ['upsert' => true]
    );
}
echo "[SEED] system categories (" . count($systemCategories) . ")\n";

// System settings (full set)
$settings = [
    ['setting_key' => 'app_name', 'setting_value' => 'Smart Transaction Control', 'description' => 'Application display name'],
    ['setting_key' => 'company_name', 'setting_value' => 'SecureSOT Ltd', 'description' => 'Company / bank display name'],
    ['setting_key' => 'support_email', 'setting_value' => 'support@securesot.com', 'description' => 'Support contact email'],
    ['setting_key' => 'default_currency', 'setting_value' => 'INR', 'description' => 'Default currency for new users'],
    ['setting_key' => 'max_upload_size', 'setting_value' => 5242880, 'description' => 'Maximum file upload size in bytes'],
    ['setting_key' => 'session_timeout', 'setting_value' => 3600, 'description' => 'Session timeout in seconds'],
    ['setting_key' => 'otp_length', 'setting_value' => 6, 'description' => 'OTP code length'],
    ['setting_key' => 'max_login_attempts', 'setting_value' => 5, 'description' => 'Max failed login attempts before lockout'],
];
foreach ($settings as $setting) {
    $db->system_settings->updateOne(
        ['setting_key' => $setting['setting_key']],
        ['$set' => ['setting_value' => $setting['setting_value'], 'description' => $setting['description'], 'updated_at' => $now], '$setOnInsert' => ['created_at' => $now]],
        ['upsert' => true]
    );
}
echo "[SEED] system settings (" . count($settings) . ")\n";

/* ------------------------------------------------------------------ *
 * 4. SEED DEMO USERS
 * ------------------------------------------------------------------ */
echo "\n--- Seeding demo users ---\n";

$demoUsers = [
    ['email' => 'admin1@gmail.com',       'first_name' => 'Admin',    'last_name' => 'User',       'role' => 'admin',        'phone' => '+919000000001', 'account_number' => '10010000001'],
    ['email' => 'staff1@gmail.com',       'first_name' => 'Staff',    'last_name' => 'User',       'role' => 'staff',        'phone' => '+919000000002', 'account_number' => '10010000002'],
    ['email' => 'recept1@gmail.com',      'first_name' => 'Reception', 'last_name' => 'Desk',       'role' => 'receptionist', 'phone' => '+919000000003', 'account_number' => '10010000003'],
    ['email' => 'customer1@gmail.com',    'first_name' => 'Customer', 'last_name' => 'User',       'role' => 'customer',     'phone' => '+919361145095', 'account_number' => '10011223344'],
];

$demoPassword = 'Password@123';
$roleBranchMap = [
    'admin' => 'BR001',
    'staff' => 'BR001',
    'receptionist' => 'BR002',
    'customer' => null,
];

foreach ($demoUsers as $du) {
    $existing = $db->users->findOne(['email' => $du['email']]);
    $branch = null;
    if (!empty($roleBranchMap[$du['role']])) {
        $branchDoc = $db->branches->findOne(['branch_code' => $roleBranchMap[$du['role']]]);
        $branch = $branchDoc['_id'] ?? null;
    }
    $userDoc = [
        'email' => $du['email'],
        'password_hash' => password_hash($demoPassword, PASSWORD_BCRYPT, ['cost' => 12]),
        'first_name' => $du['first_name'],
        'last_name' => $du['last_name'],
        'phone' => $du['phone'],
        'profile_photo' => null,
        'role' => $du['role'],
        'status' => 'active',
        'is_verified' => true,
        'currency' => 'INR',
        'theme_preference' => 'light',
        'login_attempts' => 0,
        'locked_until' => null,
        'company_id' => null,
        'branch_id' => $branch,
        'account_number' => $du['account_number'],
        'balance' => ($du['role'] === 'customer') ? 100000.00 : 0.00,
        'created_at' => $now,
        'updated_at' => $now,
        'last_login' => null,
        'otp_code' => null,
        'otp_expiry' => null,
        'deleted_at' => null,
    ];
    if ($existing) {
        unset($userDoc['created_at']);
        $db->users->updateOne(['_id' => $existing['_id']], ['$set' => $userDoc]);
        $userId = $existing['_id'];
        echo "[UPDATED] {$du['email']} ({$du['role']})\n";
    } else {
        $ins = $db->users->insertOne($userDoc);
        $userId = $ins->getInsertedId();
        echo "[CREATED] {$du['email']} ({$du['role']})\n";
    }

    // Default wallet
    $wallet = [
        'user_id' => $userId,
        'name' => 'Main Account',
        'balance' => ($du['role'] === 'customer') ? 100000.00 : 0.00,
        'currency' => 'INR',
        'icon' => 'wallet',
        'color' => '#6366f1',
        'description' => 'Default account',
        'is_default' => true,
        'account_number' => $du['account_number'],
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ];
    $db->wallets->deleteMany(['user_id' => $userId]);
    $db->wallets->insertOne($wallet);
    echo "      wallet: Main Account (INR " . $wallet['balance'] . ")\n";

    // Welcome notification
    $db->notifications->insertOne([
        'user_id' => $userId,
        'type' => 'system',
        'title' => 'Welcome to SecureSOT',
        'message' => 'Your ' . $du['role'] . ' account is ready. Explore the dashboard.',
        'is_read' => false,
        'created_at' => $now,
    ]);
}

echo "\nDemo credentials (all roles):\n";
echo "  | Role        | Email                  | Password     |\n";
echo "  |-------------|------------------------|--------------|\n";
echo "  | Admin       | admin1@gmail.com       | $demoPassword |\n";
echo "  | Staff       | staff1@gmail.com       | $demoPassword |\n";
echo "  | Receptionist| recept1@gmail.com      | $demoPassword |\n";
echo "  | Customer    | customer1@gmail.com    | $demoPassword |\n";

echo "\n=== Seed complete ===\n";
