# Viva Questions & Answers

## 1. What is this project about?
**Smart Transaction Control (SecureSOT)** is a role-based bank/finance application that lets customers manage wallets, transactions, expenses, budgets, and goals, while admins/staff/receptionists manage users, support, appointments, and reports. It supports four roles: admin, staff, receptionist, and customer.

## 2. What is your technology stack?
HTML, CSS, JavaScript (vanilla), PHP backend, MongoDB Atlas database, optional Java (business logic) and Python (AI analytics), Chart.js for charts, SMTP for email.

## 3. Why MongoDB instead of SQL?
MongoDB is schema-flexible (ideal for evolving finance documents), scales horizontally, and stores documents (e.g., a transaction with nested category/notes) naturally in one place. It also integrates well with JSON APIs.

## 4. What is an OTP and how is it implemented?
A One-Time Password is a 6-digit code used to verify email/account ownership. On registration or forgot-password, the backend generates a 6-digit OTP, stores it in the `otp_verifications` collection (hashed, with an expiry), and emails it via SMTP. The user enters it, and the backend verifies it (single-use, time-limited).

## 5. How do you send emails?
By speaking raw SMTP over a socket: connect to `smtp.gmail.com:587`, `STARTTLS`, `AUTH LOGIN` with the Gmail address and a Google **App Password**, then send an HTML email. No external mail library is required.

## 6. How do you secure passwords?
Bcrypt hashing with a cost factor of 12. Passwords are never stored in plaintext. We also enforce password-strength rules and verify on login with `password_verify`.

## 7. How is authentication and authorization handled?
Authentication uses PHP sessions; after login a session stores the user id and role. Authorization is role-based: each endpoint calls `requireRole(['admin', ...])` and returns 403 for the wrong role.

## 8. How do you prevent SQL/NoSQL injection?
MongoDB driver queries are built with document/BSON objects, not string concatenation, so queries are parameterized. All input is also sanitized before use.

## 9. What is CSRF and how do you prevent it?
Cross-Site Request Forgery tricks a logged-in user into performing an unwanted action. We generate a per-session CSRF token and verify it on every state-changing request.

## 10. How do you handle brute-force attacks?
Rate limiting per IP and account, plus failed-attempt counters with temporary lockout. Login history is recorded for audit.

## 11. What are your main database collections?
users, transactions, wallets, categories, budgets, goals, expenses, notes, notifications, complaints, otp_verifications, password_resets, activity_logs, login_history, appointments, beneficiaries, receipts, settings, roles, branches.

## 12. What is the difference between income and expense transactions?
An income transaction increases the customer/wallet balance (e.g., salary); an expense decreases it (e.g., shopping). An optional `transfer` type moves money between wallets without changing the overall balance.

## 13. Explain the soft-delete approach.
Instead of permanently deleting, we set a `deleted_at` timestamp. Records are filtered out of normal queries but remain recoverable, which is important for finance/audit purposes.

## 14. Why are you using two session cookie names?
The authentication pages route through the `backend/api/index.php` module router (`SOT_SESSION`), while the main application uses direct `backend/php/*.php` endpoints (`STC_SESSION`). Both are standard PHP session cookies scoped to the app.

## 15. How would you add a new role?
Add the role to the role normalization map, create a role document in `roles`, add portal selection, and gate endpoints with the new role in `requireRole([...])`.

## 16. What is your analytics module?
Python modules analyze spending (trend analysis, next-month prediction via linear regression, anomaly detection using Z-scores, fraud detection, and budget recommendations). The PHP analytics endpoint bridges to the Python API.

## 17. How do you export reports?
The reports endpoints generate JSON, CSV, and PDF outputs that the frontend can download, summarizing income/expense by date range and category.

## 18. What security headers do you set?
`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `X-XSS-Protection`, `Referrer-Policy`, and `Permissions-Policy`.

## 19. How do you ensure data consistency when updating a balance?
When a transaction is created, the wallet/user balance is updated in the same request. For simplicity this uses MongoDB updates after insertion; in production you could use transactions for atomic updates.

## 20. What would you improve next?
Add multi-factor authentication, end-to-end tests, server-side pagination caching, and atomic MongoDB transactions for balance transfers.
