<?php
declare(strict_types=1);

/**
 * NotesService.php — Personal notes management using MongoDB
 */

require_once __DIR__ . '/../config/database.php';

class NotesService
{
    private const COLLECTION = 'notes';

    /** Create a note */
    public static function create(string $userId, array $data): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return ['ok' => false, 'message' => 'DB error'];

            $title   = trim($data['title'] ?? '');
            $content = trim($data['content'] ?? '');
            $color   = $data['color'] ?? '#fefce8';
            $tags    = (array)($data['tags'] ?? []);
            $pinned  = (bool)($data['pinned'] ?? false);

            if (empty($title) && empty($content)) return ['ok' => false, 'message' => 'Title or content is required'];

            $result = $collection->insertOne([
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'title'      => $title,
                'content'    => $content,
                'color'      => $color,
                'tags'       => $tags,
                'pinned'     => $pinned,
                'is_archived'=> false,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
                'deleted_at' => null,
            ]);

            return ['ok' => true, 'note_id' => (string)$result->getInsertedId(), 'message' => 'Note created'];
        } catch (\Exception $e) {
            error_log('[NotesService] create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Failed to create note'];
        }
    }

    /** List notes for a user */
    public static function list(string $userId, array $filters = [], int $limit = 100, int $skip = 0): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $match = ['user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null];
            if (isset($filters['pinned']))     $match['pinned']      = (bool)$filters['pinned'];
            if (isset($filters['is_archived'])) $match['is_archived'] = (bool)$filters['is_archived'];
            if (!empty($filters['tag']))       $match['tags']        = $filters['tag'];
            if (!empty($filters['search'])) {
                $match['$or'] = [
                    ['title'   => new MongoDB\BSON\Regex($filters['search'], 'i')],
                    ['content' => new MongoDB\BSON\Regex($filters['search'], 'i')],
                ];
            }

            $cursor = $collection->find($match, ['sort' => ['pinned' => -1, 'updated_at' => -1], 'limit' => $limit, 'skip' => $skip]);
            $notes  = [];
            foreach ($cursor as $doc) {
                $notes[] = self::format($doc);
            }
            return $notes;
        } catch (\Exception $e) {
            return [];
        }
    }

    /** Get a single note */
    public static function getById(string $noteId, string $userId): ?array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return null;
            $doc = $collection->findOne([
                '_id'        => new MongoDB\BSON\ObjectId($noteId),
                'user_id'    => new MongoDB\BSON\ObjectId($userId),
                'deleted_at' => null,
            ]);
            return $doc ? self::format($doc) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Update a note */
    public static function update(string $noteId, string $userId, array $data): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;

            $set = ['updated_at' => new MongoDB\BSON\UTCDateTime()];
            if (array_key_exists('title',       $data)) $set['title']       = trim($data['title']);
            if (array_key_exists('content',     $data)) $set['content']     = trim($data['content']);
            if (array_key_exists('color',       $data)) $set['color']       = $data['color'];
            if (array_key_exists('tags',        $data)) $set['tags']        = (array)$data['tags'];
            if (array_key_exists('pinned',      $data)) $set['pinned']      = (bool)$data['pinned'];
            if (array_key_exists('is_archived', $data)) $set['is_archived'] = (bool)$data['is_archived'];

            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($noteId), 'user_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null],
                ['$set' => $set]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Soft-delete a note */
    public static function delete(string $noteId, string $userId): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;
            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($noteId), 'user_id' => new MongoDB\BSON\ObjectId($userId)],
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
            'id'          => (string)$doc['_id'],
            'title'       => $doc['title'] ?? '',
            'content'     => $doc['content'] ?? '',
            'color'       => $doc['color'] ?? '#fefce8',
            'tags'        => (array)($doc['tags'] ?? []),
            'pinned'      => (bool)($doc['pinned'] ?? false),
            'is_archived' => (bool)($doc['is_archived'] ?? false),
            'created_at'  => isset($doc['created_at']) ? $doc['created_at']->toDateTime()->format('Y-m-d H:i:s') : null,
            'updated_at'  => isset($doc['updated_at']) ? $doc['updated_at']->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }
}
