# Setup Guide

## Prerequisites

- **XAMPP** (Apache + PHP ≥ 8.0) — [download](https://www.apachefriends.org/)
- **Composer** — [download](https://getcomposer.org/)
- **MongoDB Atlas** free-tier cluster — [sign up](https://www.mongodb.com/atlas)
- (Optional) Python 3.8+ for AI analytics, Java 11 for business logic
- A **Gmail** account with an App Password for OTP emails

## 1. Install Dependencies

```bash
cd C:\xampp\htdocs\MPWT
composer install
```

This installs `mongodb/mongodb`.

## 2. Configure `.env`

Copy `.env.example` to `.env` (or edit the existing `.env`) and set real values:

```env
# MongoDB Atlas
MONGO_URI=mongodb+srv://<user>:<password>@<cluster>.mongodb.net/?retryWrites=true&w=majority
DB_NAME=smart_transaction_control

# Gmail SMTP
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=yourapp@gmail.com
SMTP_PASSWORD=<16-char Google App Password>
SMTP_FROM_NAME="Smart Transaction Control"
```

> The same Mongo URI is also defined in `config.php` (root) and
> `backend/config/constants.php`. Keep them consistent.

## 3. Gmail App Password (for OTP)

1. Turn on 2-Step Verification for the Google account.
2. Visit [Google App Passwords](https://myaccount.google.com/apppasswords).
3. Create an app password for "Mail".
4. Use that 16-character password in `SMTP_PASSWORD`.

## 4. Database

The application connects to MongoDB Atlas automatically via `config.php`.
Seed demo users by running:

```bash
php scripts/reset_db_to_seed.php
```

or visit `http://localhost/MPWT/database/setup.php`.

## 5. Run

```bash
# Start Apache (XAMPP control panel), then open:
http://localhost/MPWT/
```

The entry `index.php` / `index.html` redirects to the role-selection page.

## 6. Demo Users (seed)

| Role | Email | Password |
|------|-------|----------|
| admin | admin@stc.test | seeded in `seed_data.js` |
| staff | staff@stc.test | seeded |
| receptionist | receptionist@stc.test | seeded |
| customer | customer@stc.test | seeded |

(Confirm exact credentials in `database/seed_data.js` / `scripts/reset_db_to_seed.php`.)

## 7. Python (optional analytics)

```bash
cd python
pip install -r requirements.txt
python main.py
```

## 8. Java (optional business logic)

```bash
cd java
mvn clean package
java -jar target/expense-tracker-1.0-SNAPSHOT.jar
```

## 9. Verify OTP Email Flow

1. Open `http://localhost/MPWT/frontend/auth/register.html`.
2. Register a new user.
3. Check the inbox for the OTP email.
4. Enter the OTP on `otp-verify.html` to complete verification.

## Troubleshooting

- **"Database connection error"** → verify `MONGO_URI` is reachable and network access is allowed (Atlas IP allow-list).
- **OTP email not sent** → confirm `SMTP_PASSWORD` is a valid App Password, not the account password.
- **Blank page** → check `logs/error.log`; ensure `composer install` ran.
