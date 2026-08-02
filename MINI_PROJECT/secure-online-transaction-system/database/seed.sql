USE secure_transaction_system;

INSERT INTO roles (role_name, role_code, role_description) VALUES
('Administrator', 'ADMIN', 'Full system access'),
('Staff', 'STAFF', 'Bank staff operations'),
('Receptionist', 'RECEPTIONIST', 'Front desk and queue management'),
('Customer', 'CUSTOMER', 'End-user customer');

INSERT INTO permissions (permission_name, permission_code, module_name) VALUES
('Manage Users', 'MANAGE_USERS', 'ADMIN'),
('Approve Transactions', 'APPROVE_TX', 'STAFF'),
('Create Token', 'CREATE_TOKEN', 'RECEPTION'),
('View Own Dashboard', 'VIEW_OWN_DASHBOARD', 'COMMON'),
('Manage Expenses', 'MANAGE_EXPENSES', 'CUSTOMER'),
('Manage Notes', 'MANAGE_NOTES', 'COMMON'),
('View Reports', 'VIEW_REPORTS', 'ADMIN'),
('Manage Settings', 'MANAGE_SETTINGS', 'ADMIN');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON
(
  (r.role_code = 'ADMIN' AND p.permission_code IN ('MANAGE_USERS','APPROVE_TX','CREATE_TOKEN','VIEW_OWN_DASHBOARD','MANAGE_EXPENSES','MANAGE_NOTES','VIEW_REPORTS','MANAGE_SETTINGS')) OR
  (r.role_code = 'STAFF' AND p.permission_code IN ('APPROVE_TX','VIEW_OWN_DASHBOARD','MANAGE_NOTES','VIEW_REPORTS')) OR
  (r.role_code = 'RECEPTIONIST' AND p.permission_code IN ('CREATE_TOKEN','VIEW_OWN_DASHBOARD','MANAGE_NOTES')) OR
  (r.role_code = 'CUSTOMER' AND p.permission_code IN ('VIEW_OWN_DASHBOARD','MANAGE_EXPENSES','MANAGE_NOTES'))
);

INSERT INTO branches (branch_name, branch_code, address_line1, city, state, country, pincode, phone, email) VALUES
('Main Branch', 'BR001', 'T. Nagar Main Road', 'Chennai', 'Tamil Nadu', 'India', '600017', '0441111111', 'main@bank.local'),
('North Branch', 'BR002', 'Anna Nagar', 'Chennai', 'Tamil Nadu', 'India', '600040', '0442222222', 'north@bank.local');

INSERT INTO categories (category_name, category_type) VALUES
('Food', 'EXPENSE'),
('Transport', 'EXPENSE'),
('Shopping', 'EXPENSE'),
('Medical', 'EXPENSE'),
('Education', 'EXPENSE'),
('Bills', 'EXPENSE'),
('Entertainment', 'EXPENSE'),
('Fuel', 'EXPENSE'),
('Investment', 'EXPENSE'),
('Salary', 'INCOME'),
('Business', 'INCOME'),
('Others', 'OTHER');

INSERT INTO settings (setting_key, setting_value, setting_type, is_system) VALUES
('app_name', 'Secure Online Transaction System', 'STRING', 1),
('currency_code', 'INR', 'STRING', 1),
('session_timeout_minutes', '15', 'NUMBER', 1),
('login_max_attempts', '5', 'NUMBER', 1),
('otp_expiry_minutes', '5', 'NUMBER', 1),
('two_factor_enabled_default', '1', 'BOOLEAN', 1);

INSERT INTO users
(role_id, branch_id, full_name, username, email, mobile, password_hash, email_verified, mobile_verified, two_factor_enabled, account_status)
VALUES
((SELECT id FROM roles WHERE role_code='ADMIN'), (SELECT id FROM branches WHERE branch_code='BR001'),
 'System Administrator', 'admin', 'admin@bank.local', '9000000000',
 '$2y$10$examplehashreplacewithrealhashvalue0000000000000000000000', 1, 1, 1, 'ACTIVE');