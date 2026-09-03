# Security Notes

## Passwords
- Bcrypt hashing (`PASSWORD_BCRYPT`, cost 12) via `hashPassword()`.
- Password strength enforced: ≥8 chars, uppercase, lowercase, digit, special char.
- Wrong-password attempts increment counters and trigger temporary lockouts (`MAX_LOGIN_ATTEMPTS`, `LOCKOUT_TIME`).

## Sessions
- Server-side PHP sessions with `HttpOnly`, `SameSite=Strict` cookies.
- Session IDs regenerated on login.
- Cookie names `SOT_SESSION` (auth/module router) and `STC_SESSION` (app endpoints).
- Role-based authorization enforced server side with `requireRole()`.
- Idle-timeout / activity tracking via `checkSession()` / `updateSessionActivity()`.

## CSRF
- CSRF tokens generated per session (`generateCSRFToken()`), verified on state-changing requests (`verifyCSRFToken()`).
- Tokens mirrored to a non-HttpOnly cookie so the frontend can read them.

## XSS / Injection
- All user input sanitized with `sanitizeInput()` (HTML-escaped).
- Output escaping helper `e()`.
- MongoDB queries use the driver's parameterized query/document objects — no string concatenation into query syntax.
- Email, phone, date, and amount validated before use.

## Rate Limiting / Brute Force
- `checkRateLimit()` per IP + action.
- OTP verification limited to 5 attempts; `login_attempts` and `locked_until` fields cap repeated failures.
- Login history recorded in `activity_logs` / `login_history`.

## OTP / Email
- OTPs are 6-digit, time-limited (10 min), and stored hashed (sha256) via `OtpHelper`.
- OTPs are single-use (`is_used`), with `attempts` counter.
- Forgot-password responses never reveal whether an email is registered (anti-enumeration).

## File Uploads
- Only whitelisted extensions (`jpg`, `jpeg`, `png`, `pdf`) and MIME types.
- Size limited (`MAX_FILE_SIZE`).
- Files renamed to unique names on upload.

## Security Headers
- Set automatically by `setSecurityHeaders()`:
  `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `X-XSS-Protection`, `Referrer-Policy`, `Permissions-Policy`.

## Soft Delete
- `deleted_at` used consistently for recoverable data.

## Production Hardening Checklist
- [ ] Use HTTPS; set session cookie `secure => true`.
- [ ] Remove `APP_DEBUG=true`.
- [ ] Restrict MongoDB Atlas network access to your server IP (IP allow-list).
- [ ] Rotate the Gmail App Password and MongoDB URI if exposed.
- [ ] Do not commit `.env` with real secrets (it is gitignored).
