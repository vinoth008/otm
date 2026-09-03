<?php
declare(strict_types=1);
// Authentication API - unified with MPWT reference
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'login': $method === 'POST' && login(); break;
    case 'register': $method === 'POST' && register(); break;
    case 'logout': $method === 'POST' && logout(); break;
    case 'forgot_password': $method === 'POST' && forgotPassword(); break;
    case 'reset_password': $method === 'POST' && resetPassword(); break;
    case 'verify_email': $method === 'POST' && verifyEmail(); break;
    case 'send_otp': $method === 'POST' && sendOtp(); break;
    case 'verify_otp': $method === 'POST' && verifyOtp(); break;
    case 'get_session': $method === 'GET' && getSession(); break;
    default: errorResponse('Invalid action', 404);
}

function login() {
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $email = sanitizeInput($data['email'] ?? '');
    $password = $data['password'] ?? '';
    if (empty($email) || empty($password)) errorResponse('Email and password are required');
    if (!validateEmail($email)) errorResponse('Invalid email format');
    $collection = getCollection('users');
    if (!$collection) errorResponse('Database connection error');
    $user = $collection->findOne(['email' => $email, 'deleted_at' => null]);
    if (!$user || !verifyPassword($password, $user['password_hash'] ?? ($user['password'] ?? ''))) {
        // Rate limiting: track failed attempts
        $attemptsCollection = getCollection('login_attempts');
        if ($attemptsCollection) {
            $attemptsCollection->insertOne([
                'email' => $email,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'created_at' => phpDateToMongo()
            ]);
            $recent = $attemptsCollection->countDocuments([
                'email' => $email,
                'created_at' => ['$gte' => phpDateToMongo(date('Y-m-d H:i:s', time() - LOCKOUT_TIME))]
            ]);
            if ($recent >= MAX_LOGIN_ATTEMPTS) {
                errorResponse('Too many failed attempts. Account temporarily locked. Try again in 15 minutes.', 429);
            }
        }
        errorResponse('Invalid email or password', 401);
    }
    if (($user['status'] ?? 'active') !== 'active') {
        errorResponse('Account is ' . ($user['status'] ?? 'disabled') . '. Contact administrator.', 403);
    }
    // Rehash if needed
    $passwordHash = $user['password_hash'] ?? ($user['password'] ?? '');
    if (password_needs_rehash($passwordHash, PASSWORD_BCRYPT, ['cost' => HASH_COST])) {
        $collection->updateOne(['_id' => $user['_id']], ['$set' => ['password_hash' => hashPassword($password)]]);
    }
    // Create session
    session_regenerate_id(true);
    $_SESSION['user_id'] = (string)$user['_id'];
    $_SESSION['user_role'] = $user['role'] ?? 'customer';
    $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $_SESSION['user_email'] = $user['email'] ?? $email;
    $_SESSION['last_activity'] = time();
    $_SESSION['created_at'] = time();
    // Update last login
    $collection->updateOne(['_id' => $user['_id']], [
        '$set' => ['last_login' => phpDateToMongo(), 'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? '']
    ]);
    logActivity('login', (string)$user['_id'], ['email' => $email]);
    // Evaluate + unlock any newly-earned achievements on login.
    require_once __DIR__ . '/../services/AchievementService.php';
    AchievementService::checkAndUnlock((string)$user['_id'], [
        'email_verified' => (bool)($user['is_verified'] ?? ($user['email_verified'] ?? false)),
        'has_wallet' => true,
    ]);
    successResponse([
        'user_id' => (string)$user['_id'],
        'name' => $_SESSION['user_name'],
        'email' => $user['email'] ?? $email,
        'role' => $user['role'] ?? 'customer',
        'phone' => $user['phone'] ?? '',
        'avatar' => !empty($user['avatar']) ? $user['avatar'] : $_SESSION['user_name'],
        'last_login' => isset($user['last_login']) ? mongoDateToPHP($user['last_login'])->format('Y-m-d H:i:s') : null
    ], 'Login successful');
}

function register() {
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $firstName = sanitizeInput($data['first_name'] ?? '');
    $lastName = sanitizeInput($data['last_name'] ?? '');
    $email = sanitizeInput($data['email'] ?? '');
    $phone = sanitizeInput($data['phone'] ?? '');
    $password = $data['password'] ?? '';
    if (empty($firstName) || empty($email)) errorResponse('Name and email are required');
    if (!validateEmail($email)) errorResponse('Invalid email format');
    if (strlen($password) < PASSWORD_MIN_LENGTH) errorResponse('Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters');
    $collection = getCollection('users');
    if (!$collection) errorResponse('Database connection error');
    $existing = $collection->findOne(['email' => $email, 'deleted_at' => null]);
    if ($existing) errorResponse('Email already registered', 409);
    // Create default wallets/categories for new user
    $doc = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone,
        'password_hash' => hashPassword($password),
        'role' => 'customer',
        'status' => 'active',
        'balance' => 0.0,
        'created_at' => phpDateToMongo(),
        'updated_at' => phpDateToMongo(),
        'last_login' => null,
        'last_login_ip' => '',
        'deleted_at' => null
    ];
    $result = $collection->insertOne($doc);
    if (!$result->getInsertedId()) errorResponse('Registration failed');
    $userId = (string)$result->getInsertedId();
    // Create default categories
    $catsCollection = getCollection('categories');
    if ($catsCollection) {
        $defaultCats = ['Food', 'Transport', 'Shopping', 'Bills', 'Entertainment', 'Health', 'Income', 'Savings'];
        foreach ($defaultCats as $catName) {
            $catsCollection->insertOne([
                'user_id' => new MongoDB\BSON\ObjectId($userId),
                'name' => $catName,
                'type' => in_array($catName, ['Income', 'Savings'], true) ? 'income' : 'expense',
                'created_at' => phpDateToMongo(),
                'deleted_at' => null
            ]);
        }
    }
    // Create default wallet
    $walletCollection = getCollection('wallets');
    if ($walletCollection) {
        $walletCollection->insertOne([
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'name' => 'Main Account',
            'balance' => 0.0,
            'currency' => 'INR',
            'created_at' => phpDateToMongo(),
            'deleted_at' => null
        ]);
    }
    // Welcome notification
    createNotification($userId, 'account', 'Welcome to SecureSOT', 'Your account has been created successfully. Welcome aboard!');
    logActivity('register', $userId, ['email' => $email]);
    // Send OTP to verify email before the user can sign in
    require_once __DIR__ . '/../helpers/OtpHelper.php';
    $otp = generate_otp_code();
    $otpStored = store_otp($userId, 'verify_email', $otp);
    $emailSent = false;
    if ($otpStored) {
        $emailSent = send_otp_email($email, trim($firstName . ' ' . $lastName), $otp, 'verify_email');
    }
    if (!$emailSent) {
        error_log("[Auth] OTP email failed for {$email} — returning OTP in response as fallback");
    }
    // Auto-login the user (they can verify OTP from the OTP screen)
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = 'customer';
    $_SESSION['user_name'] = trim($firstName . ' ' . $lastName);
    $_SESSION['user_email'] = $email;
    $_SESSION['last_activity'] = time();
    $_SESSION['created_at'] = time();
    successResponse([
        'user_id' => $userId,
        'name' => $_SESSION['user_name'],
        'email' => $email,
        'role' => 'customer',
        'needs_otp' => true,
        'email_sent' => $emailSent,
        'dev_otp' => $emailSent ? '' : $otp
    ], $emailSent
        ? 'Registration successful! Enter the OTP sent to your email.'
        : 'Registration successful! Email delivery failed — use the OTP shown below to verify.');
}

