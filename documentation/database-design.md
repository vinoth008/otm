# Database Design

## Database

- **Provider:** MongoDB Atlas (Free Tier)
- **Database name:** `smart_transaction_control`
- **Driver:** `mongodb/mongodb` ^2.0 (Composer)
- **Connection:** defined in `config.php` (root) and `backend/config/constants.php`

## Core Collections

### users
Documents for all four roles (admin, staff, receptionist, customer).

| Field | Type | Notes |
|-------|------|-------|
| `_id` | ObjectId | |
| `email` | string | unique, indexed |
| `password_hash` | string | bcrypt |
| `first_name`, `last_name` | string | |
| `phone` / `mobile` | string | |
| `role` | string | admin\|staff\|receptionist\|customer |
| `status` | string | active\|suspended |
| `account_number` | string | 16-digit |
| `account_type` | string | savings\|current\|salary\|fixed |
| `balance` | float | customer's running balance |
| `is_verified`, `email_verified` | bool | |
| `created_at`, `updated_at`, `last_login` | UTCDateTime | |
| `deleted_at` | UTCDateTime\|null | soft delete |
| `reset_token`, `reset_token_expires` | string/UTCDateTime | password reset |

### transactions
| Field | Notes |
|-------|-------|
| `user_id` | ObjectId (owner) |
| `type` | income\|expense\|transfer |
| `amount` | float |
| `category` | string |
| `description` | string |
| `payment_method` | string (cash/card/upi/bank_transfer/wallet/other) |
| `date` | UTCDateTime |
| `notes` | string |
| `receipt_url` | string |
| `deleted_at` | soft delete |

### wallets
Multi-wallet per user: `user_id`, `name`, `account_number`, `balance`, `currency`, timestamps.

### categories / budgets / goals / expenses / notes
Per-user organization/categories, monthly budgets, savings goals, expense records, and notes.

### transactions-related
`beneficiaries`, `receipts`, `reminders`, `recurring` (schedules), `appointments`.

### otp_verifications
| Field | Notes |
|-------|-------|
| `user_id` | ObjectId |
| `otp_code` / `otp_code_hash` | stored via OtpHelper (sha256) |
| `otp_purpose` | verify_email\|forgot_password\|LOGIN\|REGISTER |
| `expires_at` | UTCDateTime |
| `is_used` | bool |
| `attempts` | int |

### password_resets
| Field | Notes |
|-------|-------|
| `email`, `user_id` | target |
| `token_hash` | sha256 of token |
| `expires_at` | UTCDateTime |
| `used` | bool |

### activity_logs / login_history
Audit trail: `user_id`, `action`, `ip_address`, `user_agent`, `timestamp`, `details`; and login attempts for brute-force protection.

### notifications / complaints / settings / branches / roles
Supporting collections for system notifications, support tickets, app settings (key/value), branches, and role definitions.

## Indexing Notes

- `users.email` → unique index.
- `transactions.user_id`, `notifications.user_id`, `activity_logs.user_id` → single-field indexes for fast per-user queries.
- Soft deletion (`deleted_at`) is applied consistently so records remain recoverable.

## Seed Data

- `database/schema.js` — collection schemas/creation.
- `database/seed_data.js` — demo users for all 4 roles.
- `scripts/reset_db_to_seed.php` — re-seeds a fresh demo state.

## Backup Strategy

- `database/backup_strategy.md` — describes periodic `mongodump` / atlas backups.
- Not relying on MySQL: legacy `.sql` files in `database/` are retained for reference only.
