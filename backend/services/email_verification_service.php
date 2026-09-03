<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../helpers/Token.php';

function ev_find_user_by_id(int $userId): ?array
{
    $col = getCollection('users');
    if (!$col) return null;
    $user = $col->findOne(['user_id' => $userId]);
    return $user ?: null;
}

function ev_create_email_token(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);

    $col = getCollection('otp_verifications');
    if ($col) {
        $col->insertOne([
            'user_id' => $userId,
            'otp_code_hash' => $hash,
            'otp_purpose' => 'EMAIL_VERIFY',
            'expires_at' => $expiresAt,
            'is_used' => false,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    return $token;
}

function ev_verify_email_token(int $userId, string $token): array
{
    $col = getCollection('otp_verifications');
    if (!$col) {
        return ['ok' => false, 'message' => 'Database unavailable'];
    }

    $row = $col->findOne([
        'user_id' => $userId,
        'otp_purpose' => 'EMAIL_VERIFY',
        'is_used' => false
    ], ['sort' => ['created_at' => -1]]);

    if (!$row) {
        return ['ok' => false, 'message' => 'Verification token not found'];
    }

    if (strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'message' => 'Verification token expired'];
    }

    if (!hash_equals($row['otp_code_hash'], hash('sha256', $token))) {
        return ['ok' => false, 'message' => 'Invalid verification token'];
    }

    $col->updateOne(
        ['_id' => $row['_id']],
        ['$set' => ['is_used' => true, 'used_at' => date('Y-m-d H:i:s')]]
    );

    $users = getCollection('users');
    if ($users) {
        $users->updateOne(
            ['user_id' => $userId],
            ['$set' => ['email_verified' => true]]
        );
    }

    log_event('auth.log', "Email verified for user ID {$userId}");

    return ['ok' => true, 'message' => 'Email verified successfully'];
}
