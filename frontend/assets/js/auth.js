/**
 * auth.js — Login, Register, OTP, Forgot Password logic
 */

document.addEventListener('DOMContentLoaded', () => {

  // ── Role selector ───────────────────────────────────────────
  const roleCards = document.querySelectorAll('.role-card');
  let selectedRole = 'admin';
  roleCards.forEach(card => {
    card.addEventListener('click', () => {
      roleCards.forEach(c => c.classList.remove('selected'));
      card.classList.add('selected');
      selectedRole = card.dataset.role;
    });
  });
  // Set first card active
  if (roleCards.length) {
    roleCards[0].classList.add('selected');
  }

  // ── Password toggle ────────────────────────────────────────
  document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', () => {
      const inp = btn.closest('.auth-input-wrap').querySelector('input');
      const icon = btn.querySelector('i');
      if (inp.type === 'password') { inp.type = 'text'; icon.classList.replace('fa-eye','fa-eye-slash'); }
      else { inp.type = 'password'; icon.classList.replace('fa-eye-slash','fa-eye'); }
    });
  });

  // ── Login Form ─────────────────────────────────────────────
  const loginForm = document.getElementById('login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', async e => {
      e.preventDefault();
      const email    = document.getElementById('email').value.trim();
      const password = document.getElementById('password').value;
      const btnText  = document.getElementById('btn-login-text');
      const spinner  = document.getElementById('btn-spinner');

      if (!email || !password) { showToast('Please enter email and password', 'error'); return; }

      if (btnText)  btnText.textContent = 'Signing in...';
      if (spinner)  spinner.classList.remove('hidden');

      try {
        const res = await apiPost('?module=auth&action=login', { email, password });
        const user = res.data;
        if (selectedRole && user.role !== selectedRole) {
          showToast(`You selected "${capitalise(selectedRole)}" but this account is "${capitalise(user.role)}"`, 'warning');
          if (btnText) btnText.textContent = 'Sign In';
          if (spinner) spinner.classList.add('hidden');
          return;
        }
        Auth.login({ id: user.user_id, name: user.name, email: user.email, role: user.role, avatar: user.name.slice(0,2).toUpperCase() });
        showToast(`Welcome back, ${user.name}!`, 'success');
        setTimeout(() => {
          window.location.href = ROLE_DASHBOARD[user.role] || '../admin/dashboard.html';
        }, 800);
      } catch (err) {
        showToast(err.message || 'Login failed', 'error');
        if (btnText) btnText.textContent = 'Sign In';
        if (spinner) spinner.classList.add('hidden');
      }
    });
  }

  // ── Register Form ──────────────────────────────────────────
  const regForm = document.getElementById('register-form');
  if (regForm) {
    regForm.addEventListener('submit', async e => {
      e.preventDefault();
      const name  = document.getElementById('full-name').value.trim();
      const email = document.getElementById('email').value.trim();
      const pass  = document.getElementById('password').value;
      const conf  = document.getElementById('confirm-password').value;
      const phone = (document.getElementById('phone') || {}).value || '';
      if (!name || !email || !pass) { showToast('All fields are required', 'error'); return; }
      if (pass !== conf) { showToast('Passwords do not match', 'error'); return; }
      if (pass.length < 8) { showToast('Password must be at least 8 characters', 'error'); return; }
      const nameParts = name.split(' ');
      try {
        const res = await apiPost('?module=auth&action=register', {
          first_name: nameParts[0] || '', last_name: nameParts.slice(1).join(' '), email, password: pass, phone
        });
        const user = res.data;
        if (user.needs_otp) {
          // New account must verify email via OTP before accessing the dashboard
          sessionStorage.setItem('sot_otp_email', email);
          sessionStorage.setItem('sot_otp_user_id', user.user_id);
          sessionStorage.setItem('sot_otp_purpose', 'verify_email');
          if (user.dev_otp) sessionStorage.setItem('sot_dev_otp', user.dev_otp);
          showToast('Account created! Enter the OTP sent to your email.', 'success');
          setTimeout(() => window.location.href = 'otp-verify.html', 1200);
          return;
        }
        Auth.login({ id: user.user_id, name: user.name, email: user.email, role: user.role, avatar: user.name.slice(0,2).toUpperCase() });
        showToast('Account created successfully!', 'success');
        setTimeout(() => window.location.href = ROLE_DASHBOARD[user.role] || '../customer/dashboard.html', 1200);
      } catch (err) {
        showToast(err.message || 'Registration failed', 'error');
      }
    });
  }

  // ── Forgot Password Form ───────────────────────────────────
  const forgotForm = document.getElementById('forgot-form');
  if (forgotForm) {
    forgotForm.addEventListener('submit', async e => {
      e.preventDefault();
      const email = document.getElementById('email').value.trim();
      if (!email) { showToast('Please enter your email', 'error'); return; }
      try {
        const res = await apiPost('?module=auth&action=send_otp', { email, purpose: 'forgot_password' });
        sessionStorage.setItem('sot_otp_email', email);
        sessionStorage.setItem('sot_otp_user_id', res.data.user_id);
        sessionStorage.setItem('sot_otp_purpose', 'forgot_password');
        if (res.data.dev_otp) sessionStorage.setItem('sot_dev_otp', res.data.dev_otp);
        showToast('OTP sent! Check your email.', 'success');
        setTimeout(() => window.location.href = 'otp-verify.html', 1200);
      } catch (err) {
        showToast(err.message || 'Request failed', 'error');
      }
    });
  }

  // ── OTP Inputs ─────────────────────────────────────────────
  const otpInputs = document.querySelectorAll('.otp-input');
  otpInputs.forEach((inp, idx) => {
    inp.addEventListener('input', () => {
      if (inp.value.length === 1 && idx < otpInputs.length - 1) otpInputs[idx+1].focus();
    });
    inp.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !inp.value && idx > 0) otpInputs[idx-1].focus();
    });
  });

  const otpForm = document.getElementById('otp-form');
  if (otpForm) {
    // Auto-submit when all 6 digits filled
    otpInputs.forEach((inp, idx) => {
      inp.addEventListener('input', () => {
        if (inp.value.length === 1 && idx < otpInputs.length - 1) otpInputs[idx + 1].focus();
        const allFilled = [...otpInputs].every(i => i.value);
        if (allFilled) otpForm.dispatchEvent(new Event('submit'));
      });
    });

    otpForm.addEventListener('submit', async e => {
      e.preventDefault();
      const otp = [...otpInputs].map(i => i.value).join('');
      if (otp.length < 6) { showToast('Enter all 6 digits', 'error'); return; }

      const userId = sessionStorage.getItem('sot_otp_user_id');
      const purpose = sessionStorage.getItem('sot_otp_purpose') || 'verify_email';

      if (!userId) {
        showToast('Session expired. Please request a new OTP.', 'error');
        setTimeout(() => window.location.href = 'forgot-password.html', 1200);
        return;
      }

      try {
        const res = await apiPost('?module=auth&action=verify_otp', { user_id: userId, purpose, otp });
        showToast(res.message || 'OTP verified!', 'success');
        sessionStorage.removeItem('sot_otp_user_id');
        sessionStorage.removeItem('sot_otp_purpose');
        // For forgot_password resets, store the real reset token returned by the backend
        if (res.data && res.data.reset_token) {
          sessionStorage.setItem('sot_reset_token', res.data.reset_token);
        }
        const resetToken = sessionStorage.getItem('sot_reset_token');
        if (resetToken && purpose === 'forgot_password') {
          setTimeout(() => window.location.href = 'reset-password.html', 1000);
        } else {
          setTimeout(() => window.location.href = 'login.html', 1000);
        }
      } catch (err) {
        showToast(err.message || 'OTP verification failed', 'error');
        otpInputs.forEach(i => i.value = '');
        otpInputs[0].focus();
      }
    });

    const resendLink = document.getElementById('resend-otp');
    if (resendLink) {
      resendLink.addEventListener('click', async e => {
        e.preventDefault();
        const email = sessionStorage.getItem('sot_otp_email');
        if (!email) { showToast('No email stored. Please request again.', 'error'); return; }
        try {
          const purpose = sessionStorage.getItem('sot_otp_purpose') || 'verify_email';
          const res = await apiPost('?module=auth&action=send_otp', { email, purpose });
          if (res.data && res.data.dev_otp) sessionStorage.setItem('sot_dev_otp', res.data.dev_otp);
          showToast('New OTP sent to your email!', 'success');
        } catch (err) {
          showToast(err.message || 'Resend failed', 'error');
        }
      });
    }
  }

  // ── Reset Password ─────────────────────────────────────────
  const resetForm = document.getElementById('reset-form');
  if (resetForm) {
    resetForm.addEventListener('submit', async e => {
      e.preventDefault();
      const pass = document.getElementById('password').value;
      const conf = document.getElementById('confirm-password').value;
      if (pass !== conf) { showToast('Passwords do not match', 'error'); return; }
      if (pass.length < 8) { showToast('Min 8 characters required', 'error'); return; }
      const token = sessionStorage.getItem('sot_reset_token') || '';
      try {
        await apiPost('?module=auth&action=reset_password', { token, new_password: pass });
        sessionStorage.removeItem('sot_reset_token');
        showToast('Password reset successful!', 'success');
        setTimeout(() => window.location.href = 'login.html', 1200);
      } catch (err) {
        showToast(err.message || 'Reset failed', 'error');
      }
    });
  }
});