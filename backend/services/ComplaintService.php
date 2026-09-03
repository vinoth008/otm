<?php
declare(strict_types=1);

/**
 * ComplaintService.php — Complaint/support ticket management using MongoDB
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/NotificationService.php';

class ComplaintService
{
    private const COLLECTION = 'complaints';

    /** Submit a new complaint */
    public static function submit(string $userId, array $data): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return ['ok' => false, 'message' => 'DB error'];

            $subject     = trim($data['subject'] ?? '');
            $description = trim($data['description'] ?? '');
            $category    = $data['category'] ?? 'general'; // general | transaction | account | technical

            if (empty($subject))     return ['ok' => false, 'message' => 'Subject is required'];
            if (empty($description)) return ['ok' => false, 'message' => 'Description is required'];

            $ticketNo = 'TKT' . date('ymd') . strtoupper(substr(uniqid('', true), -5));

            $result = $collection->insertOne([
                'user_id'     => new MongoDB\BSON\ObjectId($userId),
                'ticket_no'   => $ticketNo,
                'subject'     => $subject,
                'description' => $description,
                'category'    => $category,
                'status'      => 'open',   // open | in_progress | resolved | closed
                'priority'    => $data['priority'] ?? 'medium', // low | medium | high | critical
                'assigned_to' => null,
                'resolution'  => null,
                'replies'     => [],
                'created_at'  => new MongoDB\BSON\UTCDateTime(),
                'updated_at'  => new MongoDB\BSON\UTCDateTime(),
                'resolved_at' => null,
                'deleted_at'  => null,
            ]);

            $complaintId = (string)$result->getInsertedId();
            NotificationService::create($userId, 'account', 'Complaint Submitted', "Your complaint #{$ticketNo} has been submitted. We'll respond shortly.", ['complaint_id' => $complaintId]);

            return ['ok' => true, 'complaint_id' => $complaintId, 'ticket_no' => $ticketNo, 'message' => 'Complaint submitted successfully'];
        } catch (\Exception $e) {
            error_log('[ComplaintService] submit: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Failed to submit complaint'];
        }
    }

    /** List complaints for a user */
    public static function listForUser(string $userId, int $limit = 20, int $skip = 0): array
    {
        return self::list(['user_id' => new MongoDB\BSON\ObjectId($userId)], $limit, $skip);
    }

    /** List all complaints (admin/staff) */
    public static function listAll(array $filters = [], int $limit = 50, int $skip = 0): array
    {
        $match = [];
        if (!empty($filters['status']))   $match['status']   = $filters['status'];
        if (!empty($filters['category'])) $match['category'] = $filters['category'];
        if (!empty($filters['priority'])) $match['priority'] = $filters['priority'];
        return self::list($match, $limit, $skip);
    }

    /** Get a single complaint */
    public static function getById(string $complaintId): ?array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return null;
            $doc = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($complaintId), 'deleted_at' => null]);
            return $doc ? self::format($doc) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Update status (staff/admin) */
    public static function updateStatus(string $complaintId, string $status, ?string $resolution = null): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;

            $set = ['status' => $status, 'updated_at' => new MongoDB\BSON\UTCDateTime()];
            if ($resolution) $set['resolution'] = $resolution;
            if (in_array($status, ['resolved', 'closed'], true)) $set['resolved_at'] = new MongoDB\BSON\UTCDateTime();

            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($complaintId)],
                ['$set' => $set]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Add a reply to a complaint thread */
    public static function addReply(string $complaintId, string $authorId, string $message, string $role = 'customer'): bool
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return false;

            $reply = [
                'author_id' => new MongoDB\BSON\ObjectId($authorId),
                'role'      => $role,
                'message'   => trim($message),
                'created_at'=> new MongoDB\BSON\UTCDateTime(),
            ];

            $result = $collection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($complaintId)],
                ['$push' => ['replies' => $reply], '$set' => ['updated_at' => new MongoDB\BSON\UTCDateTime()]]
            );
            return $result->getModifiedCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Count complaints by status */
    public static function countByStatus(): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $pipeline = [
                ['$match'  => ['deleted_at' => null]],
                ['$group'  => ['_id' => '$status', 'count' => ['$sum' => 1]]],
            ];
            $result = [];
            foreach ($collection->aggregate($pipeline)->toArray() as $doc) {
                $result[(string)$doc['_id']] = (int)$doc['count'];
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function list(array $match, int $limit, int $skip): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];
            $match['deleted_at'] = null;
            $cursor = $collection->find($match, ['sort' => ['created_at' => -1], 'limit' => $limit, 'skip' => $skip]);
            $items  = [];
            foreach ($cursor as $doc) {
                $items[] = self::format($doc);
            }
            return $items;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function format($doc): array
    {
        $replies = [];
        foreach ((array)($doc['replies'] ?? []) as $r) {
            $replies[] = [
                'author_id'  => isset($r['author_id']) ? (string)$r['author_id'] : null,
                'role'       => $r['role'] ?? 'customer',
                'message'    => $r['message'] ?? '',
                'created_at' => isset($r['created_at']) ? $r['created_at']->toDateTime()->format('Y-m-d H:i:s') : null,
            ];
        }
        return [
            'id'          => (string)$doc['_id'],
            'user_id'     => isset($doc['user_id']) ? (string)$doc['user_id'] : null,
            'ticket_no'   => $doc['ticket_no'] ?? '',
            'subject'     => $doc['subject'] ?? '',
            'description' => $doc['description'] ?? '',
            'category'    => $doc['category'] ?? 'general',
            'status'      => $doc['status'] ?? 'open',
            'priority'    => $doc['priority'] ?? 'medium',
            'resolution'  => $doc['resolution'] ?? null,
            'replies'     => $replies,
            'created_at'  => isset($doc['created_at']) ? $doc['created_at']->toDateTime()->format('Y-m-d H:i:s') : null,
            'resolved_at' => isset($doc['resolved_at']) ? $doc['resolved_at']->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }
}
