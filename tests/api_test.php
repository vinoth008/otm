<?php
// tests/api_test.php
/**
 * Smart Transaction Control - API Test Suite
 * Run with the PHP built-in server running:
 *   php -S 127.0.0.1:8080 -t D:\MPWT router.php
 * Then:  php -d extension=zip tests/api_test.php
 */
$BASE = 'http://127.0.0.1:8080/';
$JAR = sys_get_temp_dir() . '/stc_test_jar.txt';
@unlink($JAR);

$pass = 0;
$fail = 0;
$failures = [];

function req($url, $post = null, $json = true) {
    global $JAR;
    $ch = curl_init($url);
    $headers = [];
    if ($json) $headers[] = 'Content-Type: application/json';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $JAR,
        CURLOPT_COOKIEFILE => $JAR,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = is_string($post) ? $post : json_encode($post);
        if (is_string($post) && !$json) {
            curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $parts = explode("\r\n\r\n", $res, 2);
    $raw = $parts[1] ?? '';
    // Strip UTF-8 BOM (server may emit double BOM)
    while (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    $body = json_decode($raw, true);
    return ['code' => $code, 'headers' => $parts[0] ?? '', 'body' => $body, 'raw' => $raw];
}

function check($name, $ok, $detail = '') {
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  [PASS] $name\n";
    } else {
        $fail++;
        $failures[] = $name;
        echo "  [FAIL] $name" . ($detail ? " -> $detail" : '') . "\n";
    }
}

echo "=== SMART TRANSACTION CONTROL API TESTS ===\n\n";

// 1. Load index to establish session + CSRF
echo "[1] Authentication Flow\n";
$r = req($BASE . 'index.php');
preg_match('/Set-Cookie: csrf_token=([^;]+);/', $r['headers'] ?? '', $m);
$csrf = $m[1] ?? '';
check('Session + CSRF cookie established', !empty($csrf), $r['headers'] ?? '');

$r = req($BASE . 'backend/php/auth.php?action=login', [
    'email' => 'customer1@gmail.com',
    'password' => 'customer@001',
    'csrf_token' => $csrf
]);
check('Login as test user', $r['code'] === 200 && ($r['body']['success'] ?? false), json_encode($r['body']));

$r = req($BASE . 'backend/php/auth.php?action=session_info');
check('Session info', $r['code'] === 200 && ($r['body']['data']['is_logged_in'] ?? false), json_encode($r['body']));

// 2. Transactions
echo "\n[2] Transactions CRUD\n";
$r = req($BASE . 'backend/php/transaction_crud.php?action=summary');
check('Summary', $r['code'] === 200 && ($r['body']['success'] ?? false), json_encode($r['body']));

$r = req($BASE . 'backend/php/transaction_crud.php?action=get_all&limit=5');
check('Get transactions', $r['code'] === 200, $r['raw']);

// 3. Categories
echo "\n[3] Categories\n";
$r = req($BASE . 'backend/php/category_crud.php?action=get_all');
check('Get categories', $r['code'] === 200 && ($r['body']['success'] ?? false), json_encode($r['body']));

$r = req($BASE . 'backend/php/category_crud.php?action=get_all&type=expense');
check('Get expense categories', $r['code'] === 200 && isset($r['body']['data']['categories']), json_encode($r['body']));

// 4. Budgets
echo "\n[4] Budgets\n";
$r = req($BASE . 'backend/php/budget_crud.php?action=get_all');
check('Get budgets', $r['code'] === 200, $r['raw']);

$r = req($BASE . 'backend/php/budget_crud.php?action=get_warnings');
check('Get budget warnings', $r['code'] === 200, $r['raw']);

// 5. Goals
echo "\n[5] Goals\n";
$r = req($BASE . 'backend/php/goals_crud.php?action=get_all');
check('Get goals', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/goals_crud.php?action=summary');
check('Goal summary', $r['code'] === 200, $r['raw']);

// 6. Analytics
echo "\n[6] Analytics\n";
$r = req($BASE . 'backend/php/analytics.php?action=overview&period=all');
check('Analytics overview', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/analytics.php?action=payment_methods&period=all');
check('Payment method breakdown', $r['code'] === 200, $r['raw']);

$r = req($BASE . 'backend/php/analytics.php?action=weekly_pattern&period=all');
check('Weekly pattern', $r['code'] === 200, $r['raw']);

// 7. Write operations
echo "\n[7] Write Operations (create/update/delete)\n";
// Create a transaction
$r = req($BASE . 'backend/php/transaction_crud.php?action=create', [
    'csrf_token' => $csrf,
    'type' => 'expense',
    'category' => 'Food',
    'amount' => 150,
    'description' => 'Test transaction',
    'date' => date('Y-m-d'),
    'payment_method' => 'upi'
]);
check('Create transaction', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

// Create a budget
$r = req($BASE . 'backend/php/budget_crud.php?action=create', [
    'csrf_token' => $csrf,
    'category' => 'Medical',
    'monthly_limit' => 5000,
    'warning_threshold' => 80
]);
check('Create budget', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

// Create a goal
$r = req($BASE . 'backend/php/goals_crud.php?action=create', [
    'name' => 'Test Goal',
    'target_amount' => 10000,
    'current_amount' => 0,
    'icon' => 'fa-car',
    'description' => 'Automated test'
]);
check('Create goal', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

// Create custom category (unique name to allow repeated runs)
$r = req($BASE . 'backend/php/category_crud.php?action=create', [
    'csrf_token' => $csrf,
    'name' => 'Test Category ' . date('His'),
    'type' => 'expense',
    'icon' => 'tag',
    'color' => '#ff0000'
]);
check('Create category', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

// 8. Customer Banking Modules
echo "\n[8] Customer Banking Modules\n";
$r = req($BASE . 'backend/php/wallet_crud.php?action=get_balance');
check('Wallet get_balance', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/wallet_crud.php?action=topup', [
    'csrf_token' => $csrf, 'amount' => 100, 'description' => 'Automated test top-up'
]);
check('Wallet topup', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/wallet_crud.php?action=transfer', [
    'csrf_token' => $csrf, 'recipient_email' => 'staff1@gmail.com', 'amount' => 10, 'description' => 'Automated test transfer'
]);
check('Wallet transfer', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/wallet_crud.php?action=history');
check('Wallet history', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/transfer_crud.php?action=get_all');
check('Transfer list (own)', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/transfer_crud.php?action=summary');
check('Transfer summary (own)', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/transfer_crud.php?action=create', [
    'csrf_token' => $csrf, 'recipient_email' => 'staff1@gmail.com', 'amount' => 25, 'type' => 'internal', 'description' => 'Automated test transfer request'
]);
check('Create transfer request', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/beneficiary_crud.php?action=create', [
    'csrf_token' => $csrf, 'name' => 'Test Beneficiary', 'nickname' => 'AutoTest', 'account_number' => '9876543210', 'bank_name' => 'Test Bank'
]);
check('Create beneficiary', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/beneficiary_crud.php?action=get_all');
$benId = $r['body']['data']['beneficiaries'][0]['beneficiary_id'] ?? '';
check('List beneficiaries', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/beneficiary_crud.php?action=delete', [
    'csrf_token' => $csrf, 'beneficiary_id' => $benId
]);
check('Delete beneficiary', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/notes_crud.php?action=create', [
    'csrf_token' => $csrf, 'title' => 'Automated note', 'content' => 'Created by test suite', 'category' => 'general', 'pinned' => false
]);
check('Create note', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/notes_crud.php?action=get_all');
$noteId = $r['body']['data']['notes'][0]['note_id'] ?? '';
check('List notes', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/notes_crud.php?action=update', [
    'csrf_token' => $csrf, 'note_id' => $noteId, 'content' => 'Updated by test suite'
]);
check('Update note', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/notes_crud.php?action=delete', [
    'csrf_token' => $csrf, 'note_id' => $noteId
]);
check('Delete note', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/complaint_crud.php?action=create', [
    'csrf_token' => $csrf, 'subject' => 'Automated complaint', 'category' => 'General', 'description' => 'Created by test suite', 'priority' => 'low'
]);
check('Create complaint', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/complaint_crud.php?action=get_all');
check('List complaints (own)', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/complaint_crud.php?action=summary');
check('Complaint summary (own)', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/appointment_crud.php?action=branches');
$branchId = $r['body']['data']['branches'][0]['branch_id'] ?? '';
check('Appointment branches', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/appointment_crud.php?action=create', [
    'csrf_token' => $csrf, 'appointment_date' => date('Y-m-d', strtotime('+3 days')), 'appointment_time' => '10:30', 'purpose' => 'General Inquiry', 'branch_id' => $branchId, 'notes' => 'Automated test'
]);
check('Book appointment', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/appointment_crud.php?action=get_all');
check('List appointments (own)', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/receipt_crud.php?action=get_all');
check('List receipts (own)', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/notification_crud.php?action=get_all');
check('List notifications (own)', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/notification_crud.php?action=stats');
check('Notification stats', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/notification_crud.php?action=mark_read', ['csrf_token' => $csrf]);
check('Mark notifications read', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

// 9. Manager Modules (legacy "staff" role replaced by "manager" in 4-role system)
echo "\n[9] Manager Modules\n";
$r = req($BASE . 'backend/php/auth.php?action=login', [
    'email' => 'staff1@gmail.com', 'password' => 'staff@001', 'csrf_token' => $csrf
]);
check('Login as manager', $r['code'] === 200 && ($r['body']['success'] ?? false), json_encode($r['body']));

$r = req($BASE . 'backend/php/transfer_crud.php?action=all');
check('Manager: all transfers', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/complaint_crud.php?action=get_all');
check('Manager: all complaints', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/complaint_crud.php?action=summary');
check('Manager: complaint summary', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/appointment_crud.php?action=get_all');
check('Manager: all appointments', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/admin_crud.php?action=get_users&role=user');
check('Manager: employee list', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);
$custId = '';
foreach (($r['body']['data']['users'] ?? []) as $u) {
    if ($u['email'] === 'customer1@gmail.com') { $custId = $u['user_id']; break; }
}

$r = req($BASE . 'backend/php/expense_crud.php?action=create', [
    'csrf_token' => $csrf, 'category' => 'Operations', 'description' => 'Automated test expense', 'amount' => 50
]);
check('Manager: create expense', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/expense_crud.php?action=get_all');
check('Manager: list expenses', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/receipt_crud.php?action=create', [
    'csrf_token' => $csrf, 'user_id' => $custId, 'amount' => 5, 'payment_method' => 'cash', 'description' => 'Automated test receipt'
]);
check('Manager: create receipt', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/receipt_crud.php?action=get_all');
check('Manager: list receipts', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

// 10. Admin Modules
echo "\n[10] Admin Modules\n";
$r = req($BASE . 'backend/php/auth.php?action=login', [
    'email' => 'admin1@gmail.com', 'password' => 'admin@001', 'csrf_token' => $csrf
]);
check('Login as admin', $r['code'] === 200 && ($r['body']['success'] ?? false), json_encode($r['body']));

$r = req($BASE . 'backend/php/admin_crud.php?action=get_users');
check('Admin: list users', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/admin_crud.php?action=get_roles');
check('Admin: list roles', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/admin_crud.php?action=get_stats');
check('Admin: dashboard stats', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/transaction_crud.php?action=admin_all&limit=5');
check('Admin: all transactions', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/branch_crud.php?action=get_all');
check('Admin: list branches', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/audit_crud.php?action=get_logs');
check('Admin: audit logs', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/audit_crud.php?action=get_actions');
check('Admin: audit actions', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/report_crud.php?action=transactions&from_date=' . date('Y-m-d', strtotime('-30 days')) . '&to_date=' . date('Y-m-d'));
check('Admin: transaction report', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/report_crud.php?action=transfers');
check('Admin: transfer report', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/report_crud.php?action=complaints');
check('Admin: complaint report', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/notification_crud.php?action=send', [
    'csrf_token' => $csrf, 'title' => 'Automated broadcast', 'message' => 'Sent by the automated test suite', 'type' => 'system'
]);
check('Admin: send notification', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

$r = req($BASE . 'backend/php/expense_crud.php?action=get_all');
check('Admin: list expenses', $r['code'] === 200 && ($r['body']['success'] ?? false), $r['raw']);

// 11. Logout
echo "\n[11] Logout\n";
$r = req($BASE . 'backend/php/auth.php?action=logout', []);
check('Logout', $r['code'] === 200, $r['raw']);

echo "\n========================================\n";
echo "RESULTS: $pass passed, $fail failed\n";
if ($failures) {
    echo "FAILED:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
exit($fail > 0 ? 1 : 0);
?>
