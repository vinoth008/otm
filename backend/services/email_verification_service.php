<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/validation.php';
require_once __DIR__ . '/../helpers/logger.php';

function ev_find_user_by_id(int $userId): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, full_name, email, email_verified, account_status FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function ev_create_email_token(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);

    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO otp_verifications (user_id, otp_code_hash, otp_purpose, expires_at)
        VALUES (:user_id, :otp_code_hash, 'EMAIL_VERIFY', :expires_at)
    ");
    $stmt->execute([
        'user_id' => $userId,
        'otp_code_hash' => $hash,
        'expires_at' => $expiresAt
    ]);

    return $token;
}

function ev_verify_email_token(int $userId, string $token): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT * FROM otp_verifications
        WHERE user_id = :user_id AND otp_purpose = 'EMAIL_VERIFY' AND is_used = 0
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'message' => 'Verification token not found'];
    }

    if (strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'message' => 'Verification token expired'];
    }

    if (!hash_equals($row['otp_code_hash'], hash('sha256', $token))) {
        return ['ok' => false, 'message' => 'Invalid verification token'];
    }

    $stmt = $pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE id = :id");
    $stmt->execute(['id' => $row['id']]);

    $stmt = $pdo->prepare("UPDATE users SET email_verified = 1 WHERE id = :id");
    $stmt->execute(['id' => $userId]);

    log_event('auth.log', "Email verified for user ID {$userId}");

    return ['ok' => true, 'message' => 'Email verified successfully'];
}