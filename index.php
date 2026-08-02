<?php
// index.php
/**
 * Smart Transaction Control - Main Entry Point
 * Routes users to appropriate pages based on authentication state.
 * OTP / email verification / SMTP features have been completely removed.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/php/security.php';
require_once __DIR__ . '/backend/php/session_manager.php';
// Check if user is logged in
$isLoggedIn = isLoggedIn();
$userTheme = $_SESSION['user_theme'] ?? 'dark';
// Establish CSRF token for login/register forms
generateCSRFToken();
// Determine redirect
if ($isLoggedIn) {
    header('Location: ' . getRoleDashboardUrl());
    exit;
}
// Show login page for non-authenticated users
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo e($userTheme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Transaction Control - Login</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>frontend/css/main.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>frontend/css/components.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>frontend/css/animations.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>frontend/css/responsive.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>frontend/css/mini_theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <!-- Left Side - Branding -->
        <div class="auth-branding">
            <div class="brand-content">
                <div class="logo">
                    <i class="fas fa-wallet"></i>
                    <h1>Smart Transaction Control</h1>
                </div>
                <p class="tagline">Track. Manage. Save. Grow.</p>
                <div class="features-list">
                    <div class="feature-item">
                        <i class="fas fa-chart-line"></i>
                        <span>Advanced Analytics</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-shield-alt"></i>
                        <span>Secure & Private</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-bullseye"></i>
                        <span>Goal-Based Savings</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-cogs"></i>
                        <span>Role-Based Access</span>
                    </div>
                </div>
            </div>
        </div>
        <!-- Right Side - Login Form -->
        <div class="auth-form-container">
            <div class="auth-form-wrapper">
                <div class="theme-toggle" id="themeToggle">
                    <i class="fas fa-moon"></i>
                    <i class="fas fa-sun"></i>
                    <div class="toggle-switch"></div>
                </div>
                <div class="auth-tabs">
                    <button class="auth-tab active" data-tab="login">Login</button>
                    <button class="auth-tab" data-tab="register">Register</button>
                </div>
                <!-- Role Selector -->
                <div class="role-grid" id="roleGrid">
                    <div class="role-card role-admin selected" data-role="admin" title="Sign in as Admin">
                        <i class="fas fa-user-tie"></i>
                        <span>Admin</span>
                    </div>
                    <div class="role-card role-staff" data-role="manager" title="Sign in as Manager">
                        <i class="fas fa-user-gear"></i>
                        <span>Manager</span>
                    </div>
                    <div class="role-card role-customer" data-role="user" title="Sign in as Employee">
                        <i class="fas fa-user"></i>
                        <span>Employee</span>
                    </div>
                    <div class="role-card role-recept" data-role="auditor" title="Sign in as Auditor">
                        <i class="fas fa-search"></i>
                        <span>Auditor</span>
                    </div>
                </div>
                <!-- Login Form -->
                <form id="loginForm" class="auth-form active">
                    <h2>Welcome Back</h2>
                    <p class="form-subtitle">Enter your credentials to access your account</p>
                    <div class="form-group">
                        <label for="loginEmail">
                            <i class="fas fa-envelope"></i>
                            Email Address
                        </label>
                        <input
                            type="email"
                            id="loginEmail"
                            name="email"
                            placeholder="your@email.com"
                            required
                            autocomplete="email"
                        >
                    </div>
                    <div class="form-group">
                        <label for="loginPassword">
                            <i class="fas fa-lock"></i>
                            Password
                        </label>
                        <div class="password-input-wrapper">
                            <input
                                type="password"
                                id="loginPassword"
                                name="password"
                                placeholder="Enter your password"
                                required
                                autocomplete="current-password"
                            >
                            <button type="button" class="toggle-password" onclick="togglePassword('loginPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-options">
                        <label class="checkbox-wrapper">
                            <input type="checkbox" id="rememberMe" name="remember">
                            <span>Remember me</span>
                        </label>
                    </div>
                    <button type="submit" class="btn-primary btn-full">
                        <span>Login</span>
                        <i class="fas fa-arrow-right"></i>
                    </button>
                    <div id="loginError" class="error-message"></div>
                </form>
                <!-- Register Form -->
                <form id="registerForm" class="auth-form">
                    <h2>Create Account</h2>
                    <p class="form-subtitle">Register as an employee to manage your expenses</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="registerFirstName">
                                <i class="fas fa-user"></i>
                                First Name
                            </label>
                            <input
                                type="text"
                                id="registerFirstName"
                                name="first_name"
                                placeholder="John"
                                required
                                autocomplete="given-name"
                            >
                        </div>
                        <div class="form-group">
                            <label for="registerLastName">
                                <i class="fas fa-user"></i>
                                Last Name
                            </label>
                            <input
                                type="text"
                                id="registerLastName"
                                name="last_name"
                                placeholder="Doe"
                                required
                                autocomplete="family-name"
                            >
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="registerEmail">
                            <i class="fas fa-envelope"></i>
                            Email Address
                        </label>
                        <input
                            type="email"
                            id="registerEmail"
                            name="email"
                            placeholder="your@email.com"
                            required
                            autocomplete="email"
                        >
                    </div>
                    <div class="form-group">
                        <label for="registerPhone">
                            <i class="fas fa-phone"></i>
                            Phone Number
                        </label>
                        <input
                            type="tel"
                            id="registerPhone"
                            name="phone"
                            placeholder="9876543210"
                            pattern="[6-9][0-9]{9}"
                            title="Enter a valid 10-digit Indian mobile number"
                            required
                            autocomplete="tel"
                        >
                    </div>
                    <div class="form-group">
                        <label for="registerDepartment">
                            <i class="fas fa-building"></i>
                            Department
                        </label>
                        <select id="registerDepartment" name="department" required>
                            <option value="">Select Department</option>
                            <option value="General">General</option>
                            <option value="IT">IT</option>
                            <option value="Finance">Finance</option>
                            <option value="HR">HR</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Operations">Operations</option>
                            <option value="Sales">Sales</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="registerPassword">
                            <i class="fas fa-lock"></i>
                            Password
                        </label>
                        <div class="password-input-wrapper">
                            <input
                                type="password"
                                id="registerPassword"
                                name="password"
                                placeholder="Create a strong password"
                                required
                                autocomplete="new-password"
                                minlength="8"
                            >
                            <button type="button" class="toggle-password" onclick="togglePassword('registerPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="passwordStrength">
                            <div class="strength-bar"></div>
                            <span class="strength-text">Password strength</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-wrapper">
                            <input type="checkbox" id="agreeTerms" name="terms" required>
                            <span>I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a></span>
                        </label>
                    </div>
                    <button type="submit" class="btn-primary btn-full">
                        <span>Create Account</span>
                        <i class="fas fa-user-plus"></i>
                    </button>
                    <div id="registerError" class="error-message"></div>
                    <div id="registerSuccess" class="success-message"></div>
                </form>
                <div class="auth-footer">
                    <p>&copy; 2026 Smart Transaction Control. All rights reserved.</p>
                </div>
            </div>
        </div>
    </div>
    <script src="<?php echo BASE_URL; ?>frontend/js/utils.js"></script>
    <script src="<?php echo BASE_URL; ?>frontend/js/auth.js"></script>
    <script>
        (function () {
            var roleCards = document.querySelectorAll('.role-card');
            var creds = {
                admin: { email: 'admin@smarttransaction.com', password: 'Admin@12345' },
                manager: { email: 'manager@smarttransaction.com', password: 'Manager@12345' },
                user: { email: 'employee@smarttransaction.com', password: 'Employee@12345' },
                auditor: { email: 'auditor@smarttransaction.com', password: 'Auditor@12345' }
            };
            roleCards.forEach(function (card) {
                card.addEventListener('click', function () {
                    roleCards.forEach(function (c) { c.classList.remove('selected'); });
                    card.classList.add('selected');
                    var r = card.dataset.role;
                    var emailInput = document.getElementById('loginEmail');
                    var passInput = document.getElementById('loginPassword');
                    if (emailInput && creds[r]) emailInput.value = creds[r].email;
                    if (passInput && creds[r]) passInput.value = creds[r].password;
                });
            });
            // Auto-fill the default selected role (Admin)
            var first = roleCards.length ? roleCards[0].dataset.role : 'admin';
            var e = document.getElementById('loginEmail');
            var p = document.getElementById('loginPassword');
            if (e && creds[first]) e.value = creds[first].email;
            if (p && creds[first]) p.value = creds[first].password;
        })();
    </script>
</body>
</html>