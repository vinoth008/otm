-- ============================================================
-- Smart Transaction Control — LEGACY MySQL triggers
-- ============================================================
-- NOTE: The application now uses MongoDB. This file documents the
-- original MySQL triggers for reference only; it is NOT executed at
-- runtime. Audit-trail logic now lives in PHP (logActivity()).

-- Keep the parent user's balance consistent whenever a transaction row
-- is inserted by mirroring the income/expense delta.
DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_update_balance_after_tx
AFTER INSERT ON transactions
FOR EACH ROW
BEGIN
  IF NEW.type = 'income' THEN
    UPDATE users SET balance = balance + NEW.amount, updated_at = NOW() WHERE id = NEW.user_id;
  ELSEIF NEW.type = 'expense' THEN
    UPDATE users SET balance = balance - NEW.amount, updated_at = NOW() WHERE id = NEW.user_id;
  END IF;
END//
DELIMITER ;

-- Stamp updated_at on common tables.
DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_users_before_update
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
  SET NEW.updated_at = NOW();
END//
DELIMITER ;
