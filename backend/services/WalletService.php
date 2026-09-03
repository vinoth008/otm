<?php
declare(strict_types=1);

/**
 * WalletService.php — Wallet management using MongoDB
 */

require_once __DIR__ . '/../config/database.php';

class WalletService
{
    private const COLLECTION = 'wallets';

    /** Get all wallets for a user */
    public static function getUserWallets(string $userId): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $cursor = $collection->find([
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'deleted_at' => null,
            ], ['sort' => ['created_at' => 1]]);

            $wallets = [];
            foreach ($cursor as $doc) {
                $wallets[] = self::format($doc);
            }
            return $wallets;
        } catch (\Exception $e) {
            error_log('[WalletService] getUserWallets: ' . $e->getMessage());
            return [];
        }
    }

    /** Get a single wallet by ID */
    public static function getById(string $walletId, string $userId): ?array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return null;
            $doc = $collection->findOne([
                '_id'        => new MongoDB\BSON\ObjectId($walletId),
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'deleted_at' => null,
            ]);
            return $doc ? self::format($doc) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Create a new wallet */
    public static function create(string $userId, string $name, float $initialBalance = 0.0, string $currency = 'INR'): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return ['ok' => false, 'message' => 'DB error'];

            $count = (int)$collection->countDocuments(['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null]);
            if ($count >= 10) return ['ok' => false, 'message' => 'Maximum 10 wallets allowed'];

            $result = $collection->insertOne([
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'name'       => trim($name),
                'balance'    => $initialBalance,
                'currency'   => strtoupper($currency),
                'color'      => '#' . substr(md5($name . time()), 0, 6),
                'is_default' => $count === 0,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
                'deleted_at' => null,
            ]);

            return ['ok' => true, 'wallet_id' => (string)$result->getInsertedId(), 'message' => 'Wallet created'];
        } catch (\Exception $e) {
            error_log('[WalletService] create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Failed to create wallet'];
        }
    }

    /** Update wallet name/currency */
    public static function update(string $walletId, string $userId, array $fields): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;

            $set = ['updated_at' => new MongoDB\BSON\UTCDateTime()];
            if (!empty($fields['name']))     $set['name']     = trim($fields['name']);
            if (!empty($fields['currency'])) $set['currency'] = strtoupper($fields['currency']);
            if (!empty($fields['color']))    $set['color']    = $fields['color'];

            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($walletId), 'user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null],
                ['$set' => $set]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Adjust wallet balance (internal — called by TransactionService) */
    public static function adjustBalance(string $walletId, float $delta): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;

            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($walletId), 'deleted_at' => null],
                [
                    '$inc' => ['balance' => $delta],
                    '$set' => ['updated_at' => new MongoDB\BSON\UTCDateTime()],
                ]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            error_log('[WalletService] adjustBalance: ' . $e->getMessage());
            return false;
        }
    }

    /** Transfer between two wallets */
    public static function transfer(string $fromId, string $toId, string $userId, float $amount): array
    {
        if ($amount <= 0) return ['ok' => false, 'message' => 'Amount must be positive'];
        if ($fromId === $toId) return ['ok' => false, 'message' => 'Cannot transfer to the same wallet'];

        $from = self::getById($fromId, $userId);
        $to   = self::getById($toId, $userId);

        if (!$from) return ['ok' => false, 'message' => 'Source wallet not found'];
        if (!$to)   return ['ok' => false, 'message' => 'Destination wallet not found'];
        if ($from['balance'] < $amount) return ['ok' => false, 'message' => 'Insufficient balance'];

        $ok1 = self::adjustBalance($fromId, -$amount);
        $ok2 = self::adjustBalance($toId, $amount);

        if (!$ok1 || !$ok2) return ['ok' => false, 'message' => 'Transfer failed'];

        return ['ok' => true, 'message' => "Transferred ₹{$amount} from {$from['name']} to {$to['name']}"];
    }

    /** Soft-delete a wallet */
    public static function delete(string $walletId, string $userId): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return ['ok' => false, 'message' => 'DB error'];

            $wallet = $collection->findOne([
                '_id'     => new MongoDB\BSON\ObjectId($walletId),
                'user_id' => new MongoDB\BSON\ObjectId($userId),
                'deleted_at' => null,
            ]);
            if (!$wallet) return ['ok' => false, 'message' => 'Wallet not found'];
            if ((bool)($wallet['is_default'] ?? false)) return ['ok' => false, 'message' => 'Cannot delete default wallet'];

            $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($walletId)],
                ['$set' => ['deleted_at' => new MongoDB\BSON\UTCDateTime()]]
            );
            return ['ok' => true, 'message' => 'Wallet deleted'];
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Get total balance across all wallets */
    public static function getTotalBalance(string $userId): float
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return 0.0;

            $pipeline = [
                ['$match' => ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null]],
                ['$group' => ['_id' => null, 'total' => ['$sum' => '$balance']]],
            ];
            $result = $collection->aggregate($pipeline)->toArray();
            return isset($result[0]) ? (float)$result[0]['total'] : 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    private static function format($doc): array
    {
        return [
            'id'         => (string)$doc['_id'],
            'name'       => $doc['name'] ?? 'Wallet',
            'balance'    => (float)($doc['balance'] ?? 0),
            'currency'   => $doc['currency'] ?? 'INR',
            'color'      => $doc['color'] ?? '#6366f1',
            'is_default' => (bool)($doc['is_default'] ?? false),
            'created_at' => isset($doc['created_at']) ? $doc['created_at']->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }
}
