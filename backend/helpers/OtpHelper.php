<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/constants.php';

/**
 * OtpHelper — generate, store, and send OTP codes
 * Uses the shared EmailService for Gmail SMTP.
 */

function generate_otp_code(int $length = 6): string {
    $digits = '0123456789';
    $otp = '';
    for ($i = 0; $i < $length; $i++) {
        $otp .= $digits[random_int(0, strlen($digits) - 1)];
    }
    return $otp;
}

function store_otp(string $userId, string $purpose, string $otp, int $ttlMinutes = 10): bool {
    $collection = getCollection('otp_verifications');
    if (!$collection) return false;

    $expiresAt = (int)((time() + ($ttlMinutes * 60)) * 1000);

    // Delete any previous unverified OTPs for this user + purpose
    $collection->deleteMany([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'otp_purpose' => $purpose,
        'is_used' => false
    ]);

    $collection->insertOne([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'otp_code_hash' => hash('sha256', $otp),
        'otp_purpose' => $purpose,
        'is_used' => false,
        'expires_at' => new MongoDB\BSON\UTCDateTime($expiresAt),
        'created_at' => new MongoDB\BSON\UTCDateTime(),
        'attempts' => 0
    ]);

    return true;
}

function verify_otp_code(string $userId, string $purpose, string $otpInput): array {
    $collection = getCollection('otp_verifications');
    if (!$collection) {
        return ['ok' => false, 'message' => 'Database connection error'];
    }

    $record = $collection->findOne([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'otp_purpose' => $purpose,
        'is_used' => false
    ]);

    if (!$record) {
        return ['ok' => false, 'message' => 'No OTP found. Please request a new code.'];
    }

    $expiresAt = $record['expires_at'];
    if ($expiresAt instanceof MongoDB\BSON\UTCDateTime) {
        $expiresAt = $expiresAt->toDateTime()->getTimestamp();
    } else {
        $expiresAt = (int)($expiresAt / 1000);
    }

    if ($expiresAt < time()) {
        return ['ok' => false, 'message' => 'OTP expired. Please request a new code.'];
    }

    // Rate limit: max 5 attempts per OTP
    if ((int)($record['attempts'] ?? 0) >= 5) {
        return ['ok' => false, 'message' => 'Too many failed attempts. Please request a new OTP.'];
    }

    $storedHash = $record['otp_code_hash'] ?? '';
    if ($storedHash === '' || !hash_equals($storedHash, hash('sha256', $otpInput))) {
        $collection->updateOne(
            ['_id' => $record['_id']],
            ['$inc' => ['attempts' => 1]]
        );
        return ['ok' => false, 'message' => 'Incorrect OTP. Please try again.'];
    }

    // Mark as used
    $collection->updateOne(
        ['_id' => $record['_id']],
        ['$set' => ['is_used' => true, 'verified_at' => new MongoDB\BSON\UTCDateTime()]]
    );

    return ['ok' => true, 'message' => 'OTP verified successfully'];
}

function send_otp_email(string $toEmail, string $toName, string $otp, string $purpose = 'verify_email'): bool {
    require_once __DIR__ . '/../services/EmailService.php';

    $emailService = new EmailService();
    if (!$emailService->enabled) {
        error_log("OTP email to {$toEmail} skipped - SMTP not configured");
        return false;
    }

    return $emailService->sendOTPEmail($toEmail, $toName, $otp, $purpose);
}
