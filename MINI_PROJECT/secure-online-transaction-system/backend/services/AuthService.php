<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/validation.php';
require_once __DIR__ . '/../helpers/logger.php';

function auth_find_user_by_login(string $login): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT u.*, r.role_code, r.role_name
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        WHERE u.username = :login OR u.email = :login OR u.mobile = :login
        LIMIT 1
    ");
    $stmt->execute(['login' => $login]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function auth_find_user_by_id(int $id): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT u.*, r.role_code, r.role_name
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        WHERE u.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function auth_create_otp(int $userId, string $purpose): string
{
    $otp = (string)random_int(100000, 999999);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + (OTP_LENGTH * 60));

    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO otp_verifications (user_id, otp_code_hash, otp_purpose, expires_at)
        VALUES (:user_id, :otp_code_hash, :otp_purpose, :expires_at)
    ");
    $stmt->execute([
        'user_id' => $userId,
        'otp_code_hash' => $hash,
        'otp_purpose' => $purpose,
        'expires_at' => $expires
    ]);

    return $otp;
}

function auth_create_session(array $user): string
{
    start_secure_session();
    session_regenerate_id(true);

    $token = bin2hex(random_bytes(32));
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role_code'] = $user['role_code'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['session_token'] = $token;

    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO sessions (user_id, session_token_hash, ip_address, user_agent, device_info, location_info)
        VALUES (:user_id, :session_token_hash, :ip_address, :user_agent, :device_info, :location_info)
    ");
    $stmt->execute([
        'user_id' => $user['id'],
        'session_token_hash' => password_hash($token, PASSWORD_DEFAULT),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'device_info' => 'Web Browser',
        'location_info' => null
    ]);

    return $token;
}

function auth_destroy_session(): void
{
    start_secure_session();
    if (!empty($_SESSION['user_id'])) {
        $pdo = db();
        $stmt = $pdo->prepare("UPDATE sessions SET is_active = 0 WHERE user_id = :user_id AND is_active = 1");
        $stmt->execute(['user_id' => (int)$_SESSION['user_id']]);
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
    $pdo = db();

    $roleId = (int)$input['role_id'];
    $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
    $fullName = clean_string($input['full_name'] ?? '');
    $username = clean_string($input['username'] ?? '');
    $email = clean_string($input['email'] ?? '');
    $mobile = clean_string($input['mobile'] ?? '');
    $password = (string)($input['password'] ?? '');
    $securityQuestion = clean_string($input['security_question'] ?? '');
    $securityAnswer = clean_string($input['security_answer'] ?? '');

    if ($fullName === '' || !valid_username($username) || !valid_email($email) || !valid_mobile($mobile) || !valid_password($password)) {
        return ['ok' => false, 'message' => 'Invalid registration data'];
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email OR mobile = :mobile LIMIT 1");
    $stmt->execute(['username' => $username, 'email' => $email, 'mobile' => $mobile]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'message' => 'User already exists'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (role_id, branch_id, full_name, username, email, mobile, password_hash, security_question, security_answer_hash, account_status, email_verified, mobile_verified, two_factor_enabled)
        VALUES (:role_id, :branch_id, :full_name, :username, :email, :mobile, :password_hash, :security_question, :security_answer_hash, 'PENDING', 0, 0, 0)
    ");
    $stmt->execute([
        'role_id' => $roleId,
        'branch_id' => $branchId,
        'full_name' => $fullName,
        'username' => $username,
        'email' => $email,
        'mobile' => $mobile,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'security_question' => $securityQuestion,
        'security_answer_hash' => password_hash($securityAnswer, PASSWORD_DEFAULT)
    ]);

    $userId = (int)$pdo->lastInsertId();
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

    if ($user['account_status'] !== 'ACTIVE') {
        return ['ok' => false, 'message' => 'Account is not active'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $pdo = db();
        $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id");
        $stmt->execute(['id' => $user['id']]);
        log_event('auth.log', "Failed login for user ID {$user['id']}");
        return ['ok' => false, 'message' => 'Invalid credentials'];
    }

    if ((int)$user['failed_login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
        return ['ok' => false, 'message' => 'Account locked due to too many attempts'];
    }

    $pdo = db();
    $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, last_login_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $user['id']]);

    $otp = null;
    if ((int)$user['two_factor_enabled'] === 1) {
        $otp = auth_create_otp((int)$user['id'], 'LOGIN');
    }

    auth_create_session($user);

    return [
        'ok' => true,
        'otp_required' => (int)$user['two_factor_enabled'] === 1,
        'otp' => $otp,
        'role_code' => $user['role_code'],
        'user_id' => (int)$user['id'],
        'message' => 'Login successful'
    ];
}

function auth_verify_otp(int $userId, string $purpose, string $otpInput): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT * FROM otp_verifications
        WHERE user_id = :user_id AND otp_purpose = :otp_purpose AND is_used = 0
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute(['user_id' => $userId, 'otp_purpose' => $purpose]);
    $otpRow = $stmt->fetch();

    if (!$otpRow || strtotime($otpRow['expires_at']) < time()) {
        return ['ok' => false, 'message' => 'OTP expired or invalid'];
    }

    if (!password_verify($otpInput, $otpRow['otp_code_hash'])) {
        return ['ok' => false, 'message' => 'Incorrect OTP'];
    }

    $stmt = $pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE id = :id");
    $stmt->execute(['id' => $otpRow['id']]);

    return ['ok' => true, 'message' => 'OTP verified'];
}