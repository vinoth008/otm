<?php
declare(strict_types=1);

/**
 * AuditService.php — Audit trail for sensitive MongoDB operations
 */

require_once __DIR__ . '/../config/database.php';

class AuditService
{
    private const COLLECTION = 'audit_logs';

    /**
     * Record a sensitive action in the audit trail.
     *
     * @param string      $action     Short action name (e.g. 'user.update', 'transaction.delete')
     * @param string|null $userId     MongoDB ObjectId string of the actor
     * @param array       $before     State before the change (optional)
     * @param array       $after      State after the change (optional)
     * @param array       $meta       Extra context (table, record_id, etc.)
     */
    public static function log(
        string  $action,
        ?string $userId = null,
        array   $before = [],
        array   $after  = [],
        array   $meta   = []
    ): void {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return;

            $mongoUserId = null;
            if ($userId !== null) {
                try { $mongoUserId = new MongoDB\BSON\ObjectId($userId); } catch (\Exception $e) {}
            }

            $collection->insertOne([
                'user_id'    => $mongoUserId,
                'action'     => $action,
                'before'     => $before,
                'after'      => $after,
                'meta'       => $meta,
                'ip_address' => self::getIp(),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
        } catch (\Exception $e) {
            error_log('[AuditService] ' . $e->getMessage());
        }
    }

    /**
     * Fetch recent audit logs (admin only).
     *
     * @param array $filter  MongoDB filter (e.g. ['action' => 'login'])
     * @param int   $limit
     * @param int   $skip
     */
    public static function getLogs(array $filter = [], int $limit = 50, int $skip = 0): array
    {
        try {
            $collection = getCollection(self::COLLECTION);
            if (!$collection) return [];

            $cursor = $collection->find(
                $filter,
                ['sort' => ['created_at' => -1], 'limit' => $limit, 'skip' => $skip]
            );

            $logs = [];
            foreach ($cursor as $doc) {
                $logs[] = self::formatLog($doc);
            }
            return $logs;
        } catch (\Exception $e) {
            error_log('[AuditService] getLogs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count audit logs matching a filter.
     */
    public static function count(array $filter = []): int
    {
        try {
            $collection = getCollection(self::COLLECTION);
            return $collection ? (int)$collection->countDocuments($filter) : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private static function formatLog($doc): array
    {
        return [
            'id'         => (string)$doc['_id'],
            'user_id'    => isset($doc['user_id']) ? (string)$doc['user_id'] : null,
            'action'     => $doc['action'] ?? '',
            'before'     => (array)($doc['before'] ?? []),
            'after'      => (array)($doc['after'] ?? []),
            'meta'       => (array)($doc['meta'] ?? []),
            'ip_address' => $doc['ip_address'] ?? '',
            'user_agent' => $doc['user_agent'] ?? '',
            'created_at' => isset($doc['created_at']) ? $doc['created_at']->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }

    private static function getIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
        }
        return '0.0.0.0';
    }
}