function logout() {
    requireActiveSession();
    logActivity('logout', getCurrentUserId());
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    successResponse(null, 'Logged out successfully');
}

function forgotPassword() {
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $email = sanitizeInput($data['email'] ?? '');
    if (empty($email) || !validateEmail($email)) errorResponse('Enter a valid email address');
    $collection = getCollection('users');
    if (!$collection) errorResponse('Database connection error');
    $user = $collection->findOne(['email' => $email, 'deleted_at' => null]);
    // Always return success to prevent email enumeration
    if ($user) {
        $token = bin2hex(random_bytes(32));
        $tokenExpiry = time() + 3600; // 1 hour
        $tokensCollection = getCollection('password_resets');
        if ($tokensCollection) {
            // Invalidate old tokens
            $tokensCollection->deleteMany(['email' => $email]);
            $tokensCollection->insertOne([
                'email' => $email,
                'user_id' => new MongoDB\BSON\ObjectId((string)$user['_id']),
                'token_hash' => hash('sha256', $token),
                'expires_at' => phpDateToMongo(date('Y-m-d H:i:s', $tokenExpiry)),
                'used' => false,
                'created_at' => phpDateToMongo()
            ]);
        }
        logActivity('forgot_password', (string)$user['_id'], ['email' => $email]);
    }
    successResponse(['reset_token' => $user ? $token ?? '' : ''], 'If the email exists, a reset link has been sent.');
}

