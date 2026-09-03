<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../helpers/Token.php';

function auth_find_user_by_login(string $login): ?array
{
    $col = getCollection('users');
    if (!$col) return null;

    if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
        $user = $col->findOne(['email' => $login]);
    } elseif (preg_match('/^[0-9+\- ]{7,15}$/', $login)) {
        $user = $col->findOne(['mobile' => $login]);
    } else {
        $user = $col->findOne(['username' => $login]);
    }
    return $user ?: null;
}

function auth_find_user_by_id(int $id): ?array
{
    $col = getCollection('users');
    if (!$col) return null;
    $user = $col->findOne(['user_id' => $id]);
    return $user ?: null;
}

function auth_create_otp(int $userId, string $purpose): string
{
    $otp = (string)random_int(100000, 999999);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = new DateTime('+' . OTP_LENGTH . ' minutes');

    $col = getCollection('otp_verifications');
    if ($col) {
        $col->insertOne([
            'user_id' => $userId,
            'otp_code_hash' => $hash,
            'otp_purpose' => $purpose,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'is_used' => false,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    return $otp;
}

function auth_create_session(array $user): string
{
    start_secure_session();
    session_regenerate_id(true);

    $token = bin2hex(random_bytes(32));
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['role_code'] = $user['role_code'] ?? 'customer';
    $_SESSION['full_name'] = $user['full_name'] ?? '';
    $_SESSION['session_token'] = $token;

    $col = getCollection('sessions');
    if ($col) {
        $col->insertOne([
            'user_id' => (int)$user['user_id'],
            'session_token_hash' => password_hash($token, PASSWORD_DEFAULT),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'device_info' => 'Web Browser',
            'location_info' => null,
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'last_activity' => date('Y-m-d H:i:s')
        ]);
    }

    return $token;
}

function auth_destroy_session(): void
{
    start_secure_session();
    if (!empty($_SESSION['user_id'])) {
        $col = getCollection('sessions');
        if ($col) {
            $col->updateMany(
                ['user_id' => (int)$_SESSION['user_id'], 'is_active' => true],
                ['$set' => ['is_active' => false, 'ended_at' => date('Y-m-d H:i:s')]]
            );
        }
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], (bool)$params["secure"], (bool)$params["httponly"]);
    }
    session_destroy();
}

function auth_register_user(array $input): array
{
    $roleCode = clean_string($input['role_code'] ?? 'customer');
    $fullName = clean_string($input['full_name'] ?? '');
    $username = clean_string($input['username'] ?? '');
    $email = clean_string($input['email'] ?? '');
    $mobile = clean_string($input['mobile'] ?? '');
    $password = (string)($input['password'] ?? '');

    if ($fullName === '' || $email === '' || $mobile === '' || !valid_password($password)) {
        return ['ok' => false, 'message' => 'Invalid registration data'];
    }
    if ($username !== '' && !valid_username($username)) {
        return ['ok' => false, 'message' => 'Invalid username'];
    }
    if (!valid_email($email)) {
        return ['ok' => false, 'message' => 'Invalid email'];
    }

    $col = getCollection('users');
    if (!$col) {
        return ['ok' => false, 'message' => 'Database unavailable'];
    }

    $exists = $col->findOne([
        '$or' => [
            ['username' => $username],
            ['email' => $email],
            ['mobile' => $mobile]
        ]
    ]);
    if ($exists) {
        return ['ok' => false, 'message' => 'User already exists'];
    }

    $result = $col->insertOne([
        'role_code' => $roleCode,
        'full_name' => $fullName,
        'username' => $username,
        'email' => $email,
        'mobile' => $mobile,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'account_status' => 'ACTIVE',
        'email_verified' => false,
        'mobile_verified' => false,
        'two_factor_enabled' => false,
        'failed_login_attempts' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    $userId = (int)$result->getInsertedId();
    $otp = auth_create_otp($userId, 'REGISTER');

    return ['ok' => true, 'user_id' => $userId, 'otp' => $otp, 'message' => 'Registered successfully'];
}

function auth_login_user(array $input): array
{
    $login = clean_string($input['login'] ?? '');
    $password = (string)($input['password'] ?? '');

    if ($login === '' || $password === '') {
        return ['ok' => false, 'message' => 'Login and password are required'];
    }

    $user = auth_find_user_by_login($login);
    if (!$user) {
        return ['ok' => false, 'message' => 'Invalid credentials'];
    }

    if (($user['account_status'] ?? '') !== 'ACTIVE') {
        return ['ok' => false, 'message' => 'Account is not active'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $col = getCollection('users');
        if ($col) {
            $col->updateOne(
                ['user_id' => $user['user_id']],
                ['$inc' => ['failed_login_attempts' => 1]]
            );
        }
        log_event('auth.log', "Failed login for user {$login}");
        return ['ok' => false, 'message' => 'Invalid credentials'];
    }

    if ((int)($user['failed_login_attempts'] ?? 0) >= MAX_LOGIN_ATTEMPTS) {
        return ['ok' => false, 'message' => 'Account locked due to too many attempts'];
    }

    $col = getCollection('users');
    if ($col) {
        $col->updateOne(
            ['user_id' => $user['user_id']],
            ['$set' => ['failed_login_attempts' => 0, 'last_login_at' => date('Y-m-d H:i:s')]]
        );
    }

    $otp = null;
    if (!empty($user['two_factor_enabled'])) {
        $otp = auth_create_otp((int)$user['user_id'], 'LOGIN');
    }

    auth_create_session($user);

    return [
        'ok' => true,
        'otp_required' => !empty($user['two_factor_enabled']),
        'otp' => $otp,
        'role_code' => $user['role_code'] ?? 'customer',
        'user_id' => (int)$user['user_id'],
        'message' => 'Login successful'
    ];
}

function auth_verify_otp(int $userId, string $purpose, string $otpInput): array
{
    $col = getCollection('otp_verifications');
    if (!$col) {
        return ['ok' => false, 'message' => 'Database unavailable'];
    }

    $row = $col->findOne([
        'user_id' => $userId,
        'otp_purpose' => $purpose,
        'is_used' => false
    ], ['sort' => ['created_at' => -1]]);

    if (!$row || strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'message' => 'OTP expired or invalid'];
    }

    if (!password_verify($otpInput, $row['otp_code_hash'])) {
        return ['ok' => false, 'message' => 'Incorrect OTP'];
    }

    $col->updateOne(
        ['_id' => $row['_id']],
        ['$set' => ['is_used' => true, 'used_at' => date('Y-m-d H:i:s')]]
    );

    return ['ok' => true, 'message' => 'OTP verified'];
}
