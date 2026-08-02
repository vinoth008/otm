// frontend/js/auth.js
/**
 * Smart Transaction Control - Authentication Logic
 * Handles login and register only.
 * OTP / email verification / password reset via email have been completely removed.
 */
// auth.js runs on the login page (site root /index.php) AND inside frontend/html pages.
// Resolve API/redirect paths relative to the current page depth.
const AUTH_IS_DEEP = window.location.pathname.indexOf('/frontend/html/') !== -1;
const API_BASE = AUTH_IS_DEEP ? '../../backend/php/' : 'backend/php/';
// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function () {
    initAuthForms();
    initThemeToggle();
    initPasswordStrength();
    loadStoredTheme();
});
// ============================================
// FORM HANDLING
// ============================================
/**
 * Initialize authentication forms
 */
function initAuthForms() {
    // Tab switching
    const tabs = document.querySelectorAll('.auth-tab');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => switchTab(tab.dataset.tab));
    });
    // Login form
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', handleLogin);
    }
    // Register form
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', handleRegister);
    }
}
/**
 * Switch between login and register tabs
 */
function switchTab(tabName) {
    const tabs = document.querySelectorAll('.auth-tab');
    const forms = document.querySelectorAll('.auth-form');
    tabs.forEach(tab => {
        tab.classList.remove('active');
        if (tab.dataset.tab === tabName) {
            tab.classList.add('active');
        }
    });
    forms.forEach(form => {
        form.classList.remove('active');
        if (form.id === tabName + 'Form' ||
            (tabName === 'login' && form.id === 'loginForm') ||
            (tabName === 'register' && form.id === 'registerForm')) {
            form.classList.add('active');
        }
    });
    // Clear error messages
    clearErrors();
}
/**
 * Handle login form submission
 */
async function handleLogin(e) {
    e.preventDefault();
    const form = e.target;
    const email = form.email.value.trim();
    const password = form.password.value;
    const remember = form.remember.checked;
    const errorDiv = document.getElementById('loginError');
    // Clear previous errors
    errorDiv.style.display = 'none';
    errorDiv.textContent = '';
    // Validate
    if (!email || !password) {
        showError(errorDiv, 'Please enter both email and password');
        return;
    }
    try {
        // Show loading state
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner spinner-sm"></span> Logging in...</span>';
        // Call API
        const response = await fetch(API_BASE + 'auth.php?action=login', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                email: email,
                password: password,
                remember: remember,
                csrf_token: getCookie('csrf_token') || ''
            })
        });
        const data = await response.json();
        if (data.success) {
            // Redirect to role-based dashboard
            window.location.href = data.data.redirect || 'frontend/html/user/dashboard.html';
        } else {
            showError(errorDiv, data.error || 'Invalid credentials');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    } catch (error) {
        console.error('Login error:', error);
        showError(errorDiv, 'Network error. Please check your connection.');
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
}
/**
 * Handle registration form submission
 */
