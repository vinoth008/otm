# Architecture

## Overview

Smart Transaction Control (STC) is a role-based banking/finance platform supporting **4 roles**: `admin`, `staff`, `receptionist`, and `customer`. It is built as a classic LAMP-style web application (XAMPP) with a **MongoDB Atlas** backend, PHP business/API layer, and optional Java (business logic) and Python (AI analytics) modules.

## High-Level Layers

```
┌─────────────────────────────────────────────────────┐
│                    Frontend (HTML/CSS/JS)           │
│  role-select.html → login-*.html → dashboard pages   │
└──────────────┬──────────────────────────────────────┘
               │  fetch() / apiPost()
┌──────────────▼──────────────────────────────────────┐
│                 PHP API Layer                       │
│  A) backend/api/index.php  (module router, auth pages)│
│  B) backend/php/*.php      (direct endpoints, app)    │
│  C) backend/api/<role>/*.php (standalone endpoints)   │
└──────────────┬──────────────────────────────────────┘
               │  mongodb/mongodb driver
┌──────────────▼──────────────────────────────────────┐
│               MongoDB Atlas (single DB)             │
│        smart_transaction_control                     │
└─────────────────────────────────────────────────────┘
```

## Backend API Architectures

Three API surfaces coexist, all backed by the same MongoDB database:

1. **Module router** — `backend/api/index.php?module=<name>&action=<action>`.
   Used by `frontend/auth/*.html` via `frontend/assets/js/api.js`.
   Modules: auth, transactions, expenses, budgets, wallets, users, goals,
   notifications, settings, profile, dashboard, reports, categories,
   complaints, audit, notes, appointments, analytics, recurring, reminders.

2. **Direct file endpoints** — `backend/php/*.php?action=<action>`.
   Used by `frontend/html/*.html` via `frontend/js/app_common.js` (`STC.get`).
   Includes `auth.php`, `user_crud.php`, `wallet_crud.php`, `transaction_crud.php`,
   `security.php`, `session_manager.php`.

3. **Standalone role endpoints** — `backend/api/<role-or-feature>/*.php`.
   Self-contained endpoints for admin, auth, complaints, customer, expenses,
   notes, notifications, receptionist, reports, settings, staff, transactions.
   Each bootstraps `config.php` + `security.php` + `session_manager.php` and
   uses `getCollection()` + `successResponse()`/`errorResponse()`.

## Key Configuration Files

| File | Purpose |
|------|---------|
| `config.php` (root) | MongoDB URI, DB name, base URL, session, `getCollection()` |
| `.env` | SMTP (Gmail) + MongoDB URI + security constants |
| `backend/config/constants.php` | Security/timezone/upload constants |
| `backend/config/database.php` | `getMongoClient()`, `getMongoDB()`, `getCollection()`, `db()` |
| `backend/config/mail.php` | Loads `.env`, defines `SMTP_*` constants |
| `backend/config/security.php` | CSRF helpers, `start_secure_session()` |
| `backend/php/security.php` | Input validation, hashing, responses, rate limiting |
| `backend/php/session_manager.php` | Role-aware session guards (`requireRole`) |

## Session Model

- Two cookie names exist: `SOT_SESSION` (module router / auth pages) and
  `STC_SESSION` (backend/php endpoints / app pages).
- Sessions store `user_id`, `user_role`, `user_name`, `user_email`, and activity timestamps.
- Role guards are enforced server-side via `requireRole()`.

## Email / OTP Flow

1. User registers → `register()` creates a user and a 6-digit OTP.
2. `OtpHelper` stores the OTP in `otp_verifications` (hash, purpose, expiry).
3. `EmailService` sends the OTP over Gmail SMTP (STARTTLS, no external library).
4. User submits OTP → verified, email marked verified, session created.
5. Forgot password → reset token generated, verified, password updated.

## Data Flow Example (Customer Transaction)

1. `dashboard.html` calls `backend/php/transaction_crud.php?action=create`.
2. Handler validates + checks `session_manager.php` auth.
3. Inserts into `transactions` collection and updates the user/wallet `balance`.
4. `logActivity('transaction_created', ...)` writes to `activity_logs`.
5. Returns `{ success, message, data }` to the frontend.
