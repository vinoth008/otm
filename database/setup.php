<?php
declare(strict_types=1);
/**
 * Database Setup & Index Creation for Smart Transaction Control
 * Run: php database/setup.php
 *
 * Creates required collections and indexes for optimal performance.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../backend/config/constants.php';
require_once __DIR__ . '/../backend/config/database.php';

echo "=== Smart Transaction Control - Database Setup ===\n\n";

$db = getMongoDB();
if (!$db) {
    $err = error_get_last();
    fwrite(STDERR, "FATAL: Cannot connect to MongoDB Atlas.\n");
    exit(1);
}

echo "Connected to database: " . DB_NAME . "\n\n";

// Create collections and indexes
$collections = [
    'users' => [
        ['key' => ['email' => 1], 'unique' => true],
        ['key' => ['role' => 1]],
        ['key' => ['status' => 1]],
        ['key' => ['account_number' => 1], 'unique' => true, 'sparse' => true],
        ['key' => ['deleted_at' => 1]],
        ['key' => ['created_at' => -1]]
    ],
    'transactions' => [
        ['key' => ['user_id' => 1, 'date' => -1]],
        ['key' => ['user_id' => 1, 'status' => 1, 'deleted_at' => 1]],
        ['key' => ['status' => 1, 'created_at' => -1]],
        ['key' => ['payment_method' => 1, 'status' => 1]],
        ['key' => ['category' => 1]],
        ['key' => ['deleted_at' => 1]],
        ['key' => ['created_at' => -1]]
    ],
    'login_history' => [
        ['key' => ['email' => 1, 'attempt_time' => -1]],
        ['key' => ['user_id' => 1, 'attempt_time' => -1]],
        ['key' => ['ip_address' => 1]],
        ['key' => ['success' => 1, 'attempt_time' => -1]],
        ['key' => ['attempt_time' => -1]]
    ],
    'activity_logs' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
        ['key' => ['action' => 1, 'created_at' => -1]],
        ['key' => ['created_at' => -1]]
    ],
    'notifications' => [
        ['key' => ['user_id' => 1, 'read' => 1, 'created_at' => -1]],
        ['key' => ['type' => 1, 'created_at' => -1]]
    ],
    'categories' => [
        ['key' => ['user_id' => 1, 'name' => 1]],
        ['key' => ['user_id' => 1, 'type' => 1]]
    ],
    'wallets' => [
        ['key' => ['user_id' => 1]],
        ['key' => ['account_number' => 1], 'unique' => true, 'sparse' => true]
    ],
    'budgets' => [
        ['key' => ['user_id' => 1, 'category' => 1]],
        ['key' => ['user_id' => 1, 'is_active' => 1]]
    ],
    'beneficiaries' => [
        ['key' => ['user_id' => 1, 'account_number' => 1]],
        ['key' => ['user_id' => 1, 'created_at' => -1]]
    ],
    'complaints' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
        ['key' => ['status' => 1, 'created_at' => -1]]
    ],
    'branches' => [
        ['key' => ['branch_code' => 1], 'unique' => true],
        ['key' => ['city' => 1, 'state' => 1]]
    ],
    'appointments' => [
        ['key' => ['user_id' => 1, 'appointment_date' => -1]],
        ['key' => ['status' => 1]]
    ],
    'receipts' => [
        ['key' => ['user_id' => 1, 'created_at' => -1]],
        ['key' => ['transaction_id' => 1]]
    ],
    'otp_verifications' => [
        ['key' => ['user_id' => 1, 'otp_purpose' => 1]],
        ['key' => ['expires_at' => 1], 'expireAfterSeconds' => 0],
        ['key' => ['created_at' => 1]]
    ]
];

foreach ($collections as $name => $indexes) {
    try {
        $collection = $db->selectCollection($name);
        $collection->createIndex(['_id' => 1]); // ensure exists
        echo "[OK] Collection '{$name}' ready\n";
        foreach ($indexes as $index) {
            $idx = $index['key'];
            $options = [];
            if (!empty($index['unique'])) {
                $options['unique'] = true;
            }
            if (!empty($index['sparse'])) {
                $options['sparse'] = true;
            }
            if (!empty($index['expireAfterSeconds'])) {
                $options['expireAfterSeconds'] = $index['expireAfterSeconds'];
            }
            $indexName = $collection->createIndex($idx, $options);
            echo "     Index created: " . json_encode($idx) . ($options ? ' (' . json_encode($options) . ')' : '') . "\n";
        }
    } catch (Exception $e) {
        echo "[ERROR] Collection '{$name}': " . $e->getMessage() . "\n";
    }
}

echo "\n=== Database setup complete ===\n";
?>