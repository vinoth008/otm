<?php
declare(strict_types=1);
/**
 * MINI_PROJECT - Seed Demo Users for MongoDB Atlas
 * Run: php database/seed_demo_users.php
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

$demoUsers = [
    ['email' => 'admin@sot.com', 'password' => 'Admin@123', 'first_name' => 'System', 'last_name' => 'Administrator', 'phone' => '9876500001', 'role' => 'admin', 'balance' => 100000, 'theme' => 'dark'],
    ['email' => 'staff@sot.com', 'password' => 'Staff@123', 'first_name' => 'Staff', 'last_name' => 'Member', 'phone' => '9876500002', 'role' => 'staff', 'balance' => 50000, 'theme' => 'light'],
    ['email' => 'recept@sot.com', 'password' => 'Recept@123', 'first_name' => 'Reception', 'last_name' => 'Desk', 'phone' => '9876500003', 'role' => 'receptionist', 'balance' => 30000, 'theme' => 'light'],
    ['email' => 'cust@sot.com', 'password' => 'Cust@123', 'first_name' => 'Demo', 'last_name' => 'Customer', 'phone' => '9876500004', 'role' => 'customer', 'balance' => 25000, 'theme' => 'light'],
];

foreach ($demoUsers as $du) {
    if ($users->findOne(['email' => $du['email']])) {
        echo "  [SKIP] {$du['email']} already exists\n";
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
    $id = $result->getInsertedId();
    echo "  [OK] Created {$du['email']} / {$du['password']} (role: {$du['role']})\n";
    if ($wallets) {
        $wallets->insertOne([
            'user_id' => $id, 'name' => 'Main Account', 'balance' => $du['balance'],
            'currency' => 'INR', 'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo(), 'deleted_at' => null
        ]);
    }
}

// Seed sample data for customer demo
$cust = $users->findOne(['email' => 'cust@sot.com']);
if ($cust) {
    $cid = $cust['_id'];
    $txCount = $db->transactions->countDocuments(['user_id' => $cid]);
    if ($txCount == 0) {
        $now = new DateTime();
        $txs = [
            ['income', 'Salary', 50000, 'Monthly salary', '-20 days', 'bank_transfer'],
            ['expense', 'Food', 5000, 'Groceries and dining', '-16 days', 'upi'],
            ['expense', 'Travel', 2000, 'Fuel and cab', '-12 days', 'card'],
            ['expense', 'Bills & Utilities', 3000, 'Electricity and internet', '-8 days', 'bank_transfer'],
            ['expense', 'Shopping', 4000, 'Clothes and accessories', '-4 days', 'card'],
            ['income', 'Bonus', 10000, 'Performance bonus', '0 days', 'bank_transfer'],
        ];
        foreach ($txs as $t) {
            $db->transactions->insertOne([
                'user_id' => $cid, 'type' => $t[0], 'category' => $t[1], 'amount' => $t[2],
                'currency' => 'INR', 'description' => $t[3],
                'date' => phpDateToMongo((new DateTime())->modify($t[4])),
                'payment_method' => $t[5], 'status' => 'completed', 'is_recurring' => false,
                'reference' => 'TXN' . strtoupper(bin2hex(random_bytes(4))),
                'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo(), 'deleted_at' => null
            ]);
        }
        echo "  [OK] Inserted 6 transactions for cust@sot.com\n";
    }
    $budgetCount = $db->budgets->countDocuments(['user_id' => $cid]);
    if ($budgetCount == 0) {
        $ms = new DateTime('first day of this month');
        $me = new DateTime('last day of this month');
        foreach ([['Food',8000,5000], ['Travel',5000,2000], ['Shopping',6000,4000]] as $b) {
            $db->budgets->insertOne([
                'user_id' => $cid, 'category' => $b[0], 'monthly_limit' => $b[1], 'current_spent' => $b[2],
                'period_start' => phpDateToMongo($ms), 'period_end' => phpDateToMongo($me),
                'warning_threshold' => 80, 'is_active' => true,
                'created_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()
            ]);
        }
        echo "  [OK] Inserted 3 budgets for cust@sot.com\n";
    }
}

echo "Done!\n";