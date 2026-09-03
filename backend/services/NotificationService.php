<?php
declare(strict_types=1);

/**
 * NotificationService.php — User notifications using MongoDB
 */

require_once __DIR__ . '/../config/database.php';

class NotificationService
{
    private const COLLECTION = 'notifications';

    /** Create a notification for a user */
    public static function create(string $userId, string $type, string $title, string $message, array $meta = []): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;

            $collection->insertOne([
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'type'       => $type,   // account | transaction | alert | system | reminder
                'title'      => $title,
                'message'    => $message,
                'meta'       => $meta,
                'is_read'    => false,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
                'deleted_at' => null,
            ]);
            return true;
        } catch (\Exception $e) {
            error_log('[NotificationService] create: ' . $e->getMessage());
            return false;
        }
    }

    /** Get unread notifications for a user */
    public static function getUnread(string $userId, int $limit = 20): array
    {
        return self::list($userId, ['is_read' => false], $limit);
    }

    /** Get all notifications for a user (paginated) */
    public static function list(string $userId, array $filter = [], int $limit = 50, int $skip = 0): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $baseFilter = array_merge([
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'deleted_at' => null,
            ], $filter);

            $cursor = $collection->find(
                $baseFilter,
                ['sort' => ['created_at' => -1], 'limit' => $limit, 'skip' => $skip]
            );

            $items = [];
            foreach ($cursor as $doc) {
                $items[] = self::format($doc);
            }
            return $items;
        } catch (\Exception $e) {
            error_log('[NotificationService] list: ' . $e->getMessage());
            return [];
        }
    }

    /** Count unread notifications */
    public static function countUnread(string $userId): int
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return 0;
            return (int)$collection->countDocuments([
                'user_id' => new MongoDB\BSON\ObjectId($userId),
                'is_read' => false,
                'deleted_at' => null,
            ]);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /** Mark a single notification as read */
    public static function markRead(string $notifId, string $userId): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;
            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($notifId), 'user_id' => new MongoDB\BSON\ObjectId($userId)],
                ['$set' => ['is_read' => true, 'read_at' => new MongoDB\BSON\UTCDateTime()]]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Mark all notifications as read for a user */
    public static function markAllRead(string $userId): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;
            $collection->updateMany(
                ['user_id' => new MongoDB\BSON\ObjectId($userId), 'is_read' => false],
                ['$set' => ['is_read' => true, 'read_at' => new MongoDB\BSON\UTCDateTime()]]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Soft-delete a notification */
    public static function delete(string $notifId, string $userId): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;
            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($notifId), 'user_id' => new MongoDB\BSON\ObjectId($userId)],
                ['$set' => ['deleted_at' => new MongoDB\BSON\UTCDateTime()]]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function format($doc): array
    {
        return [
            'id'         => (string)$doc['_id'],
            'type'       => $doc['type'] ?? 'system',
            'title'      => $doc['title'] ?? '',
            'message'    => $doc['message'] ?? '',
            'meta'       => (array)($doc['meta'] ?? []),
            'is_read'    => (bool)($doc['is_read'] ?? false),
            'created_at' => isset($doc['created_at']) ? $doc['created_at']->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }
}
