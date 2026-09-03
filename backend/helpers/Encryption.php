<?php
declare(strict_types=1);

/**
 * Encryption.php — AES-256-GCM encryption/decryption helpers
 * Uses a key derived from ENCRYPTION_KEY env variable.
 */

function get_encryption_key(): string
{
    $raw = getenv('ENCRYPTION_KEY') ?: 'change-me-32-char-secret-key!!!!';
    return hash('sha256', $raw, true); // always 32 bytes
}

/**
 * Encrypt a string using AES-256-GCM.
 * Returns base64url-encoded ciphertext with IV and tag prepended.
 */
function encrypt_data(string $plaintext): string
{
    $key    = get_encryption_key();
    $iv     = random_bytes(12); // 96-bit IV recommended for GCM
    $tag    = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed');
    }
    // Pack: 12-byte IV | 16-byte tag | ciphertext
    $packed = $iv . $tag . $cipher;
    return rtrim(strtr(base64_encode($packed), '+/', '-_'), '=');
}

/**
 * Decrypt a string produced by encrypt_data().
 * Returns the original plaintext or null on failure.
 */
function decrypt_data(string $encoded): ?string
{
    $packed = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4));
    if (strlen($packed) < 29) {
        return null; // too short to be valid
    }
    $key  = get_encryption_key();
    $iv   = substr($packed, 0, 12);
    $tag  = substr($packed, 12, 16);
    $data = substr($packed, 28);
    $plain = openssl_decrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/**
 * Hash sensitive data (e.g. card numbers) with SHA-256 for indexed lookups.
 */
function hash_sensitive(string $value): string
{
    return hash('sha256', $value);
}
