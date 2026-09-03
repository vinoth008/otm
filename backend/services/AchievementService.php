<?php
declare(strict_types=1);

/**
 * AchievementService.php ??? Achievements for Smart Transaction Control
 *
 * Achievements are stored in the `achievements` collection:
 *   {
 *     _id, user_id (ObjectId), achievement_type (string unique per user),
 *     title, description, points, icon, is_claimed, unlocked_at, deleted_at
 *   }
 *
 * Usage:
 *   AchievementService::getUserAchievements($userId)     // unlocked list
 *   AchievementService::getAllWithStatus($userId)        // catalog + unlocked state
 *   AchievementService::checkAndUnlock($userId, $context) // evaluate+unlock
 */

require_once __DIR__ . '/../config/database.php';

class AchievementService
{
    private const COLLECTION = 'achievements';

    /** Self-contained ObjectId validity check (no router dependency). */
    private static function validObjectId($id): bool
    {
        return preg_match('/^[a-f0-9]{24}$/i', (string)$id) === 1;
    }

    /** Catalog of all achievements and their unlock conditions. */
    public static function catalog(): array
    {
        return [
            [
                'type' => 'welcome',
                'title' => 'Welcome Aboard',
                'description' => 'Create your account and start your journey.',
                'points' => 10,
                'icon' => '????',
            ],
            [
                'type' => 'first_transaction',
                'title' => 'First Steps',
                'description' => 'Record your first transaction.',
                'points' => 20,
                'icon' => '????',
            ],
            [
                'type' => 'five_transactions',
                'title' => 'On a Roll',
                'description' => 'Record 5 transactions.',
                'points' => 50,
                'icon' => '????',
            ],
            [
                'type' => 'first_income',
                'title' => 'Money In',
                'description' => 'Add your first income transaction.',
                'points' => 20,
                'icon' => '????',
            ],
            [
                'type' => 'first_savings',
                'title' => 'Wise Saver',
                'description' => 'Reach a wallet balance of INR 100,000.',
                'points' => 100,
                'icon' => '????',
            ],
            [
                'type' => 'login_streak_3',
                'title' => 'Regular',
                'description' => 'Log in on 3 different days.',
                'points' => 30,
                'icon' => '????',
            ],
            [
                'type' => 'email_verified',
                'title' => 'Verified',
                'description' => 'Verify your email address.',
                'points' => 15,
                'icon' => '????',
            ],
            [
                'type' => 'first_wallet',
                'title' => 'Wallet Owner',
                'description' => 'Create your first wallet.',
                'points' => 15,
                'icon' => '????',
            ],
        ];
    }