function resetPassword() {
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $token = $data['token'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    if (empty($token)) errorResponse('Reset token is required');
    if (strlen($newPassword) < PASSWORD_MIN_LENGTH) errorResponse('Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters');
    $tokensCollection = getCollection('password_resets');
    if (!$tokensCollection) errorResponse('Database connection error');
    $reset = $tokensCollection->findOne([
        'token_hash' => hash('sha256', $token),
        'used' => false,
        'expires_at' => ['$gte' => phpDateToMongo()]
    ]);
    if (!$reset) errorResponse('Invalid or expired reset token', 400);
    $usersCollection = getCollection('users');
    if (!$usersCollection) errorResponse('Database connection error');
    $user = $usersCollection->findOne(['email' => $reset['email'] ?? '', 'deleted_at' => null]);
    if (!$user) errorResponse('User not found', 404);
    $usersCollection->updateOne(['_id' => $user['_id']], [
        '$set' => ['password_hash' => hashPassword($newPassword), 'updated_at' => phpDateToMongo()]
    ]);
    $tokensCollection->updateOne(['_id' => $reset['_id']], ['$set' => ['used' => true]]);
    logActivity('password_reset', (string)$user['_id'], ['email' => $reset['email'] ?? '']);
    successResponse(null, 'Password reset successful. You can now login.');
}

function getSession() {
    requireActiveSession();
    successResponse([
        'user_id' => getCurrentUserId(),
        'name' => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'role' => getCurrentUserRole()
    ], 'Session active');
}

function verifyEmail() {
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $userId = sanitizeInput($data['user_id'] ?? '');
    $token = sanitizeInput($data['token'] ?? '');
    $isResend = !empty($data['resend']);
    if (empty($userId) || !isValidObjectId($userId)) errorResponse('Invalid user id');
    $usersCollection = getCollection('users');
    if (!$usersCollection) errorResponse('Database connection error');
    $user = $usersCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId), 'deleted_at' => null]);
    if (!$user) errorResponse('User not found', 404);
    if ($isResend) {
        $verifyToken = bin2hex(random_bytes(32));
        $tokensCollection = getCollection('email_verifications');
        if (!$tokensCollection) errorResponse('Database connection error');
        $tokensCollection->deleteMany(['user_id' => new MongoDB\BSON\ObjectId($userId)]);
        $tokensCollection->insertOne([
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'email' => $user['email'] ?? '',
            'token_hash' => hash('sha256', $verifyToken),
            'expires_at' => phpDateToMongo(date('Y-m-d H:i:s', time() + 3600)),
            'used' => false,
            'created_at' => phpDateToMongo()
        ]);
        logActivity('email_verification_resend', $userId);
        successResponse([
            'verify_link' => BASE_URL . 'frontend/auth/verify-email.html?uid=' . $userId . '&token=' . $verifyToken
        ], 'Verification link generated');
    }
    if (empty($token)) errorResponse('Verification token is required');
    $tokensCollection = getCollection('email_verifications');
    if (!$tokensCollection) errorResponse('Database connection error');
    $verification = $tokensCollection->findOne([
        'user_id' => new MongoDB\BSON\ObjectId($userId),
        'token_hash' => hash('sha256', $token),
        'used' => false,
        'expires_at' => ['$gte' => phpDateToMongo()]
    ]);
    if (!$verification) errorResponse('Invalid or expired verification token', 400);
    $usersCollection->updateOne(['_id' => new MongoDB\BSON\ObjectId($userId)], [
        '$set' => ['email_verified' => true, 'email_verified_at' => phpDateToMongo(), 'updated_at' => phpDateToMongo()]
    ]);
    $tokensCollection->updateOne(['_id' => $verification['_id']], ['$set' => ['used' => true]]);
    logActivity('email_verified', $userId);
    successResponse(null, 'Email verified successfully');
}

