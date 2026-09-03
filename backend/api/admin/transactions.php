<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

requireRole(['admin']);

$data = getRequestData();
$action = $data['action'] ?? ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'get_all';
}

$collection = getCollection('transactions');
if (!$collection) {
    errorResponse('Database connection error');
}

switch ($action) {
    case 'get_all':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $skip = ($page - 1) * $limit;
        $filter = [];
        $userIdFilter = trim($_GET['user_id'] ?? '');
        if (isValidObjectId($userIdFilter)) {
            $filter['user_id'] = new MongoDB\BSON\ObjectId($userIdFilter);
        }
        $type = trim($_GET['type'] ?? '');
        if (in_array($type, ['income', 'expense'], true)) {
            $filter['type'] = $type;
        }
        $fromDate = trim($_GET['from_date'] ?? '');
        $toDate = trim($_GET['to_date'] ?? '');
        if (!empty($fromDate)) {
            $filter['created_at'] = ['$gte' => phpDateToMongo($fromDate . ' 00:00:00')];
        }
        if (!empty($toDate)) {
            $toFilter = ['$lte' => phpDateToMongo($toDate . ' 23:59:59')];
            if (isset($filter['created_at'])) {
                $filter['created_at'] += $toFilter;
            } else {
                $filter['created_at'] = $toFilter;
            }
        }
        $total = $collection->countDocuments($filter);
        $cursor = $collection->find($filter, [
            'sort' => ['created_at' => -1],
            'skip' => $skip,
            'limit' => $limit
        ]);
        $txs = [];
        $usersCache = [];
        $userCollection = getCollection('users');
        foreach ($cursor as $tx) {
            $uid = isset($tx['user_id']) ? (string)$tx['user_id'] : null;
            $userName = '';
            if ($uid && $userCollection) {
                if (!isset($usersCache[$uid])) {
                    $u = $userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($uid)]);
                    $usersCache[$uid] = $u ? (($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) : '';
                }
                $userName = $usersCache[$uid];
            }
            $txs[] = [
                'transaction_id' => (string)$tx['_id'],
                'user_id' => $uid,
                'userName' => $userName,
                'type' => $tx['type'] ?? '',
                'amount' => $tx['amount'] ?? 0,
                'category' => $tx['category'] ?? '',
                'description' => $tx['description'] ?? '',
                'payment_method' => $tx['payment_method'] ?? '',
                'date' => isset($tx['date']) ? mongoDateToPHP($tx['date'])->format('Y-m-d H:i:s') : '',
                'notes' => $tx['notes'] ?? '',
                'created_at' => isset($tx['created_at']) ? mongoDateToPHP($tx['created_at'])->format('Y-m-d H:i:s') : ''
            ];
        }
        $incomeTotal = 0;
        $expenseTotal = 0;
        $incomeAgg = $collection->aggregate([
            ['$match' => ['type' => 'income']],
            ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
        ])->toArray();
        if (isset($incomeAgg[0]['total'])) {
            $incomeTotal = $incomeAgg[0]['total'];
        }
        $expenseAgg = $collection->aggregate([
            ['$match' => ['type' => 'expense']],
            ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]]
        ])->toArray();
        if (isset($expenseAgg[0]['total'])) {
            $expenseTotal = $expenseAgg[0]['total'];
        }
        successResponse([
            'transactions' => $txs,
            'summary' => [
                'total_transactions' => $collection->countDocuments($filter),
                'total_income' => $incomeTotal,
                'total_expense' => $expenseTotal
            ],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($total / $limit),
                'total_count' => $total,
                'limit' => $limit
            ]
        ], 'Transactions retrieved');
        break;

    default:
        errorResponse('Invalid action', 400);
}