    /**
     * Unlock an achievement for a user (idempotent ??? duplicate type ignored).
     */
    public static function unlock(string $userId, string $type): bool
    {
        if (!self::validObjectId($userId)) {
            return false;
        }
        $achievement = null;
        foreach (self::catalog() as $item) {
            if ($item['type'] === $type) {
                $achievement = $item;
                break;
            }
        }
        if (!$achievement) {
            return false;
        }
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) {
                return false;
            }
            $collection->updateOne(
                [
                    'user_id' => new MongoDB\BSON\ObjectId($userId),
                    'achievement_type' => $type,
                ],
                [
                    '$setOnInsert' => [
                        'user_id' => new MongoDB\BSON\ObjectId($userId),
                        'achievement_type' => $type,
                        'title' => $achievement['title'],
                        'description' => $achievement['description'],
                        'points' => $achievement['points'],
                        'icon' => $achievement['icon'],
                        'is_claimed' => false,
                        'unlocked_at' => new MongoDB\BSON\UTCDateTime(),
                        'deleted_at' => null,
                    ],
                ],
                ['upsert' => true]
            );
            return true;
        } catch (\Throwable $e) {
            error_log('[AchievementService] unlock: ' . $e->getMessage());
            return false;
        }
    }

    /** Return the user's unlocked achievements (formatted array). */
    public static function getUserAchievements(string $userId): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection || !self::validObjectId($userId)) {
                return [];
            }
            $cursor = $collection->find(
                ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null],
                ['sort' => ['unlocked_at' => -1]]
            );
            $out = [];
            foreach ($cursor as $doc) {
                $out[] = [
                    'achievement_type' => $doc['achievement_type'],
                    'title' => $doc['title'],
                    'description' => $doc['description'],
                    'points' => (int)($doc['points'] ?? 0),
                    'icon' => $doc['icon'] ?? '',
                    'is_claimed' => (bool)($doc['is_claimed'] ?? false),
                    'unlocked_at' => isset($doc['unlocked_at'])
                        ? $doc['unlocked_at']->toDateTime()->format('Y-m-d H:i:s')
                        : null,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[AchievementService] getUserAchievements: ' . $e->getMessage());
            return [];
        }
    }

    /** Return the full catalog with per-user unlocked state. */
    public static function getAllWithStatus(string $userId): array
    {
        $unlocked = self::getUserAchievements($userId);
        $unlockedTypes = array_column($unlocked, 'achievement_type');
        $catalog = self::catalog();

        $result = [];
        foreach ($catalog as $item) {
            $result[] = [
                'achievement_type' => $item['type'],
                'title' => $item['title'],
                'description' => $item['description'],
                'points' => $item['points'],
                'icon' => $item['icon'],
                'unlocked' => in_array($item['type'], $unlockedTypes, true),
                'unlocked_at' => null,
            ];
        }
        // Attach unlock times where present
        $unlockedByIdx = [];
        foreach ($unlocked as $u) {
            $unlockedByIdx[$u['achievement_type']] = $u;
        }
        foreach ($result as &$row) {
            if (isset($unlockedByIdx[$row['achievement_type']])) {
                $row['unlocked_at'] = $unlockedByIdx[$row['achievement_type']]['unlocked_at'];
            }
        }
        unset($row);

        return [
            'achievements' => $result,
            'total_earned' => array_sum(array_column($unlocked, 'points')),
            'unlocked_count' => count($unlocked),
            'total_achievements' => count($catalog),
        ];
    }

    /**
     * Evaluate conditions and unlock any that pass for the user.
     * $context may carry: 'transaction_count', 'has_income', 'wallet_balance',
     *                     'login_days', 'email_verified', 'has_wallet'.
     */
    public static function checkAndUnlock(string $userId, array $context = []): array
    {
        if (!self::validObjectId($userId)) {
            return [];
        }

        $unlockedNow = [];

        // Derived from DB when not explicitly supplied.
        $txnCount = $context['transaction_count'] ?? self::transactionCount($userId);
        $hasIncome = $context['has_income'] ?? self::hasIncome($userId);
        $balance = $context['wallet_balance'] ?? self::maxWalletBalance($userId);
        $loginDays = $context['login_days'] ?? self::loginDays($userId);
        $emailVerified = (bool)($context['email_verified'] ?? self::isEmailVerified($userId));
        $hasWallet = $context['has_wallet'] ?? ($balance > 0 || count(self::userWallets($userId)) > 0);

        // Unlock any newly earned achievements, collect the ones that just fired.
        $already = array_column(self::getUserAchievements($userId), 'achievement_type');

        if (!in_array('welcome', $already, true)) {
            self::unlock($userId, 'welcome');
            $unlockedNow[] = 'welcome';
        }
        if ($txnCount >= 1 && !in_array('first_transaction', $already, true)) {
            self::unlock($userId, 'first_transaction');
            $unlockedNow[] = 'first_transaction';
        }
        if ($txnCount >= 5 && !in_array('five_transactions', $already, true)) {
            self::unlock($userId, 'five_transactions');
            $unlockedNow[] = 'five_transactions';
        }
        if ($hasIncome && !in_array('first_income', $already, true)) {
            self::unlock($userId, 'first_income');
            $unlockedNow[] = 'first_income';
        }
        if ($balance >= 100000 && !in_array('first_savings', $already, true)) {
            self::unlock($userId, 'first_savings');
            $unlockedNow[] = 'first_savings';
        }
        if ($loginDays >= 3 && !in_array('login_streak_3', $already, true)) {
            self::unlock($userId, 'login_streak_3');
            $unlockedNow[] = 'login_streak_3';
        }
        if ($emailVerified && !in_array('email_verified', $already, true)) {
            self::unlock($userId, 'email_verified');
            $unlockedNow[] = 'email_verified';
        }
        if ($hasWallet && !in_array('first_wallet', $already, true)) {
            self::unlock($userId, 'first_wallet');
            $unlockedNow[] = 'first_wallet';
        }

        return $unlockedNow;
    }

    /* ---------- private helpers ---------- */

    private static function transactionCount(string $userId): int
    {
        try {
            $c = getCollection('transactions');
            if (!$c || !self::validObjectId($userId)) return 0;
            return (int)$c->countDocuments(['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function hasIncome(string $userId): bool
    {
        try {
            $c = getCollection('transactions');
            if (!$c || !self::validObjectId($userId)) return false;
            return $c->countDocuments(['user_id' => new MongoDB\BSON\ObjectId($userId), 'type' => 'income', 'deleted_at' => null]) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function userWallets(string $userId): array
    {
        try {
            $c = getCollection('wallets');
            if (!$c || !self::validObjectId($userId)) return [];
            return $c->find(['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function maxWalletBalance(string $userId): float
    {
        try {
            $wallets = self::userWallets($userId);
            $max = 0.0;
            foreach ($wallets as $w) {
                $max = max($max, (float)($w['balance'] ?? 0));
            }
            return $max;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private static function loginDays(string $userId): int
    {
        try {
            $c = getCollection('activity_logs');
            if (!$c || !self::validObjectId($userId)) return 0;
            $cursor = $c->find(
                ['user_id' => new MongoDB\BSON\ObjectId($userId), 'action' => 'login'],
                ['projection' => ['_id' => 1, 'created_at' => 1]]
            );
            $days = [];
            foreach ($cursor as $doc) {
                if (isset($doc['created_at']) && $doc['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
                    $d = $doc['created_at']->toDateTime()->format('Y-m-d');
                    $days[$d] = true;
                }
            }
            return count($days);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function isEmailVerified(string $userId): bool
    {
        try {
            $c = getCollection('users');
            if (!$c || !self::validObjectId($userId)) return false;
            $u = $c->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
            return (bool)($u['is_verified'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
