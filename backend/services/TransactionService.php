<?php
declare(strict_types=1);

/**
 * TransactionService.php — Financial transaction management using MongoDB
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../services/NotificationService.php';

class TransactionService
{
    private const COLLECTION = 'transactions';

    /** Create a new transaction and update wallet balance */
    public static function create(string $userId, array $data): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return ['ok' => false, 'message' => 'DB error'];

            $type      = $data['type'] ?? 'expense'; // income | expense | transfer
            $amount    = (float)($data['amount'] ?? 0);
            $walletId  = $data['wallet_id'] ?? '';
            $category  = $data['category'] ?? 'Uncategorized';
            $note      = trim($data['note'] ?? '');
            $txDate    = $data['date'] ?? date('Y-m-d');
            $tags      = (array)($data['tags'] ?? []);

            if ($amount <= 0) return ['ok' => false, 'message' => 'Amount must be positive'];
            if (empty($walletId)) return ['ok' => false, 'message' => 'Wallet is required'];
            if (!in_array($type, ['income', 'expense', 'transfer'], true)) return ['ok' => false, 'message' => 'Invalid transaction type'];

            // Verify wallet belongs to user
            $wallet = WalletService::getById($walletId, $userId);
            if (!$wallet) return ['ok' => false, 'message' => 'Wallet not found'];

            // Check sufficient balance for expenses
            if ($type === 'expense' && $wallet['balance'] < $amount) {
                return ['ok' => false, 'message' => 'Insufficient wallet balance'];
            }

            $txData = [
                'user_id'     => new MongoDB\BSON\ObjectId($userId),
                'wallet_id'   => new MongoDB\BSON\ObjectId($walletId),
                'type'        => $type,
                'amount'      => $amount,
                'category'    => $category,
                'note'        => $note,
                'tags'        => $tags,
                'date'        => $txDate,
                'status'      => 'completed',
                'reference'   => self::generateRef(),
                'created_at'  => new MongoDB\BSON\UTCDateTime(),
                'updated_at'  => new MongoDB\BSON\UTCDateTime(),
                'deleted_at'  => null,
            ];

            // Handle transfer
            if ($type === 'transfer') {
                $toWalletId = $data['to_wallet_id'] ?? '';
                if (empty($toWalletId) || $toWalletId === $walletId) return ['ok' => false, 'message' => 'Invalid destination wallet'];
                $txData['to_wallet_id'] = new MongoDB\BSON\ObjectId($toWalletId);
                WalletService::adjustBalance($walletId, -$amount);
                WalletService::adjustBalance($toWalletId, $amount);
            } else {
                $delta = $type === 'income' ? $amount : -$amount;
                WalletService::adjustBalance($walletId, $delta);
            }

            $result  = $collection->insertOne($txData);
            $txId    = (string)$result->getInsertedId();

            // Notification
            $label = ucfirst($type);
            NotificationService::create($userId, 'transaction', "{$label} Recorded", "₹{$amount} {$type} — {$category}", ['tx_id' => $txId]);

            return ['ok' => true, 'transaction_id' => $txId, 'message' => 'Transaction created'];
        } catch (\Exception $e) {
            error_log('[TransactionService] create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Failed to create transaction'];
        }
    }

    /** List transactions for a user with filters */
    public static function list(string $userId, array $filters = [], int $limit = 50, int $skip = 0): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $match = ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null];
            if (!empty($filters['type']))      $match['type']     = $filters['type'];
            if (!empty($filters['category']))  $match['category'] = $filters['category'];
            if (!empty($filters['wallet_id'])) $match['wallet_id'] = new MongoDB\BSON\ObjectId($filters['wallet_id']);
            if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
                $dateCond = [];
                if (!empty($filters['date_from'])) $dateCond['$gte'] = $filters['date_from'];
                if (!empty($filters['date_to']))   $dateCond['$lte'] = $filters['date_to'];
                $match['date'] = $dateCond;
            }

            $cursor = $collection->find($match, ['sort' => ['created_at' => -1], 'limit' => $limit, 'skip' => $skip]);
            $txns   = [];
            foreach ($cursor as $doc) {
                $txns[] = self::format($doc);
            }
            return $txns;
        } catch (\Exception $e) {
            error_log('[TransactionService] list: ' . $e->getMessage());
            return [];
        }
    }

    /** Count matching transactions */
    public static function count(string $userId, array $filters = []): int
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return 0;
            $match = ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null];
            if (!empty($filters['type'])) $match['type'] = $filters['type'];
            return (int)$collection->countDocuments($match);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /** Get a single transaction */
    public static function getById(string $txId, string $userId): ?array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return null;
            $doc = $collection->findOne([
                '_id'        => new MongoDB\BSON\ObjectId($txId),
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'deleted_at' => null,
            ]);
            return $doc ? self::format($doc) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Update a transaction note/category */
    public static function update(string $txId, string $userId, array $fields): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;

            $set = ['updated_at' => new MongoDB\BSON\UTCDateTime()];
            if (isset($fields['note']))     $set['note']     = trim($fields['note']);
            if (isset($fields['category'])) $set['category'] = $fields['category'];
            if (isset($fields['tags']))     $set['tags']     = (array)$fields['tags'];

            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($txId), 'user_id' => new MongoDB\BSON\ObjectId($userId)],
                ['$set' => $set]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Soft-delete a transaction (balance is NOT reversed — log only) */
    public static function delete(string $txId, string $userId): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;
            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($txId), 'user_id' => new MongoDB\BSON\ObjectId($userId)],
                ['$set' => ['deleted_at' => new MongoDB\BSON\UTCDateTime(), 'status' => 'cancelled']]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Monthly income vs expense summary */
    public static function monthlySummary(string $userId, int $months = 6): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $since = date('Y-m-d', strtotime("-{$months} months"));
            $pipeline = [
                ['$match' => ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null, 'date' => ['$gte' => $since]]],
                ['$group' => [
                    '_id'     => ['month' => ['$substr' => ['$date', 0, 7]], 'type' => '$type'],
                    'total'   => ['$sum' => '$amount'],
                    'count'   => ['$sum' => 1],
                ]],
                ['$sort' => ['_id.month' => 1]],
            ];
            return $collection->aggregate($pipeline)->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /** Category breakdown */
    public static function categoryBreakdown(string $userId, string $type = 'expense', string $period = 'month'): array
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
                ['$match' => ['user_id' => new MongoDB\BSON\ObjectId($userId), 'type' => $type, 'deleted_at' => null, 'date' => ['$gte' => $since]]],
                ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount'], 'count' => ['$sum' => 1]]],
                ['$sort'  => ['total' => -1]],
            ];

            $result = [];
            foreach ($collection->aggregate($pipeline)->toArray() as $doc) {
                $result[] = ['category' => (string)$doc['_id'], 'total' => (float)$doc['total'], 'count' => (int)$doc['count']];
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function generateRef(): string
    {
        return 'TXN' . strtoupper(substr(uniqid('', true), -8));
    }

    private static function format($doc): array
    {
        return [
            'id'          => (string)$doc['_id'],
            'wallet_id'   => isset($doc['wallet_id']) ? (string)$doc['wallet_id'] : null,
            'type'        => $doc['type'] ?? 'expense',
            'amount'      => (float)($doc['amount'] ?? 0),
            'category'    => $doc['category'] ?? '',
            'note'        => $doc['note'] ?? '',
            'tags'        => (array)($doc['tags'] ?? []),
            'date'        => $doc['date'] ?? '',
            'status'      => $doc['status'] ?? 'completed',
            'reference'   => $doc['reference'] ?? '',
            'created_at'  => isset($doc['created_at']) ? $doc['created_at']->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }
}
