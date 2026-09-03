<?php
declare(strict_types=1);

/**
 * Token.php — Secure token helpers for API keys, invite links, etc.
 */

/**
 * Generate a cryptographically-secure random token string.
 * @param int $bytes Number of random bytes (default 32 → 64 hex chars)
 */
function generate_secure_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Generate a URL-safe token (base64url, no padding).
 */
function generate_url_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * Hash a token for storage (SHA-256).
 * Use hash_equals() to verify — never store plain tokens.
 */
function hash_token(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Verify a token against its stored hash in constant time.
 */
function verify_token(string $token, string $storedHash): bool
{
    return hash_equals($storedHash, hash_token($token));
}

/**
 * Build a signed HMAC-SHA256 token string: "<data>.<hmac>"
 */
function sign_token(string $data, string $secret): string
{
    $mac = hash_hmac('sha256', $data, $secret);
    return base64_encode($data) . '.' . $mac;
}

/**
 * Verify and decode a signed token. Returns the original data or null.
 */
function verify_signed_token(string $signed, string $secret): ?string
{
    $parts = explode('.', $signed, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$encodedData, $mac] = $parts;
    $data = base64_decode($encodedData);
    if ($data === false) {
        return null;
    }
    $expected = hash_hmac('sha256', $data, $secret);
    return hash_equals($expected, $mac) ? $data : null;
}