async function handleRegister(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const data = {
        first_name: formData.get('first_name')?.trim(),
        last_name: formData.get('last_name')?.trim(),
        email: formData.get('email')?.trim().toLowerCase(),
        phone: formData.get('phone')?.trim(),
        department: formData.get('department')?.trim() || 'General',
        password: formData.get('password'),
        terms: formData.get('terms') === 'on',
        csrf_token: getCookie('csrf_token') || ''
    };
    const errorDiv = document.getElementById('registerError');
    const successDiv = document.getElementById('registerSuccess');
    // Clear messages
    clearErrors();
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    // Validate
    if (!data.first_name || !data.last_name || !data.email || !data.phone || !data.password) {
        showError(errorDiv, 'All fields are required');
        return;
    }
    if (!validateEmail(data.email)) {
        showError(errorDiv, 'Please enter a valid email address');
        return;
    }
    if (!validatePhone(data.phone)) {
        showError(errorDiv, 'Please enter a valid 10-digit phone number');
        return;
    }
    if (data.password.length < 8) {
        showError(errorDiv, 'Password must be at least 8 characters');
        return;
    }
    if (!data.terms) {
        showError(errorDiv, 'You must agree to the Terms of Service');
        return;
    }
    try {
        // Show loading state
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner spinner-sm"></span> Creating Account...</span>';
        // Call API
        const response = await fetch(API_BASE + 'auth.php?action=register', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.success) {
            // Success - no OTP required, auto-login and redirect
            successDiv.textContent = 'Account created successfully! Redirecting...';
            successDiv.style.display = 'block';
            setTimeout(() => {
                window.location.href = result.data.redirect || 'frontend/html/user/dashboard.html';
            }, 1000);
        } else {
            showError(errorDiv, result.error || 'Registration failed');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    } catch (error) {
        console.error('Register error:', error);
        showError(errorDiv, 'Network error. Please try again.');
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
}
// ============================================
// UTILITY FUNCTIONS
// ============================================
/**
 * Toggle password visibility
 */
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = input.nextElementSibling.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
/**
 * Initialize password strength meter
 */
function initPasswordStrength() {
    const passwordInput = document.getElementById('registerPassword');
    if (passwordInput) {
        passwordInput.addEventListener('input', (e) => {
            const password = e.target.value;
            const strength = calculatePasswordStrength(password);
            updatePasswordStrengthUI(strength);
        });
    }
}
/**
 * Calculate password strength
 */
function calculatePasswordStrength(password) {
    let score = 0;
    if (password.length >= 8) score++;
    if (password.length >= 12) score++;
    if (/[a-z]/.test(password)) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^a-zA-Z0-9]/.test(password)) score++;
    return score;
}
/**
 * Update password strength UI
 */
function updatePasswordStrengthUI(strength) {
    const strengthBar = document.querySelector('.strength-bar');
    const strengthText = document.querySelector('.strength-text');
    if (!strengthBar || !strengthText) return;
    const colors = ['#ef4444', '#f97316', '#f59e0b', '#fbbf24', '#84cc16', '#10b981'];
    const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very Strong'];
    const index = Math.min(strength, 5);
    strengthBar.style.width = ((index + 1) / 6 * 100) + '%';
    strengthBar.style.backgroundColor = colors[index];
    strengthText.textContent = labels[index];
    strengthText.style.color = colors[index];
}
/**
 * Initialize theme toggle
 */
function initThemeToggle() {
    const themeToggle = document.getElementById('themeToggle');
    if (!themeToggle) return;
    const savedTheme = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeToggleIcon(savedTheme);
    themeToggle.addEventListener('click', () => {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateThemeToggleIcon(newTheme);
    });
}
/**
 * Load stored theme
 */
function loadStoredTheme() {
    const savedTheme = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeToggleIcon(savedTheme);
}
/**
 * Update theme toggle icon
 */
function updateThemeToggleIcon(theme) {
    const themeToggle = document.getElementById('themeToggle');
    if (!themeToggle) return;
    const moonIcon = themeToggle.querySelector('.fa-moon');
    const sunIcon = themeToggle.querySelector('.fa-sun');
    if (theme === 'dark') {
        moonIcon.style.display = 'none';
        sunIcon.style.display = 'block';
    } else {
        moonIcon.style.display = 'block';
        sunIcon.style.display = 'none';
    }
}
// ============================================
// VALIDATION HELPERS
// ============================================
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}
function validatePhone(phone) {
    const re = /^[6-9][0-9]{9}$/;
    return re.test(phone.replace(/\D/g, ''));
}
function showError(element, message) {
    element.textContent = message;
    element.style.display = 'block';
    element.classList.add('show');
}
function clearErrors() {
    const errorDivs = document.querySelectorAll('.error-message, .success-message');
    errorDivs.forEach(div => {
        div.style.display = 'none';
        div.textContent = '';
    });
}