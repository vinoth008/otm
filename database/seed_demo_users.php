<?php
declare(strict_types=1);
/**
 * MINI_PROJECT - Seed Demo Users for MongoDB Atlas
 * Run: php database/seed_demo_users.php
 *
 * Seeds:
 *   - 8 demo users across all roles (admin, manager, staff, receptionist, customer/employee, auditor)
 *   - Full system categories (income + expense)
 *   - Wallets for each user
 *   - Transactions for every user
 *   - Budgets, goals, notifications
 *   - Appointments, complaints, beneficiaries, receipts, notes for customer-facing roles
 *   - System settings
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../backend/config/constants.php';
require_once __DIR__ . '/../backend/config/database.php';

// Local date helper (security.php is not loaded when running standalone scripts)
if (!function_exists('phpDateToMongo')) {
    function phpDateToMongo($date = null) {
        if ($date instanceof DateTimeInterface) {
            return new MongoDB\BSON\UTCDateTime($date->getTimestamp() * 1000);
        }
        return new MongoDB\BSON\UTCDateTime(time() * 1000);
    }
}

echo "Seeding demo users...\n";
$db = getMongoDB();
if (!$db) { fwrite(STDERR, "FATAL: Cannot connect to MongoDB Atlas.\n"); exit(1); }
echo "Connected to " . DB_NAME . "\n";

$users = $db->users;
$wallets = $db->wallets;

// ============================================
// 1. USERS
// ============================================
$demoUsers = [
    ['email' => 'admin@sot.com',      'password' => 'Admin@123',   'first_name' => 'System',    'last_name' => 'Administrator', 'phone' => '9876500001', 'role' => 'admin',        'balance' => 100000, 'theme' => 'dark'],
    ['email' => 'manager@sot.com',    'password' => 'Manager@123', 'first_name' => 'Priya',     'last_name' => 'Sharma',       'phone' => '9876500002', 'role' => 'manager',       'balance' => 75000,  'theme' => 'dark'],
    ['email' => 'staff@sot.com',      'password' => 'Staff@123',   'first_name' => 'Staff',     'last_name' => 'Member',       'phone' => '9876500003', 'role' => 'staff',         'balance' => 50000,  'theme' => 'light'],
    ['email' => 'recept@sot.com',     'password' => 'Recept@123',  'first_name' => 'Reception', 'last_name' => 'Desk',         'phone' => '9876500004', 'role' => 'receptionist',  'balance' => 30000,  'theme' => 'light'],
    ['email' => 'cust@sot.com',       'password' => 'Cust@123',    'first_name' => 'Demo',      'last_name' => 'Customer',     'phone' => '9876500005', 'role' => 'customer',      'balance' => 25000,  'theme' => 'light'],
    ['email' => 'employee@sot.com',   'password' => 'User@123',    'first_name' => 'Rahul',     'last_name' => 'Verma',        'phone' => '9876500006', 'role' => 'employee',      'balance' => 40000,  'theme' => 'light'],
    ['email' => 'auditor@sot.com',    'password' => 'Audit@123',   'first_name' => 'Anita',     'last_name' => 'Rao',          'phone' => '9876500007', 'role' => 'auditor',       'balance' => 0,      'theme' => 'dark'],
    ['email' => 'customer2@sot.com',  'password' => 'Cust@123',    'first_name' => 'Sneha',     'last_name' => 'Nair',         'phone' => '9876500008', 'role' => 'customer',      'balance' => 15000,  'theme' => 'dark'],
];

$userIds = [];

foreach ($demoUsers as $du) {
    if ($users->findOne(['email' => $du['email']])) {
        echo "  [SKIP] {$du['email']} already exists\n";
        $existing = $users->findOne(['email' => $du['email']]);
        $userIds[$du['email']] = $existing['_id'];
        continue;
    }
    $result = $users->insertOne([
        'first_name' => $du['first_name'],
        'last_name' => $du['last_name'],
        'email' => $du['email'],
        'phone' => $du['phone'],
        'password_hash' => password_hash($du['password'], PASSWORD_BCRYPT, ['cost' => HASH_COST]),
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
        'last_login_ip' => '',
        'deleted_at' => null
    ]);
    $userIds[$du['email']] = $result->getInsertedId();
    echo "  [OK] Created {$du['email']} / {$du['password']} (role: {$du['role']})\n";

    if ($wallets) {
        $walletSeed = [
            'user_id' => $result->getInsertedId(),
            'name' => 'Main Account',
            'balance' => $du['balance'],
            'currency' => 'INR', 'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo(), 'deleted_at' => null
        ];
        $wallets->insertOne($walletSeed);

        $wallets->insertOne([
            'user_id' => $result->getInsertedId(),
            'name' => 'Savings',
            'balance' => intval($du['balance'] * 0.3),
            'currency' => 'INR', 'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo(), 'deleted_at' => null
        ]);
    }
}

// ============================================
// 2. SYSTEM CATEGORIES
// ============================================
$catColl = $db->categories;
$systemCategories = [
    ['name' => 'Salary',           'type' => 'income',  'icon' => 'fa-money-bill-wave', 'color' => '#10b981', 'is_system' => true, 'sort_order' => 1],
    ['name' => 'Bonus',            'type' => 'income',  'icon' => 'fa-gift',            'color' => '#34d399',  'is_system' => true, 'sort_order' => 2],
    ['name' => 'Investment',       'type' => 'income',  'icon' => 'fa-chart-line',      'color' => '#059669',  'is_system' => true, 'sort_order' => 3],
    ['name' => 'Freelance',        'type' => 'income',  'icon' => 'fa-laptop',          'color' => '#6ee7b7',  'is_system' => true, 'sort_order' => 4],
    ['name' => 'Rental',           'type' => 'income',  'icon' => 'fa-home',            'color' => '#a7f3d0',  'is_system' => true, 'sort_order' => 5],
    ['name' => 'Other Income',     'type' => 'income',  'icon' => 'fa-plus-circle',     'color' => '#d1fae5',  'is_system' => true, 'sort_order' => 6],
    ['name' => 'Food',             'type' => 'expense', 'icon' => 'fa-utensils',        'color' => '#f97316',  'is_system' => true, 'sort_order' => 7],
    ['name' => 'Travel',           'type' => 'expense', 'icon' => 'fa-car',             'color' => '#fb923c',  'is_system' => true, 'sort_order' => 8],
    ['name' => 'Shopping',         'type' => 'expense', 'icon' => 'fa-shopping-bag',    'color' => '#fdba74',  'is_system' => true, 'sort_order' => 9],
    ['name' => 'Bills & Utilities','type' => 'expense', 'icon' => 'fa-bolt',            'color' => '#fbbf24',  'is_system' => true, 'sort_order' => 10],
    ['name' => 'Entertainment',    'type' => 'expense', 'icon' => 'fa-film',            'color' => '#fca5a5',  'is_system' => true, 'sort_order' => 11],
    ['name' => 'Medical',          'type' => 'expense', 'icon' => 'fa-heart',           'color' => '#ef4444',  'is_system' => true, 'sort_order' => 12],
    ['name' => 'Education',        'type' => 'expense', 'icon' => 'fa-book',            'color' => '#f87171',  'is_system' => true, 'sort_order' => 13],
    ['name' => 'Rent',             'type' => 'expense', 'icon' => 'fa-home',            'color' => '#f59e0b',  'is_system' => true, 'sort_order' => 14],
    ['name' => 'EMI',              'type' => 'expense', 'icon' => 'fa-credit-card',     'color' => '#d97706',  'is_system' => true, 'sort_order' => 15],
    ['name' => 'Insurance',        'type' => 'expense', 'icon' => 'fa-shield-alt',      'color' => '#fcd34d',  'is_system' => true, 'sort_order' => 16],
    ['name' => 'Subscriptions',    'type' => 'expense', 'icon' => 'fa-sync',            'color' => '#fde68a',  'is_system' => true, 'sort_order' => 17],
    ['name' => 'Fuel',             'type' => 'expense', 'icon' => 'fa-gas-pump',        'color' => '#f0abfc',  'is_system' => true, 'sort_order' => 18],
    ['name' => 'Tax',              'type' => 'expense', 'icon' => 'fa-file-invoice-dollar','color' => '#a78bfa', 'is_system' => true, 'sort_order' => 19],
    ['name' => 'Loan',             'type' => 'expense', 'icon' => 'fa-hand-holding-usd','color' => '#c4b5fd',  'is_system' => true, 'sort_order' => 20],
    ['name' => 'Transfer',         'type' => 'transfer','icon' => 'fa-exchange-alt',    'color' => '#3b82f6',  'is_system' => true, 'sort_order' => 21],
    ['name' => 'Other Expense',    'type' => 'expense', 'icon' => 'fa-minus-circle',    'color' => '#ddd6fe',  'is_system' => true, 'sort_order' => 22],
];
$catCount = $catColl->countDocuments();
if ($catCount == 0) {
    $catColl->insertMany($systemCategories);
    echo "  [OK] Inserted " . count($systemCategories) . " system categories\n";
} else {
    echo "  [SKIP] {$catCount} categories already exist\n";
}

// ============================================
// 3. TRANSACTIONS FOR EVERY USER
// ============================================
$txColl = $db->transactions;

$txTemplates = [
    'cust@sot.com' => [
        ['income', 'Salary', 50000, 'Monthly salary', '-20 days', 'bank_transfer', 'completed'],
        ['expense', 'Food', 5000, 'Groceries and dining', '-16 days', 'upi', 'completed'],
        ['expense', 'Travel', 2000, 'Fuel and cab', '-12 days', 'card', 'completed'],
        ['expense', 'Bills & Utilities', 3000, 'Electricity and internet', '-8 days', 'bank_transfer', 'completed'],
        ['expense', 'Shopping', 4000, 'Clothes and accessories', '-4 days', 'card', 'completed'],
        ['income', 'Bonus', 10000, 'Performance bonus', '-2 days', 'bank_transfer', 'completed'],
        ['expense', 'Medical', 1200, 'Pharmacy', '-1 days', 'upi', 'completed'],
        ['expense', 'Entertainment', 1500, 'Movie tickets', '-5 days', 'card', 'pending'],
    ],
    'employee@sot.com' => [
        ['income', 'Salary', 40000, 'Monthly salary', '-18 days', 'bank_transfer', 'completed'],
        ['expense', 'Food', 3500, 'Lunch and snacks', '-15 days', 'upi', 'completed'],
        ['expense', 'Travel', 1800, 'Metro card recharge', '-10 days', 'wallet', 'completed'],
        ['expense', 'Subscriptions', 999, 'Netflix & Spotify', '-9 days', 'card', 'completed'],
        ['expense', 'Education', 2500, 'Online course', '-6 days', 'upi', 'completed'],
        ['income', 'Freelance', 8000, 'Design project', '-3 days', 'bank_transfer', 'completed'],
        ['expense', 'Fuel', 2500, 'Petrol', '-2 days', 'card', 'pending'],
    ],
    'manager@sot.com' => [
        ['income', 'Salary', 75000, 'Monthly salary', '-19 days', 'bank_transfer', 'completed'],
        ['expense', 'Rent', 15000, 'Apartment rent', '-14 days', 'bank_transfer', 'completed'],
        ['expense', 'Shopping', 8000, 'Home appliances', '-11 days', 'card', 'completed'],
        ['expense', 'Food', 6000, 'Family dining', '-7 days', 'upi', 'completed'],
        ['expense', 'EMI', 9500, 'Car EMI', '-5 days', 'bank_transfer', 'completed'],
        ['income', 'Bonus', 15000, 'Quarterly bonus', '-1 days', 'bank_transfer', 'completed'],
        ['expense', 'Medical', 3000, 'Health checkup', '-3 days', 'card', 'pending'],
    ],
    'staff@sot.com' => [
        ['income', 'Salary', 45000, 'Monthly salary', '-17 days', 'bank_transfer', 'completed'],
        ['expense', 'Food', 4200, 'Meals', '-13 days', 'upi', 'completed'],
        ['expense', 'Bills & Utilities', 2800, 'WiFi + electricity', '-9 days', 'bank_transfer', 'completed'],
        ['expense', 'Entertainment', 1200, 'Concert', '-6 days', 'card', 'completed'],
        ['expense', 'Loan', 5000, 'Personal loan EMi', '-4 days', 'bank_transfer', 'completed'],
        ['income', 'Freelance', 6000, 'Website project', '-2 days', 'upi', 'completed'],
    ],
    'recept@sot.com' => [
        ['income', 'Salary', 30000, 'Monthly salary', '-16 days', 'bank_transfer', 'completed'],
        ['expense', 'Food', 3000, 'Meals', '-11 days', 'upi', 'completed'],
        ['expense', 'Travel', 1500, 'Auto fare', '-7 days', 'wallet', 'completed'],
        ['expense', 'Shopping', 2000, 'Cosmetics', '-3 days', 'card', 'completed'],
        ['expense', 'Education', 1000, 'Skill course', '-1 days', 'upi', 'pending'],
    ],
    'customer2@sot.com' => [
        ['income', 'Salary', 35000, 'Monthly salary', '-15 days', 'bank_transfer', 'completed'],
        ['expense', 'Food', 4000, 'Groceries', '-10 days', 'upi', 'completed'],
        ['expense', 'Rent', 10000, 'Room rent', '-5 days', 'bank_transfer', 'completed'],
        ['expense', 'Shopping', 3500, 'Clothes', '-2 days', 'card', 'completed'],
        ['income', 'Rental', 5000, 'Second room income', '-1 days', 'upi', 'completed'],
    ],
    'admin@sot.com' => [
        ['expense', 'Bills & Utilities', 8000, 'Server hosting', '-12 days', 'bank_transfer', 'completed'],
        ['expense', 'Subscriptions', 4000, 'SaaS tools', '-8 days', 'card', 'completed'],
        ['expense', 'Insurance', 12000, 'Business insurance', '-3 days', 'bank_transfer', 'completed'],
    ],
];

foreach ($txTemplates as $email => $txs) {
    if (!isset($userIds[$email])) { echo "  [SKIP] $email not found (template skipped)\n"; continue; }
    $uid = $userIds[$email];
    $txCount = $txColl->countDocuments(['user_id' => $uid]);
    if ($txCount > 0) { echo "  [SKIP] $email already has $txCount transactions\n"; continue; }
    $now = new DateTime();
    foreach ($txs as $t) {
        $txColl->insertOne([
            'user_id' => $uid, 'type' => $t[0], 'category' => $t[1], 'amount' => $t[2],
            'currency' => 'INR', 'description' => $t[3],
            'date' => phpDateToMongo((clone $now)->modify($t[4])),
            'payment_method' => $t[5], 'status' => $t[6], 'is_recurring' => false,
            'reference' => 'TXN' . strtoupper(bin2hex(random_bytes(4))),
            'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo(), 'deleted_at' => null
        ]);
    }
    echo "  [OK] Inserted " . count($txs) . " transactions for $email\n";
}

// ============================================
// 4. BUDGETS
// ============================================
$budgetColl = $db->budgets;
$budgetTemplates = [
    'cust@sot.com'   => [['Food', 8000, 5000], ['Travel', 5000, 2000], ['Shopping', 6000, 4000], ['Entertainment', 3000, 1500]],
    'employee@sot.com' => [['Food', 6000, 3500], ['Travel', 3000, 1800], ['Subscriptions', 1500, 999], ['Education', 4000, 2500]],
    'manager@sot.com' => [['Rent', 18000, 15000], ['Food', 8000, 6000], ['EMI', 10000, 9500], ['Shopping', 10000, 8000]],
    'staff@sot.com'  => [['Food', 6000, 4200], ['Bills & Utilities', 4000, 2800], ['Entertainment', 2000, 1200]],
    'recept@sot.com' => [['Food', 4000, 3000], ['Travel', 2500, 1500], ['Shopping', 3000, 2000]],
    'customer2@sot.com' => [['Food', 5000, 4000], ['Rent', 12000, 10000], ['Shopping', 4000, 3500]],
];

foreach ($budgetTemplates as $email => $budgets) {
    if (!isset($userIds[$email])) continue;
    $uid = $userIds[$email];
    $existing = $budgetColl->countDocuments(['user_id' => $uid]);
    if ($existing > 0) { echo "  [SKIP] $email already has budgets\n"; continue; }
    $ms = new DateTime('first day of this month');
    $me = new DateTime('last day of this month');
    foreach ($budgets as $b) {
        $budgetColl->insertOne([
            'user_id' => $uid, 'category' => $b[0], 'monthly_limit' => $b[1], 'current_spent' => $b[2],
            'period_start' => phpDateToMongo($ms), 'period_end' => phpDateToMongo($me),
            'warning_threshold' => 80, 'is_active' => true,
            'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()
        ]);
    }
    echo "  [OK] Inserted " . count($budgets) . " budgets for $email\n";
}

// ============================================
// 5. GOALS
// ============================================
$goalColl = $db->goals;
$goalTemplates = [
    'cust@sot.com' => [
        ['Emergency Fund', 100000, 45000, '2026-12-31', 'high', 'active', 'Build 6 months expense buffer'],
        ['Vacation', 50000, 15000, '2027-03-31', 'medium', 'active', 'Trip to Goa'],
    ],
    'employee@sot.com' => [
        ['New Laptop', 80000, 25000, '2026-10-31', 'high', 'active', 'MacBook for work'],
        ['Emergency Fund', 60000, 12000, '2027-06-30', 'medium', 'active', 'Rainy day savings'],
    ],
    'manager@sot.com' => [
        ['Home Down Payment', 500000, 120000, '2027-12-31', 'high', 'active', 'Save for own house'],
        ['Car Upgrade', 300000, 60000, '2028-06-30', 'low', 'active', 'New SUV'],
    ],
];

foreach ($goalTemplates as $email => $goals) {
    if (!isset($userIds[$email])) continue;
    $uid = $userIds[$email];
    if ($goalColl->countDocuments(['user_id' => $uid]) > 0) { echo "  [SKIP] $email already has goals\n"; continue; }
    foreach ($goals as $g) {
        $goalColl->insertOne([
            'user_id' => $uid, 'name' => $g[0], 'target_amount' => $g[1], 'current_amount' => $g[2],
            'deadline' => phpDateToMongo(new DateTime($g[3])),
            'priority' => $g[4], 'status' => $g[5], 'notes' => $g[6],
            'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()
        ]);
    }
    echo "  [OK] Inserted " . count($goals) . " goals for $email\n";
}

// ============================================
// 6. NOTIFICATIONS
// ============================================
$notifColl = $db->notifications;
$notifTemplates = [
    'cust@sot.com' => [
        ['Budget Alert', 'You have used 80% of your Food budget for this month.', 'budget'],
        ['Welcome', 'Welcome to Smart Transaction Control! Start tracking your expenses.', 'system'],
    ],
    'employee@sot.com' => [
        ['Expense Approved', 'Your Travel expense of ₹1,800 has been approved by your manager.', 'expense'],
        ['Budget Alert', 'You have used 75% of your Food budget.', 'budget'],
    ],
    'manager@sot.com' => [
        ['Pending Approvals', 'You have 3 expense requests waiting for approval.', 'approval'],
        ['Report Ready', 'Your departmental report for this month is ready.', 'report'],
    ],
];

foreach ($notifTemplates as $email => $notifs) {
    if (!isset($userIds[$email])) continue;
    $uid = $userIds[$email];
    if ($notifColl->countDocuments(['user_id' => $uid]) > 0) { echo "  [SKIP] $email already has notifications\n"; continue; }
    foreach ($notifs as $n) {
        $notifColl->insertOne([
            'user_id' => $uid, 'title' => $n[0], 'message' => $n[1],
            'type' => $n[2], 'read' => false,
            'created_at' => phpDateToMongo(), 'read_at' => null
        ]);
    }
    echo "  [OK] Inserted " . count($notifs) . " notifications for $email\n";
}

// ============================================
// 7. APPOINTMENTS (receptionist-facing)
// ============================================
$apptColl = $db->appointments;
$apptSeeds = [
    ['recept@sot.com', 'cust@sot.com',  'Account opening consultation', '2026-08-05 10:30:00', 'scheduled', 'Bring your PAN and Aadhaar'],
    ['recept@sot.com', 'customer2@sot.com', 'Loan application follow-up', '2026-08-06 14:00:00', 'scheduled', 'Documents required'],
    ['recept@sot.com', 'staff@sot.com',  'Salary account query', '2026-08-07 11:00:00', 'pending', null],
];
foreach ($apptSeeds as $a) {
    if (!isset($userIds[$a[0]]) || !isset($userIds[$a[1]])) continue;
    $exists = $apptColl->findOne(['staff_id' => $userIds[$a[0]], 'customer_id' => $userIds[$a[1]], 'title' => $a[2]]);
    if ($exists) continue;
    $apptColl->insertOne([
        'staff_id' => $userIds[$a[0]],
        'customer_id' => $userIds[$a[1]],
        'title' => $a[2],
        'scheduled_at' => phpDateToMongo(new DateTime($a[3])),
        'status' => $a[4],
        'notes' => $a[5],
        'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()
    ]);
}
echo "  [OK] Inserted " . $apptColl->countDocuments() . " total appointments\n";

// ============================================
// 8. COMPLAINTS
// ============================================
$compColl = $db->complaints;
$complaintSeeds = [
    ['cust@sot.com',  'APP003', 'App mobile login not working', 'Warning',   'APP-2026-0001', 'open'],
    ['customer2@sot.com', 'TRN002', 'Expected transaction missing from statement', 'Medium', 'TRN-2026-0002', 'in_progress'],
];
foreach ($complaintSeeds as $c) {
    if (!isset($userIds[$c[0]])) continue;
    $uid = $userIds[$c[0]];
    if ($compColl->findOne(['user_id' => $uid, 'complaint_no' => $c[4]])) continue;
    $compColl->insertOne([
        'user_id' => $uid,
        'type' => $c[1],
        'subject' => $c[2],
        'priority' => $c[3],
        'complaint_no' => $c[4],
        'status' => $c[5],
        'description' => $c[2],
        'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()
    ]);
}
echo "  [OK] Inserted " . $compColl->countDocuments() . " total complaints\n";

// ============================================
// 9. BENEFICIARIES
// ============================================
$benColl = $db->beneficiaries;
$benSeeds = [
    ['cust@sot.com', 'Ramesh Kumar', 'Paytm UPI',      'ramesh@paytm'],
    ['cust@sot.com', 'SBI - Rent',    'Bank Account',  'SBIN0001234'],
    ['employee@sot.com', 'Landlord',  'Bank Account',  'HDFC0005678'],
];
foreach ($benSeeds as $b) {
    if (!isset($userIds[$b[0]])) continue;
    $uid = $userIds[$b[0]];
    if ($benColl->findOne(['user_id' => $uid, 'name' => $b[1]])) continue;
    $benColl->insertOne([
        'user_id' => $uid, 'name' => $b[1], 'type' => $b[2], 'account_details' => $b[3],
        'is_active' => true, 'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()
    ]);
}
echo "  [OK] Inserted " . $benColl->countDocuments() . " total beneficiaries\n";

// ============================================
// 10. NOTES
// ============================================
$noteColl = $db->notes;
$noteSeeds = [
    ['cust@sot.com', 'Monthly budget review', 'Review all subscriptions and trim unused ones.', 'personal'],
    ['cust@sot.com', 'Tax filing reminders', 'Submit Form 16 to CA before August 15.', 'work'],
    ['employee@sot.com', 'Work trip reimbursements', 'Collect all cab bills for the Mumbai trip.', 'work'],
];
foreach ($noteSeeds as $n) {
    if (!isset($userIds[$n[0]])) continue;
    $uid = $userIds[$n[0]];
    if ($noteColl->findOne(['user_id' => $uid, 'title' => $n[1]])) continue;
    $noteColl->insertOne([
        'user_id' => $uid, 'title' => $n[1], 'content' => $n[2], 'type' => $n[3], 'color' => '#4f46e5',
        'is_pinned' => false, 'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()
    ]);
}
echo "  [OK] Inserted " . $noteColl->countDocuments() . " total notes\n";

// ============================================
// 11. SYSTEM SETTINGS
// ============================================
$settingsColl = $db->system_settings;
$settings = [
    ['setting_key' => 'app_name', 'setting_value' => 'Smart Transaction Control', 'description' => 'Application display name'],
    ['setting_key' => 'default_currency', 'setting_value' => 'INR', 'description' => 'Default currency for new users'],
    ['setting_key' => 'max_upload_size', 'setting_value' => 5242880, 'description' => 'Maximum file upload size in bytes'],
    ['setting_key' => 'session_timeout', 'setting_value' => 3600, 'description' => 'Session timeout in seconds'],
    ['setting_key' => 'company_name', 'setting_value' => 'SOT Bank Ltd', 'description' => 'Company / bank display name'],
    ['setting_key' => 'support_email', 'setting_value' => 'support@sot.com', 'description' => 'Support contact email'],
    ['setting_key' => 'support_phone', 'setting_value' => '1800-419-0000', 'description' => 'Support toll-free number'],
];
if ($settingsColl->countDocuments() == 0) {
    $settingsColl->insertMany($settings);
    echo "  [OK] Inserted " . count($settings) . " system settings\n";
} else {
    echo "  [SKIP] System settings already exist\n";
}

// ============================================
// 12. ACTIVITY / AUDIT LOGS
// ============================================
$logColl = $db->activity_logs;
if ($logColl->countDocuments() == 0) {
    foreach ($userIds as $email => $uid) {
        $logColl->insertOne([
            'user_id' => $uid,
            'email' => $email,
            'action' => 'user.seeded',
            'description' => "Demo user account created for $email",
            'ip_address' => '127.0.0.1',
            'user_agent' => 'seed-script',
            'created_at' => phpDateToMongo()
        ]);
    }
    $auditLog = $db->audit_logs;
    if ($auditLog) {
        $auditLog->insertOne([
            'action' => 'database.seeded',
            'actor' => 'system',
            'actor_email' => 'setup',
            'description' => 'Initial demo data seeded into database',
            'ip_address' => '127.0.0.1',
            'created_at' => phpDateToMongo()
        ]);
    }
    echo "  [OK] Inserted activity + audit logs\n";
}

echo "\nSeeding complete!\n";
echo "Login credentials:\n";
foreach ($demoUsers as $du) {
    echo "  {$du['email']} / {$du['password']}\n";
}