-- Migration: 016_add_guild_tax_system.sql
-- Description: Add guild tax system for automatic resource contributions
-- Date: 2025-08-04

-- Create guild treasury table to track tax contributions
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

-- Add guild tax settings
CREATE TABLE IF NOT EXISTS guild_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default tax rate (10%)
INSERT INTO guild_settings (setting_key, setting_value) VALUES 
    ('guild_tax_rate', '0.10'),
    ('guild_tax_enabled', 'true')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Add user tax preference to users table
ALTER TABLE users
ADD COLUMN personal_tax_rate DECIMAL(5,4) DEFAULT 0.10,
ADD COLUMN tax_opt_in BOOLEAN DEFAULT TRUE;

-- Add tax tracking to contributions
ALTER TABLE contributions
ADD COLUMN tax_amount DECIMAL(15,2) DEFAULT 0 AFTER quantity,
ADD COLUMN tax_rate DECIMAL(5,4) DEFAULT 0 AFTER tax_amount;

-- Create view for guild treasury totals
CREATE VIEW guild_treasury_totals AS
SELECT 
    r.id as resource_id,
    r.name as resource_name,
    r.category,
    COALESCE(SUM(gt.quantity), 0) as total_quantity
FROM resources r
LEFT JOIN guild_treasury gt ON r.id = gt.resource_id
GROUP BY r.id, r.name, r.category;

-- Record this migration
INSERT INTO migrations (version, description) VALUES (16, 'Add guild tax system');