<?php
declare(strict_types=1);

/**
 * RateLimitMiddleware.php — IP-based rate limiting using MongoDB
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

class RateLimitMiddleware
{
    /**
     * Enforce rate limit. Sends 429 and exits if exceeded.
     *
     * @param string $action    Identifier for the action being limited (e.g. 'login', 'otp')
     * @param int    $maxHits   Max requests allowed in the window
     * @param int    $windowSec Window size in seconds
     */
    public static function check(string $action = 'global', int $maxHits = 60, int $windowSec = 60): void
    {
        $ip         = self::getClientIp();
        $windowStart = new MongoDB\BSON\UTCDateTime((int)((time() - $windowSec) * 1000));

        try {
            $collection = getCollection('rate_limits');
            if (!$collection) return; // fail-open if DB unavailable

            // Count recent hits
            $count = $collection->countDocuments([
                'ip_address' => $ip,
                'action'     => $action,
                'created_at' => ['$gte' => $windowStart],
            ]);

            if ($count >= $maxHits) {
                // Log the blocked attempt
                $collection->insertOne([
                    'ip_address' => $ip,
                    'action'     => $action,
                    'blocked'    => true,
                    'created_at' => new MongoDB\BSON\UTCDateTime(),
                ]);
                http_response_code(429);
                header('Retry-After: ' . $windowSec);
                echo json_encode([
                    'success' => false,
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => $windowSec,
                ]);
                exit;
            }

            // Record this hit
            $collection->insertOne([
                'ip_address' => $ip,
                'action'     => $action,
                'blocked'    => false,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ]);

            // TTL cleanup — ensure a TTL index exists on created_at (set once in DB setup)
        } catch (\Exception $e) {
            error_log('[RateLimitMiddleware] ' . $e->getMessage());
            // fail-open on error
        }
    }

    /**
     * Strict limiter for sensitive auth actions (login, forgot password).
     */
    public static function checkAuth(string $action = 'auth'): void
    {
        self::check($action, MAX_LOGIN_ATTEMPTS, LOCKOUT_TIME);
    }

    private static function getClientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }
}
