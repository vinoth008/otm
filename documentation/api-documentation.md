# API Documentation

All endpoints return JSON of the shape:

```json
{
  "success": true|false,
  "message": "Human-readable message",
  "data": { ... }
}
```

On error, HTTP 4xx/5xx is returned with `"success": false`.

## 1. Module Router

Base: `backend/api/index.php?module=<module>&action=<action>`

### auth
| action | method | purpose |
|--------|--------|---------|
| `register` | POST | create customer account, send OTP |
| `login` | POST | authenticate (email + password) |
| `logout` | POST | end session |
| `send_otp` | POST | send OTP to email |
| `verify_otp` | POST | validate OTP |
| `forgot_password` | POST | generate reset token |
| `reset_password` | POST | set new password |
| `verify_email` | POST | verify email via token |
| `get_session` | GET | current session info |

### transactions / expenses / budgets / categories / goals / wallets / notifications / profile / settings / dashboard / reports / complaints / audit / notes / appointments / analytics / reminders / recurring

Each supports action-specific CRUD using the same router pattern. For example:
```
POST /backend/api/index.php?module=transactions&action=create
GET  /backend/api/index.php?module=transactions&action=list
GET  /backend/api/index.php?module=wallets&action=get_all
POST /backend/api/index.php?module=wallets&action=transfer
GET  /backend/api/index.php?module=reports&action=export_csv
```

## 2. Direct File Endpoints

Base: `backend/php/*.php?action=<action>`

| file | actions |
|------|---------|
| `auth.php` | register, login, logout, change_password, forgot_password, reset_password, session_info, login_history, lock_user, unlock_user, admin_reset_password |
| `user_crud.php` | get/get_all/create/update/delete, update_profile, delete_account |
| `wallet_crud.php` | get_all, create, update, delete, transfer |
| `transaction_crud.php` | summary, create, get, update, delete |
| `security.php` | helpers only (no routes) |
| `session_manager.php` | helpers only (no routes) |

Example:
```
POST /backend/php/auth.php?action=login
GET  /backend/php/auth.php?action=session_info
```

## 3. Standalone Role Endpoints

Base: `backend/api/<group>/<file>.php`

| Group | Files | Purpose |
|-------|-------|---------|
| `auth/` | login, register, logout, me, forgot-password, reset-password, otp-verify, refresh-token, verify_email, verify_otp, resend_email_verification | self-contained auth |
| `admin/` | dashboard, users, branches, roles, settings, transactions, audit-logs, reports | admin management |
| `customer/` | dashboard, profile, wallet | customer portal |
| `receptionist/` | dashboard, appointments, customers | receptionist portal |
| `staff/` | dashboard, customers, complaints, beneficiaries, receipts | staff portal |
| `complaints/` | index, create, update | support tickets |
| `expenses/` | index, create, summary | expenses |
| `notes/` | index, create, update, delete | notes |
| `notifications/` | index, mark-read, send | notifications |
| `reports/` | summary, generate, download | reporting |
| `settings/` | index, update | settings |
| `transactions/` | index, create, history, receipt | transactions |

Every standalone endpoint bootstraps with:
```php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';
```
and enforces session/role via `requireActiveSession()` / `requireRole([...])`.

## Authentication

### Register (module router)
```
POST /backend/api/index.php?module=auth&action=register
{ "first_name":"A", "last_name":"B", "email":"a@b.com",
  "phone":"9876543210", "password":"Strong@123" }
```
Response includes `needs_otp` and optionally `dev_otp`.

### Login
```
POST /backend/api/index.php?module=auth&action=login
{ "email":"a@b.com", "password":"Strong@123" }
```
Returns `user_id`, `name`, `email`, `role`.

## Error Codes
- `400` Bad request / validation failure
- `401` Unauthenticated
- `403` Forbidden (wrong role)
- `404` Resource not found / unknown action
- `405` Method not allowed
- `429` Too many attempts (rate limit / lockout)
- `500` Server / database error
