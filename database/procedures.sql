-- ============================================================
-- Smart Transaction Control — LEGACY MySQL stored procedures
-- ============================================================
-- NOTE: The application now uses MongoDB. This file documents the
-- original MySQL stored procedures for reference only; it is NOT
-- executed at runtime. The equivalent logic now lives in PHP
-- (backend/services, backend/api, backend/php).

-- Insert a transaction and update the user balance atomically.
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_insert_transaction(
  IN p_user_id INT,
  IN p_type VARCHAR(16),
  IN p_amount DECIMAL(15,2),
  IN p_category VARCHAR(64),
  IN p_description VARCHAR(255),
  IN p_payment_method VARCHAR(32)
)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  INSERT INTO transactions (user_id, type, amount, category, description, payment_method, created_at)
  VALUES (p_user_id, p_type, p_amount, p_category, p_description, p_payment_method, NOW());

  IF p_type = 'income' THEN
    UPDATE users SET balance = balance + p_amount WHERE id = p_user_id;
  ELSEIF p_type = 'expense' THEN
    UPDATE users SET balance = balance - p_amount WHERE id = p_user_id;
  END IF;

  COMMIT;
END//
DELIMITER ;

-- Register failed login attempt / lockout bookkeeping.
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_record_login_attempt(
  IN p_email VARCHAR(190),
  IN p_success TINYINT(1),
  IN p_failure_reason VARCHAR(64)
)
BEGIN
  INSERT INTO login_history (email, success, failure_reason, attempt_time)
  VALUES (p_email, p_success, p_failure_reason, NOW());
END//
DELIMITER ;