function sendOtp() {
    require_once __DIR__ . '/../helpers/OtpHelper.php';
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $email = sanitizeInput($data['email'] ?? '');
    $purpose = sanitizeInput($data['purpose'] ?? 'verify_email');
    if (empty($email) || !validateEmail($email)) errorResponse('Enter a valid email address');
    $usersCollection = getCollection('users');
    if (!$usersCollection) errorResponse('Database connection error');
    $user = $usersCollection->findOne(['email' => $email, 'deleted_at' => null]);
    if (!$user) errorResponse('No account found with that email', 404);
    $userId = (string)$user['_id'];
    $otp = generate_otp_code();
    if (!store_otp($userId, $purpose, $otp)) errorResponse('Could not store OTP', 500);
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $emailSent = send_otp_email($email, $name ?: 'User', $otp, $purpose);
    logActivity('otp_sent', $userId, ['purpose' => $purpose, 'email_delivered' => $emailSent]);
    if (!$emailSent) {
        error_log("[Auth] OTP email failed for {$email} (purpose={$purpose}) — returning OTP as fallback");
    }
    successResponse([
        'email_delivered' => $emailSent,
        'user_id' => $userId,
        'dev_otp' => $emailSent ? '' : $otp
    ], $emailSent
        ? 'OTP sent to your email'
        : 'Email delivery failed. Use the OTP shown to verify.');
}

function verifyOtp() {
    require_once __DIR__ . '/../helpers/OtpHelper.php';
    $data = getJSONRequest();
    if (!$data) errorResponse('Invalid request format');
    $userId = sanitizeInput($data['user_id'] ?? '');
    $purpose = sanitizeInput($data['purpose'] ?? 'verify_email');
    $otpInput = preg_replace('/\D/', '', (string)($data['otp'] ?? ''));
    if (empty($userId) || !isValidObjectId($userId)) errorResponse('Invalid user id');
    if (strlen($otpInput) < 6) errorResponse('Enter the 6-digit OTP');
    $result = verify_otp_code($userId, $purpose, $otpInput);
    if (!$result['ok']) errorResponse($result['message'], 400);
    if ($purpose === 'verify_email') {
        $usersCollection = getCollection('users');
        if ($usersCollection) {
            $usersCollection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($userId)],
                ['$set' => ['email_verified' => true, 'email_verified_at' => phpDateToMongo()]]
            );
        }
    }
    logActivity('otp_verified', $userId, ['purpose' => $purpose]);
    if ($purpose === 'forgot_password') {
        $usersCollection = getCollection('users');
        $user = $usersCollection ? $usersCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]) : null;
        $tokensCollection = getCollection('password_resets');
        if ($user && $tokensCollection) {
            $tokensCollection->deleteMany(['user_id' => new MongoDB\BSON\ObjectId($userId), 'used' => false]);
            $resetToken = bin2hex(random_bytes(32));
            $tokensCollection->insertOne([
                'email' => $user['email'] ?? '',
                'user_id' => new MongoDB\BSON\ObjectId($userId),
                'token_hash' => hash('sha256', $resetToken),
                'expires_at' => phpDateToMongo(date('Y-m-d H:i:s', time() + 1800)),
                'used' => false,
                'created_at' => phpDateToMongo()
            ]);
            successResponse(['reset_token' => $resetToken], $result['message']);
            return;
        }
    }
    successResponse(null, $result['message']);
}
