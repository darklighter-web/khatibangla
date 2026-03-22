-- =============================================
-- Security Bug Fixes Migration
-- Date: 2026-03-23
-- Description: Strengthens security settings
-- =============================================

-- Ensure admin_secret_key is not the weak default
UPDATE site_settings 
SET setting_value = 'khatibangla2026' 
WHERE setting_key = 'admin_secret_key' 
AND setting_value = 'menzio2026';

-- Add login rate limit settings if not exists
INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_type, setting_group, label, updated_at)
VALUES 
('sec_customer_login_max_attempts', '5', 'text', 'security', 'Customer Login Max Attempts', NOW()),
('sec_customer_login_lockout_minutes', '15', 'text', 'security', 'Customer Login Lockout Minutes', NOW()),
('sec_order_rate_limit', '5', 'text', 'security', 'Max Orders Per IP Per 10min', NOW());
