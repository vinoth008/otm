<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/validation.php';
require_once __DIR__ . '/../helpers/logger.php';

function pr_find_user_by_email_or_mobile(string $identifier): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, full_name, email, mobile, account_status FROM users WHERE email = :identifier OR mobile = :identifier LIMIT 1");
    $stmt->execute(['identifier' => $identifier]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function pr_create_reset_token(int $userId): array
{
    $plainToken = bin2hex(random_bytes(32));
    $hash = password_hash($plainToken, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);

    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO password_resets (user_id, reset_token_hash, expires_at)
        VALUES (:user_id, :reset_token_hash, :expires_at)
    ");
    $stmt->execute([
        'user_id' => $userId,
        'reset_token_hash' => $hash,
        'expires_at' => $expiresAt
    ]);

    return [
        'token' => $plainToken,
        'expires_at' => $expiresAt
    ];
}

function pr_validate_reset_token(int $userId, string $token): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT * FROM password_resets
        WHERE user_id = :user_id AND is_used = 0
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'message' => 'Reset token not found'];
    }

    if (strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'message' => 'Reset token expired'];
    }

    if (!password_verify($token, $row['reset_token_hash'])) {
        return ['ok' => false, 'message' => 'Invalid reset token'];
    }

    return ['ok' => true, 'row' => $row];
}

function pr_mark_token_used(int $id): void
{
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE password_resets SET is_used = 1 WHERE id = :id");
    $stmt->execute(['id' => $id]);
}

function pr_update_password(int $userId, string $newPassword): bool
{
    if (!valid_password($newPassword)) {
        return false;
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        UPDATE users
        SET password_hash = :password_hash,
            failed_login_attempts = 0,
            locked_until = NULL
        WHERE id = :id
    ");
    return $stmt->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $userId
    ]);
}