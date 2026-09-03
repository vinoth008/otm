-- ============================================================
-- Smart Transaction Control — LEGACY MySQL views
-- ============================================================
-- NOTE: The application now uses MongoDB. These views document the
-- original relational read models for reference only; they are NOT
-- used at runtime. Equivalent aggregation is done in PHP with the
-- MongoDB aggregation framework.

-- User with role/branch names for listings.
CREATE OR REPLACE VIEW v_user_list AS
SELECT
  u.id,
  u.full_name,
  u.username,
  u.email,
  u.mobile,
  r.role_code,
  r.role_name,
  b.branch_name,
  u.account_status,
  u.last_login_at,
  u.created_at
FROM users u
LEFT JOIN roles r    ON r.id = u.role_id
LEFT JOIN branches b ON b.id = u.branch_id;

-- Per-user financial summary.
CREATE OR REPLACE VIEW v_user_finance AS
SELECT
  t.user_id,
  SUM(CASE WHEN t.type = 'income'  THEN t.amount ELSE 0 END) AS total_income,
  SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) AS total_expense,
  COUNT(*) AS transaction_count
FROM transactions t
GROUP BY t.user_id;

-- Pending / open support tickets by priority.
CREATE OR REPLACE VIEW v_open_complaints AS
SELECT id, user_id, category, priority, status, created_at
FROM complaints
WHERE status IN ('open', 'in_progress');
