-- ============================================================
-- Smart Transaction Control — LEGACY MySQL schema (reference only)
-- ============================================================
-- NOTE: The application now uses MongoDB (database
-- `smart_transaction_control`) — see ./schema.js and
-- documentation/database-design.md.
-- This file is retained for reference / migration traceability and
-- documents the original relational model that the MongoDB schema
-- replaced. It is NOT used at runtime.

CREATE DATABASE IF NOT EXISTS secure_transaction_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE secure_transaction_system;

-- Roles (canonical map: admin, staff, receptionist, customer)
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_code  VARCHAR(32)  NOT NULL UNIQUE,
  role_name  VARCHAR(64)  NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Branches (banking branches)
CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_name VARCHAR(128) NOT NULL,
  address     TEXT NULL,
  phone       VARCHAR(20) NULL,
  status      VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Users
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_id          INT NOT NULL,
  branch_id        INT NULL,
  full_name        VARCHAR(128) NOT NULL,
  username         VARCHAR(64)  NOT NULL UNIQUE,
  email            VARCHAR(190) NOT NULL UNIQUE,
  mobile           VARCHAR(20)  NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  account_status   VARCHAR(16)  NOT NULL DEFAULT 'pending',
  email_verified   TINYINT(1)   NOT NULL DEFAULT 0,
  mobile_verified  TINYINT(1)   NOT NULL DEFAULT 0,
  two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
  failed_login_attempts INT NOT NULL DEFAULT 0,
  last_login_at    DATETIME NULL,
  security_question VARCHAR(255) NULL,
  security_answer_hash VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  INDEX idx_role (role_id),
  INDEX idx_branch (branch_id),
  CONSTRAINT fk_user_role   FOREIGN KEY (role_id)   REFERENCES roles(id),
  CONSTRAINT fk_user_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

-- Sessions
CREATE TABLE IF NOT EXISTS sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id          INT NOT NULL,
  session_token_hash VARCHAR(255) NOT NULL,
  ip_address       VARCHAR(45)  NULL,
  user_agent       VARCHAR(255) NULL,
  device_info      VARCHAR(255) NULL,
  location_info    VARCHAR(255) NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_activity    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- OTP verifications
CREATE TABLE IF NOT EXISTS otp_verifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  otp_code_hash VARCHAR(255) NOT NULL,
  otp_purpose  VARCHAR(32)  NOT NULL,
  expires_at   DATETIME NOT NULL,
  is_used      TINYINT(1) NOT NULL DEFAULT 0,
  used_at      DATETIME NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_purpose (user_id, otp_purpose),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Password resets
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  reset_token_hash VARCHAR(255) NOT NULL,
  expires_at      DATETIME NOT NULL,
  is_used         TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- New relational collections succeed as MongoDB collections (see schema.js):
-- transactions, wallets, categories, budgets, goals, expenses, notes,
-- notifications, complaints, activity_logs, login_history, branches,
-- roles, settings, beneficiaries, receipts, appointments.
