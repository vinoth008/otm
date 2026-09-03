<?php
declare(strict_types=1);

/**
 * ExpenseService.php — Expense management using MongoDB
 */

require_once __DIR__ . '/../config/database.php';

class ExpenseService
{
    private const COLLECTION = 'expenses';

    /** Create a new expense */
    public static function create(string $userId, array $data): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return ['ok' => false, 'message' => 'DB error'];

            $amount      = (float)($data['amount'] ?? 0);
            $category    = trim($data['category'] ?? '');
            $description = trim($data['description'] ?? '');
            $date        = $data['date'] ?? date('Y-m-d');

            if ($amount <= 0) return ['ok' => false, 'message' => 'Amount must be positive'];
            if (empty($category)) return ['ok' => false, 'message' => 'Category is required'];

            $result = $collection->insertOne([
                'user_id'     => new MongoDB\BSON\ObjectId($userId),
                'amount'      => $amount,
                'category'    => $category,
                'description' => $description,
                'date'        => $date,
                'receipt_url' => $data['receipt_url'] ?? null,
                'tags'        => (array)($data['tags'] ?? []),
                'is_recurring'=> (bool)($data['is_recurring'] ?? false),
                'created_at'  => new MongoDB\BSON\UTCDateTime(),
                'updated_at'  => new MongoDB\BSON\UTCDateTime(),
                'deleted_at'  => null,
            ]);

            return ['ok' => true, 'expense_id' => (string)$result->getInsertedId(), 'message' => 'Expense recorded'];
        } catch (\Exception $e) {
            error_log('[ExpenseService] create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Failed to create expense'];
        }
    }

    /** List expenses for a user */
    public static function list(string $userId, array $filters = [], int $limit = 50, int $skip = 0): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $match = ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null];
            if (!empty($filters['category'])) $match['category'] = $filters['category'];
            if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
                $dateCond = [];
                if (!empty($filters['date_from'])) $dateCond['$gte'] = $filters['date_from'];
                if (!empty($filters['date_to']))   $dateCond['$lte'] = $filters['date_to'];
                $match['date'] = $dateCond;
            }

            $cursor = $collection->find($match, ['sort' => ['date' => -1, 'created_at' => -1], 'limit' => $limit, 'skip' => $skip]);
            $expenses = [];
            foreach ($cursor as $doc) {
                $expenses[] = self::format($doc);
            }
            return $expenses;
        } catch (\Exception $e) {
            return [];
        }
    }

    /** Update an expense */
    public static function update(string $expenseId, string $userId, array $data): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;

            $set = ['updated_at' => new MongoDB\BSON\UTCDateTime()];
            if (isset($data['amount']))      $set['amount']      = (float)$data['amount'];
            if (isset($data['category']))    $set['category']    = trim($data['category']);
            if (isset($data['description'])) $set['description'] = trim($data['description']);
            if (isset($data['date']))        $set['date']        = $data['date'];

            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($expenseId), 'user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null],
                ['$set' => $set]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Soft-delete an expense */
    public static function delete(string $expenseId, string $userId): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;
            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($expenseId), 'user_id' => new MongoDB\BSON\ObjectId($userId)],
                ['$set' => ['deleted_at' => new MongoDB\BSON\UTCDateTime()]]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Monthly spending total */
    public static function monthlyTotal(string $userId, string $month = null): float
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return 0.0;

            $monthStr = $month ?? date('Y-m');
            $pipeline = [
                ['$match' => [
                    'user_id'    => new MongoDB\BSON\ObjectId($userId),
                    'deleted_at' => null,
                    'date'       => ['$regex' => "^{$monthStr}"],
                ]],
                ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]],
            ];
            $result = $collection->aggregate($pipeline)->toArray();
            return isset($result[0]) ? (float)$result[0]['total'] : 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /** Category breakdown for a period */
    public static function categoryStats(string $userId, string $period = 'month'): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $since = match ($period) {
                'week'  => date('Y-m-d', strtotime('-7 days')),
                'month' => date('Y-m-01'),
                'year'  => date('Y-01-01'),
                default => date('Y-m-01'),
            };

            $pipeline = [
                ['$match' => ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null, 'date' => ['$gte' => $since]]],
                ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]],
                ['$sort'  => ['total' => -1]],
            ];

            $stats = [];
            foreach ($collection->aggregate($pipeline)->toArray() as $doc) {
                $stats[] = ['category' => (string)$doc['_id'], 'total' => (float)$doc['total'], 'count' => (int)$doc['count']];
            }
            return $stats;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function format($doc): array
    {
        return [
            'id'          => (string)$doc['_id'],
            'amount'      => (float)($doc['amount'] ?? 0),
            'category'    => $doc['category'] ?? '',
            'description' => $doc['description'] ?? '',
            'date'        => $doc['date'] ?? '',
            'tags'        => (array)($doc['tags'] ?? []),
            'is_recurring'=> (bool)($doc['is_recurring'] ?? false),
            'receipt_url' => $doc['receipt_url'] ?? null,
            'created_at'  => isset($doc['created_at']) ? $doc['created_at']->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }
}
