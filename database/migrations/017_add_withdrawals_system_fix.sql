-- Migration: 017_add_withdrawals_system_fix.sql
-- Description: Fix for partial application of withdrawal tracking system
-- Date: 2025-08-04

-- Create withdrawals table if it doesn't exist
CREATE TABLE IF NOT EXISTS withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    resource_id INT NOT NULL,
    quantity DECIMAL(15,2) NOT NULL,
    purpose VARCHAR(255),
    notes TEXT,
    approved_by INT,
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (resource_id) REFERENCES resources(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_resource (resource_id),
    INDEX idx_created (created_at),
    INDEX idx_status (approval_status)
);

-- Add total_withdrawn to resources if it doesn't exist
SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'resources' 
AND COLUMN_NAME = 'total_withdrawn';

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE resources ADD COLUMN total_withdrawn DECIMAL(15,2) DEFAULT 0 AFTER current_stock',
    'SELECT "Column total_withdrawn already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add available_stock to resources if it doesn't exist
SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'resources' 
AND COLUMN_NAME = 'available_stock';

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE resources ADD COLUMN available_stock DECIMAL(15,2) GENERATED ALWAYS AS (current_stock - total_withdrawn) STORED AFTER total_withdrawn',
    'SELECT "Column available_stock already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create or replace view for resource availability
CREATE OR REPLACE VIEW resource_availability AS
SELECT 
    r.id,
    r.name,
    r.category,
    r.current_stock as total_contributed,
    r.total_withdrawn,
    r.available_stock,
    CASE 
        WHEN r.available_stock <= 0 THEN 'depleted'
        WHEN r.available_stock < r.current_stock * 0.2 THEN 'low'
        ELSE 'adequate'
    END as stock_status
FROM resources r;

-- Drop trigger if exists and recreate
DROP TRIGGER IF EXISTS update_resource_withdrawn;

-- Update the existing function to also update total_withdrawn
DELIMITER //
CREATE TRIGGER update_resource_withdrawn
AFTER INSERT ON withdrawals
FOR EACH ROW
BEGIN
    IF NEW.approval_status = 'approved' THEN
        UPDATE resources 
        SET total_withdrawn = total_withdrawn + NEW.quantity 
        WHERE id = NEW.resource_id;
    END IF;
END//
DELIMITER ;

-- Add withdrawal purpose categories to guild_settings if not exists
INSERT INTO guild_settings (setting_key, setting_value) VALUES 
    ('withdrawal_purposes', '["Guild Event", "Crafting", "Trading", "Personal Use", "Raid Preparation", "Other"]')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Update migration record
UPDATE migrations SET description = 'Add withdrawal tracking system (fixed)' WHERE version = 17;