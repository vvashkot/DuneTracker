-- Migration: 016_add_guild_tax_system_fix.sql
-- Description: Fix for partial application of guild tax system
-- Date: 2025-08-04

-- Check and add columns only if they don't exist
-- This handles the case where migration was partially applied

-- Add personal_tax_rate if it doesn't exist
SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'users' 
AND COLUMN_NAME = 'personal_tax_rate';

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE users ADD COLUMN personal_tax_rate DECIMAL(5,4) DEFAULT 0.10',
    'SELECT "Column personal_tax_rate already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add tax_opt_in if it doesn't exist
SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'users' 
AND COLUMN_NAME = 'tax_opt_in';

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE users ADD COLUMN tax_opt_in BOOLEAN DEFAULT TRUE',
    'SELECT "Column tax_opt_in already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add tax_amount to contributions if it doesn't exist
SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'contributions' 
AND COLUMN_NAME = 'tax_amount';

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE contributions ADD COLUMN tax_amount DECIMAL(15,2) DEFAULT 0 AFTER quantity',
    'SELECT "Column tax_amount already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add tax_rate to contributions if it doesn't exist
SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'contributions' 
AND COLUMN_NAME = 'tax_rate';

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE contributions ADD COLUMN tax_rate DECIMAL(5,4) DEFAULT 0 AFTER tax_amount',
    'SELECT "Column tax_rate already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create guild_treasury table if it doesn't exist
CREATE TABLE IF NOT EXISTS guild_treasury (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,
    quantity DECIMAL(15,2) NOT NULL,
    source_type ENUM('tax', 'donation', 'admin_adjustment') DEFAULT 'tax',
    source_user_id INT,
    source_contribution_id INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (resource_id) REFERENCES resources(id),
    FOREIGN KEY (source_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (source_contribution_id) REFERENCES contributions(id) ON DELETE SET NULL,
    INDEX idx_resource (resource_id),
    INDEX idx_created (created_at)
);

-- Create guild_settings table if it doesn't exist
CREATE TABLE IF NOT EXISTS guild_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default tax settings if they don't exist
INSERT INTO guild_settings (setting_key, setting_value) VALUES 
    ('guild_tax_rate', '0.10'),
    ('guild_tax_enabled', 'true')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Create or replace the guild_treasury_totals view
CREATE OR REPLACE VIEW guild_treasury_totals AS
SELECT 
    r.id as resource_id,
    r.name as resource_name,
    r.category,
    COALESCE(SUM(gt.quantity), 0) as total_quantity
FROM resources r
LEFT JOIN guild_treasury gt ON r.id = gt.resource_id
GROUP BY r.id, r.name, r.category;

-- Update the migration record
INSERT INTO migrations (version, description) VALUES (16, 'Add guild tax system')
ON DUPLICATE KEY UPDATE description = 'Add guild tax system';