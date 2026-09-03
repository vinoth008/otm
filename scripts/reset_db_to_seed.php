<?php
declare(strict_types=1);
/**
 * Database Reset and Seed Script
 * Cleans the database of all non-seeded data and resets user balances.
 */
require_once __DIR__ . '/../backend/config/constants.php';
require_once __DIR__ . '/../backend/config/database.php';

echo "=== Resetting Database to Clean Seed State ===\n";

$db = getMongoDB();
if (!$db) {
    die("FATAL: Cannot connect to MongoDB.\n");
}

$allowedEmails = [
    'admin1@gmail.com',
    'staff1@gmail.com',
    'recept1@gmail.com',
    'customer1@gmail.com'
];

// Clean users collection - remove any user that is not in the allowed seeded list
$usersCollection = $db->users;
$allUsers = $usersCollection->find([])->toArray();
$keptUserIds = [];

foreach ($allUsers as $user) {
    if (!in_array($user['email'], $allowedEmails)) {
        $usersCollection->deleteOne(['_id' => $user['_id']]);
        echo "Removed user: " . $user['email'] . "\n";
    } else {
        $keptUserIds[(string)$user['_id']] = $user['email'];
        // Re-enable/activate target seeded users
        $usersCollection->updateOne(
            ['_id' => $user['_id']],
            ['$set' => [
                'status' => 'active',
                'is_verified' => true,
                'deleted_at' => null
            ]]
        );
        echo "Kept and activated user: " . $user['email'] . "\n";
    }
}

// Balance and Wallets: Reset customer1 wallet to 100000.00
$customerUser = $usersCollection->findOne(['email' => 'customer1@gmail.com']);
if ($customerUser) {
    $custUserId = $customerUser['_id'];
    
    // Ensure customer has correct account number
    $usersCollection->updateOne(
        ['_id' => $custUserId],
        ['$set' => ['account_number' => '10011223344']]
    );
    
    // Reset/create wallet for customer
    $walletsCollection = $db->wallets;
    $walletsCollection->deleteMany(['user_id' => ['$ne' => $custUserId]]);
    
    $wallet = $walletsCollection->findOne(['user_id' => $custUserId]);
    if (!$wallet) {
        $walletsCollection->insertOne([
            'user_id' => $custUserId,
            'balance' => 100000.00,
            'currency' => 'INR',
            'account_number' => '10011223344',
            'created_at' => new MongoDB\BSON\UTCDateTime(),
            'updated_at' => new MongoDB\BSON\UTCDateTime()
        ]);
        echo "Created wallet for customer1@gmail.com with 100,000 INR balance.\n";
    } else {
        $walletsCollection->updateOne(
            ['user_id' => $custUserId],
            ['$set' => [
                'balance' => 100000.00,
                'account_number' => '10011223344',
                'updated_at' => new MongoDB\BSON\UTCDateTime()
            ]]
        );
        echo "Reset wallet balance for customer1@gmail.com to 100,000 INR.\n";
    }
}

// Clear non-essential collections completely for clean state
$collectionsToClear = [
    'transactions',
    'login_history',
    'activity_logs',
    'notifications',
    'goals',
    'complaints',
    'appointments',
    'receipts',
    'otp_verifications'
];

foreach ($collectionsToClear as $colName) {
    $db->selectCollection($colName)->deleteMany([]);
    echo "Cleared collection: $colName\n";
}

// Keep categories but clear non-system ones
$db->categories->deleteMany(['is_system' => ['$ne' => true]]);
echo "Cleared custom categories, kept system categories.\n";

// Clear beneficiary list
$db->beneficiaries->deleteMany([]);
echo "Cleared beneficiaries list.\n";

// Add receptionist beneficiary if required (though customer can add it dynamically)
echo "=== Database clean and reset complete! ===\n";
