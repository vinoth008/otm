<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../helpers/Token.php';

function pr_find_user_by_email_or_mobile(string $identifier): ?array
{
    $col = getCollection('users');
    if (!$col) return null;

    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $user = $col->findOne(['email' => $identifier]);
    } else {
        $user = $col->findOne(['mobile' => $identifier]);
    }
    return $user ?: null;
}

function pr_create_reset_token(int $userId): array
{
    $plainToken = bin2hex(random_bytes(32));
    $hash = password_hash($plainToken, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);

    $col = getCollection('password_resets');
    if ($col) {
        $col->insertOne([
            'user_id' => $userId,
            'reset_token_hash' => $hash,
            'expires_at' => $expiresAt,
            'is_used' => false,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    return [
        'token' => $plainToken,
        'expires_at' => $expiresAt
    ];
}

function pr_validate_reset_token(int $userId, string $token): array
{
    $col = getCollection('password_resets');
    if (!$col) {
        return ['ok' => false, 'message' => 'Database unavailable'];
    }

    $row = $col->findOne([
        'user_id' => $userId,
        'is_used' => false
    ], ['sort' => ['created_at' => -1]]);

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

function pr_mark_token_used($id): void
{
    $col = getCollection('password_resets');
    if ($col) {
        $col->updateOne(
            ['_id' => $id],
            ['$set' => ['is_used' => true, 'used_at' => date('Y-m-d H:i:s')]]
        );
    }
}

function pr_update_password(int $userId, string $newPassword): bool
{
    if (!valid_password($newPassword)) {
        return false;
    }

    $col = getCollection('users');
    if (!$col) return false;

    $col->updateOne(
        ['user_id' => $userId],
        ['$set' => [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'failed_login_attempts' => 0,
            'locked_until' => null
        ]]
    );

    return true;
}
