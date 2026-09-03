<?php
declare(strict_types=1);

/**
 * UserService.php — User profile management with MongoDB
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

class UserService
{
    private const COLLECTION = 'users';

    /** Find a user by their MongoDB _id string */
    public static function findById(string $id): ?array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return null;
            $doc = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id), 'deleted_at' => null]);
            return $doc ? self::format($doc) : null;
        } catch (\Exception $e) {
            error_log('[UserService] findById: ' . $e->getMessage());
            return null;
        }
    }

    /** Find a user by email */
    public static function findByEmail(string $email): ?array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return null;
            $doc = $collection->findOne(['email' => $email, 'deleted_at' => null]);
            return $doc ? self::format($doc) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** List users with optional filter, pagination */
    public static function list(array $filter = [], int $limit = 50, int $skip = 0, string $sort = 'created_at'): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $baseFilter = array_merge(['deleted_at' => null], $filter);
            $cursor = $collection->find(
                $baseFilter,
                ['sort' => [$sort => -1], 'limit' => $limit, 'skip' => $skip]
            );

            $users = [];
            foreach ($cursor as $doc) {
                $users[] = self::format($doc);
            }
            return $users;
        } catch (\Exception $e) {
            error_log('[UserService] list: ' . $e->getMessage());
            return [];
        }
    }

    /** Count users */
    public static function count(array $filter = []): int
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return 0;
            return (int)$collection->countDocuments(array_merge(['deleted_at' => null], $filter));
        } catch (\Exception $e) {
            return 0;
        }
    }

    /** Update user profile fields */
    public static function update(string $id, array $fields): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;

            $allowed = ['first_name', 'last_name', 'phone', 'avatar', 'address', 'date_of_birth'];
            $set = ['updated_at' => new MongoDB\BSON\UTCDateTime()];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $fields)) {
                    $set[$key] = is_string($fields[$key]) ? trim($fields[$key]) : $fields[$key];
                }
            }

            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($id)],
                ['$set' => $set]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            error_log('[UserService] update: ' . $e->getMessage());
            return false;
        }
    }

    /** Change user status (admin action) */
    public static function setStatus(string $id, string $status): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;
            $allowed = ['active', 'suspended', 'banned', 'pending'];
            if (!in_array($status, $allowed, true)) return false;

            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($id)],
                ['$set' => ['status' => $status, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            error_log('[UserService] setStatus: ' . $e->getMessage());
            return false;
        }
    }

    /** Change user password */
    public static function changePassword(string $id, string $currentPass, string $newPass): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return ['ok' => false, 'message' => 'DB error'];

            $user = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
            if (!$user) return ['ok' => false, 'message' => 'User not found'];

            $hash = $user['password_hash'] ?? ($user['password'] ?? '');
            if (!password_verify($currentPass, $hash)) {
                return ['ok' => false, 'message' => 'Current password is incorrect'];
            }
            if (strlen($newPass) < PASSWORD_MIN_LENGTH) {
                return ['ok' => false, 'message' => 'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'];
            }

            $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($id)],
                ['$set' => ['password_hash' => password_hash($newPass, PASSWORD_BCRYPT, ['cost' => HASH_COST]), 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
            );
            return ['ok' => true, 'message' => 'Password changed successfully'];
        } catch (\Exception $e) {
            error_log('[UserService] changePassword: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'An error occurred'];
        }
    }

    /** Soft-delete a user */
    public static function delete(string $id): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;
            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($id)],
                ['$set' => ['deleted_at' => new MongoDB\BSON\UTCDateTime(), 'status' => 'deleted']]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Get user statistics for admin panel */
    public static function getStats(): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $total    = (int)$collection->countDocuments(['deleted_at' => null]);
            $active   = (int)$collection->countDocuments(['deleted_at' => null, 'status' => 'active']);
            $pending  = (int)$collection->countDocuments(['deleted_at' => null, 'status' => 'pending']);
            $today    = new MongoDB\BSON\UTCDateTime((int)(strtotime(date('Y-m-d')) * 1000));
            $newToday = (int)$collection->countDocuments(['deleted_at' => null, 'created_at' => ['$gte' => $today]]);

            return compact('total', 'active', 'pending', 'newToday');
        } catch (\Exception $e) {
            return ['total' => 0, 'active' => 0, 'pending' => 0, 'newToday' => 0];
        }
    }

    private static function format($doc): array
    {
        return [
            'id'             => (string)$doc['_id'],
            'first_name'     => $doc['first_name'] ?? '',
            'last_name'      => $doc['last_name'] ?? '',
            'name'           => trim(($doc['first_name'] ?? '') . ' ' . ($doc['last_name'] ?? '')),
            'email'          => $doc['email'] ?? '',
            'phone'          => $doc['phone'] ?? '',
            'role'           => $doc['role'] ?? 'customer',
            'status'         => $doc['status'] ?? 'active',
            'avatar'         => $doc['avatar'] ?? '',
            'email_verified' => (bool)($doc['email_verified'] ?? false),
            'balance'        => (float)($doc['balance'] ?? 0),
            'created_at'     => isset($doc['created_at']) ? $doc['created_at']->toDateTime()->format('Y-m-d H:i:s') : null,
            'last_login'     => isset($doc['last_login']) ? $doc['last_login']->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }
}
