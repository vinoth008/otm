-- ============================================================
-- Smart Transaction Control — migration 002: LEGACY seed roles
-- ============================================================
-- NOTE: The application now uses MongoDB. Demo users for the four
-- canonical roles are seeded via database/seed_data.js. This SQL is
-- retained for reference only and is NOT executed at runtime.

INSERT INTO roles (role_code, role_name) VALUES
  ('admin',        'Administrator'),
  ('staff',        'Staff'),
  ('receptionist', 'Receptionist'),
  ('customer',     'Customer')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

-- Placeholder branch used by seeded internal users.
INSERT INTO branches (branch_name, address, status) VALUES
  ('Head Office', 'Main Branch Address', 'active')
ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name);
