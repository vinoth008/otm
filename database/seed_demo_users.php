<?php
declare(strict_types=1);
/**
 * MINI_PROJECT - Seed Demo Users into MongoDB Atlas
 * Run: php database/seed_demo_users.php
 *
 * Creates the 4 demo users with their login credentials:
 *   1. Admin        -> admin1@gmail.com    / admin@001
 *   2. Staff        -> staff1@gmail.com    / staff@001
 *   3. Receptionist -> recept1@gmail.com   / recept@001
 *   4. Customer     -> customer1@gmail.com / customer@001
 *
 * For each user it also creates:
 *   - Default expense categories
 *   - A default wallet (Main Account)
 *   - A welcome notification
 *   - Activity log entry
 *
 * If a user already exists, the script updates the password hash
 * so the credentials above are always guaranteed to work.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../backend/config/constants.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/php/security.php';

echo "Seeding demo users...\n";
$db = getMongoDB();
if (!$db) { fwrite(STDERR, "FATAL: Cannot connect to MongoDB Atlas.\n"); exit(1); }
echo "Connected to " . DB_NAME . "\n\n";

$usersCollection = $db->users;
$categoriesCollection = $db->categories;
$walletCollection = $db->wallets;
$notificationsCollection = $db->notifications;
$activityCollection = $db->activity_logs;

// ============================================
// DEMO USERS DEFINITION
// ============================================
$demoUsers = [
    [
        'email'        => 'admin1@gmail.com',
        'password'     => 'admin@001',
        'first_name'   => 'Admin',
        'last_name'    => 'User',
        'phone'        => '9876543210',
        'role'         => 'admin',
        'status'       => 'active',
        'balance'      => 50000.00,
        'avatar'       => 'Admin User',
        'email_verified' => true,
        'theme_preference' => 'light'
    ],
    [
        'email'        => 'staff1@gmail.com',
        'password'     => 'staff@001',
        'first_name'   => 'Staff',
        'last_name'    => 'User',
        'phone'        => '9876543211',
        'role'         => 'staff',
        'status'       => 'active',
        'balance'      => 25000.00,
        'avatar'       => 'Staff User',
        'email_verified' => true,
        'theme_preference' => 'light'
    ],
    [
        'email'        => 'recept1@gmail.com',
        'password'     => 'recept@001',
        'first_name'   => 'Reception',
        'last_name'    => 'User',
        'phone'        => '9876543212',
        'role'         => 'receptionist',
        'status'       => 'active',
        'balance'      => 10000.00,
        'avatar'       => 'Reception User',
        'email_verified' => true,
        'theme_preference' => 'light'
    ],
    [
        'email'        => 'customer1@gmail.com',
        'password'     => 'customer@001',
        'first_name'   => 'Customer',
        'last_name'    => 'User',
        'phone'        => '9876543213',
        'role'         => 'customer',
        'status'       => 'active',
        'balance'      => 5000.00,
        'avatar'       => 'Customer User',
        'email_verified' => true,
        'theme_preference' => 'light'
    ]
];

$defaultCategories = ['Food', 'Transport', 'Shopping', 'Bills', 'Entertainment', 'Health', 'Income', 'Savings'];

foreach ($demoUsers as $demo) {
    $email = $demo['email'];
    echo "Processing: {$email} (role: {$demo['role']})\n";

    // Check if user already exists
    $existing = $usersCollection->findOne(['email' => $email, 'deleted_at' => null]);

    if ($existing) {
        // Update password and ensure account is active so credentials always work
        $uid = $existing['_id'];
        $usersCollection->updateOne(
            ['_id' => $uid],
            [
                '$set' => [
                    'password_hash'   => hashPassword($demo['password']),
                    'role'            => $demo['role'],
                    'status'          => 'active',
                    'first_name'      => $demo['first_name'],
                    'last_name'       => $demo['last_name'],
                    'phone'           => $demo['phone'],
                    'balance'         => $demo['balance'],
                    'email_verified'  => true,
                    'updated_at'      => phpDateToMongo()
                ]
            ]
        );
        $userId = (string)$uid;
        echo "  [UPDATED] Existing user - password & details refreshed\n";
    } else {
        // Create new user
        $doc = [
            'first_name'       => $demo['first_name'],
            'last_name'        => $demo['last_name'],
            'email'            => $email,
            'phone'            => $demo['phone'],
            'password_hash'    => hashPassword($demo['password']),
            'role'             => $demo['role'],
            'status'           => $demo['status'],
            'balance'          => $demo['balance'],
            'avatar'           => $demo['avatar'],
            'email_verified'   => $demo['email_verified'],
            'theme_preference' => $demo['theme_preference'],
            'created_at'       => phpDateToMongo(),
            'updated_at'       => phpDateToMongo(),
            'last_login'       => null,
            'last_login_ip'    => '',
            'deleted_at'       => null
        ];
        $result = $usersCollection->insertOne($doc);
        $userId = (string)$result->getInsertedId();

        // Create default categories
        if ($categoriesCollection) {
            foreach ($defaultCategories as $catName) {
                $categoriesCollection->insertOne([
                    'user_id'    => new MongoDB\BSON\ObjectId($userId),
                    'name'       => $catName,
                    'type'       => in_array($catName, ['Income', 'Savings'], true) ? 'income' : 'expense',
                    'created_at' => phpDateToMongo(),
                    'deleted_at' => null
                ]);
            }
            echo "  [OK] 8 default categories created\n";
        }

        // Create default wallet
        if ($walletCollection) {
            $walletCollection->insertOne([
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'name'       => 'Main Account',
                'balance'    => $demo['balance'],
                'currency'   => 'INR',
                'created_at' => phpDateToMongo(),
                'deleted_at' => null
            ]);
            echo "  [OK] Main Account wallet created\n";
        }

        // Welcome notification
        if ($notificationsCollection) {
            $notificationsCollection->insertOne([
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'type'       => 'account',
                'title'      => 'Welcome to SecureSOT',
                'message'    => 'Your ' . ucfirst($demo['role']) . ' account has been created successfully. Welcome aboard!',
                'is_read'    => false,
                'created_at' => phpDateToMongo(),
                'deleted_at' => null
            ]);
            echo "  [OK] Welcome notification created\n";
        }

        // Activity log
        if ($activityCollection) {
            $activityCollection->insertOne([
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'action'     => 'demo_user_seeded',
                'details'    => ['email' => $email, 'role' => $demo['role']],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'CLI',
                'created_at' => phpDateToMongo()
            ]);
        }

        echo "  [CREATED] New user {$email}\n";
    }
    echo "\n";
}

echo "==========================================\n";
echo "Demo users ready! Credentials:\n";
echo "  Admin        -> admin1@gmail.com    / admin@001\n";
echo "  Staff        -> staff1@gmail.com    / staff@001\n";
echo "  Receptionist -> recept1@gmail.com   / recept@001\n";
echo "  Customer     -> customer1@gmail.com / customer@001\n";
echo "==========================================\n";