-- Migration: 017_add_withdrawals_system.sql
-- Description: Add withdrawal tracking system for accurate inventory management
-- Date: 2025-08-04

-- Create withdrawals table
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

-- Add withdrawal tracking to resources table
ALTER TABLE resources
ADD COLUMN total_withdrawn DECIMAL(15,2) DEFAULT 0 AFTER current_stock,
ADD COLUMN available_stock DECIMAL(15,2) GENERATED ALWAYS AS (current_stock - total_withdrawn) STORED AFTER total_withdrawn;

-- Create view for resource availability
CREATE VIEW resource_availability AS
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

-- Add withdrawal purpose categories to guild_settings
INSERT INTO guild_settings (setting_key, setting_value) VALUES 
    ('withdrawal_purposes', '["Guild Event", "Crafting", "Trading", "Personal Use", "Raid Preparation", "Other"]')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Record this migration
INSERT INTO migrations (version, description) VALUES (17, 'Add withdrawal tracking system');