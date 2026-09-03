<?php
declare(strict_types=1);

/**
 * LogMiddleware.php — Request/response and activity logging to MongoDB
 */

require_once __DIR__ . '/../config/database.php';

class LogMiddleware
{
    /**
     * Log every incoming API request to MongoDB activity_logs collection.
     */
    public static function logRequest(string $module = '', string $action = ''): void
    {
        try {
            $collection = getCollection('activity_logs');
            if (!$collection) return;

            $userId = null;
            if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
                try {
                    $userId = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
                } catch (\Exception $e) {
                    // Invalid ObjectId — skip
                }
            }

            $collection->insertOne([
                'user_id'    => $userId,
                'module'     => $module,
                'action'     => $action,
                'method'     => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
                'ip_address' => self::getClientIp(),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
        } catch (\Exception $e) {
            error_log('[LogMiddleware] ' . $e->getMessage());
        }
    }

    /**
     * Log a specific user activity with optional details payload.
     */
    public static function logActivity(string $action, ?string $userId, array $details = []): void
    {
        try {
            $collection = getCollection('activity_logs');
            if (!$collection) return;

            $mongoUserId = null;
            if ($userId !== null) {
                try {
                    $mongoUserId = new MongoDB\BSON\ObjectId($userId);
                } catch (\Exception $e) {
                    // Skip invalid IDs
                }
            }

            $collection->insertOne([
                'user_id'    => $mongoUserId,
                'action'     => $action,
                'details'    => $details,
                'ip_address' => self::getClientIp(),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
        } catch (\Exception $e) {
            error_log('[LogMiddleware] logActivity error: ' . $e->getMessage());
        }
    }

    /**
     * Log an error to the filesystem error log AND MongoDB.
     */
    public static function logError(string $message, array $context = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message;
        if ($context) {
            $line .= ' | Context: ' . json_encode($context);
        }
        error_log($line);

        try {
            $collection = getCollection('error_logs');
            if ($collection) {
                $collection->insertOne([
                    'message'    => $message,
                    'context'    => $context,
                    'ip_address' => self::getClientIp(),
                    'created_at' => new MongoDB\BSON\UTCDateTime(),
                ]);
            }
        } catch (\Exception $e) {
            // Silently fail — don't recursive-log
        }
    }

    /**
     * Get real client IP, respecting trusted proxies.
     */
    private static function getClientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key])[0];
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }
}
