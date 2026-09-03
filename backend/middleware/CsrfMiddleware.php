<?php
declare(strict_types=1);

/**
 * CsrfMiddleware.php — CSRF token generation and validation
 */

class CsrfMiddleware
{
    private const TOKEN_KEY = 'csrf_token';

    /**
     * Generate (or return existing) CSRF token.
     */
    public static function getToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Validate the CSRF token from the request.
     * Checks X-CSRF-Token header or _csrf POST field.
     * Exits with 403 on failure.
     */
    public static function validate(): void
    {
        // API calls with JSON body typically use the header
        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['_csrf']
            ?? null;

        if (!self::verify((string)$token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
    }

    /**
     * Verify a token string without side effects.
     */
    public static function verify(string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $stored = $_SESSION[self::TOKEN_KEY] ?? '';
        return $stored !== '' && hash_equals($stored, $token);
    }

    /**
     * Rotate the CSRF token (call after a successful state-changing request).
     */
    public static function rotate(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::TOKEN_KEY];
    }
}
