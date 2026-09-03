-- ============================================================
-- Smart Transaction Control — migration 003: LEGACY audit logs
-- ============================================================
-- NOTE: The application now uses MongoDB. Audit trail is captured in
-- the `activity_logs` collection via logActivity() in PHP. This SQL
-- is retained for reference only and is NOT executed at runtime.

CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  details TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_action (action)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  email VARCHAR(190) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  failure_reason VARCHAR(64) NULL,
  ip_address VARCHAR(45) NULL,
  attempt_time DATETIME NOT NULL,
  INDEX idx_email (email),
  INDEX idx_attempt_time (attempt_time)
) ENGINE=InnoDB;
