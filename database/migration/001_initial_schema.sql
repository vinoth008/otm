-- ============================================================
-- Smart Transaction Control — migration 001: LEGACY initial schema
-- ============================================================
-- NOTE: The application now uses MongoDB. This file documents the
-- original MySQL initial-schema migration for traceability only.
-- The live model is defined in database/schema.js (MongoDB).

CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_code VARCHAR(32) NOT NULL UNIQUE,
  role_name VARCHAR(64) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_name VARCHAR(128) NOT NULL,
  address TEXT NULL,
  phone VARCHAR(20) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  branch_id INT NULL,
  full_name VARCHAR(128) NOT NULL,
  username VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  mobile VARCHAR(20) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  account_status VARCHAR(16) NOT NULL DEFAULT 'pending',
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  mobile_verified TINYINT(1) NOT NULL DEFAULT 0,
  two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
  failed_login_attempts INT NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS otp_verifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  otp_code_hash VARCHAR(255) NOT NULL,
  otp_purpose VARCHAR(32) NOT NULL,
  expires_at DATETIME NOT NULL,
  is_used TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  reset_token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  is_used TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  session_token_hash VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
