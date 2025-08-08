-- Hostinger Clean SQL Import File
-- Generated from schema.sql and all migration files
-- Date: 2025-08-06

-- Disable foreign key checks during import
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- Create migrations table first
CREATE TABLE IF NOT EXISTS migrations (
    version DECIMAL(10,1) PRIMARY KEY,
    description VARCHAR(255),
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Users table to store Discord OAuth information
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  discord_id VARCHAR(20) NULL,
  username VARCHAR(100) NOT NULL,
  avatar TEXT,
  role ENUM('member', 'admin') DEFAULT 'member',
  approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  approved_at DATETIME NULL,
  approved_by INT NULL,
  rejection_reason TEXT NULL,
  is_manual BOOLEAN DEFAULT FALSE,
  merged_into_user_id INT NULL,
  personal_tax_rate DECIMAL(5,4) DEFAULT 0.10,
  tax_opt_in BOOLEAN DEFAULT TRUE,
  notification_preferences JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_manual_users (is_manual),
  INDEX idx_merged_users (merged_into_user_id),
  INDEX idx_approval_status (approval_status),
  FOREIGN KEY (merged_into_user_id) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Resources table for different resource types
CREATE TABLE IF NOT EXISTS resources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  category VARCHAR(100),
  description TEXT,
  can_be_refined BOOLEAN DEFAULT FALSE,
  refined_into_id INT DEFAULT NULL,
  base_conversion_rate DECIMAL(10,4) DEFAULT NULL,
  current_stock DECIMAL(15,2) DEFAULT 0,
  total_withdrawn DECIMAL(15,2) DEFAULT 0,
  available_stock DECIMAL(15,2) GENERATED ALWAYS AS (current_stock - total_withdrawn) STORED,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (refined_into_id) REFERENCES resources(id)
);

-- Contributions table to track resource submissions
CREATE TABLE IF NOT EXISTS contributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  resource_id INT NOT NULL,
  quantity INT NOT NULL,
  tax_amount DECIMAL(15,2) DEFAULT 0,
  tax_rate DECIMAL(5,4) DEFAULT 0,
  date_collected DATETIME DEFAULT CURRENT_TIMESTAMP,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE RESTRICT,
  INDEX idx_user_id (user_id),
  INDEX idx_resource_id (resource_id),
  INDEX idx_date_collected (date_collected)
);

-- Distributions table for tracking resource allocations
CREATE TABLE IF NOT EXISTS distributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  resource_id INT NOT NULL,
  quantity INT NOT NULL,
  date_given DATETIME DEFAULT CURRENT_TIMESTAMP,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE RESTRICT,
  INDEX idx_user_id (user_id),
  INDEX idx_resource_id (resource_id),
  INDEX idx_date_given (date_given)
);

-- Table for farming runs/sessions
CREATE TABLE IF NOT EXISTS farming_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_number INT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(255) NOT NULL,
    run_type ENUM('spice', 'mining', 'mixed') DEFAULT 'mixed',
    location VARCHAR(255),
    started_at DATETIME NOT NULL,
    scheduled_for DATETIME NULL,
    ended_at DATETIME,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    is_scheduled BOOLEAN DEFAULT FALSE,
    reminder_sent BOOLEAN DEFAULT FALSE,
    created_by INT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_started_at (started_at),
    INDEX idx_scheduled_runs (is_scheduled, scheduled_for)
);

-- Table for run participants
CREATE TABLE IF NOT EXISTS run_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('leader', 'member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    share_percentage DECIMAL(5,2) DEFAULT NULL,
    FOREIGN KEY (run_id) REFERENCES farming_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_run_user (run_id, user_id)
);

-- Table for raw resources collected during the run
CREATE TABLE IF NOT EXISTS run_collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    resource_id INT NOT NULL,
    quantity INT NOT NULL,
    collected_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES farming_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id),
    FOREIGN KEY (collected_by) REFERENCES users(id),
    INDEX idx_run_id (run_id)
);

-- Table for refined outputs from the run
CREATE TABLE IF NOT EXISTS run_refined_outputs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    input_resource_id INT NOT NULL,
    output_resource_id INT NOT NULL,
    input_quantity INT NOT NULL,
    output_quantity INT NOT NULL,
    conversion_rate DECIMAL(10,4),
    refined_by INT,
    refined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (run_id) REFERENCES farming_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (input_resource_id) REFERENCES resources(id),
    FOREIGN KEY (output_resource_id) REFERENCES resources(id),
    FOREIGN KEY (refined_by) REFERENCES users(id),
    INDEX idx_run_id (run_id)
);

-- Create a table for complex crafting recipes
CREATE TABLE IF NOT EXISTS crafting_recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    output_resource_id INT NOT NULL,
    output_quantity INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (output_resource_id) REFERENCES resources(id)
);

-- Create recipe ingredients table
CREATE TABLE IF NOT EXISTS recipe_ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT NOT NULL,
    resource_id INT NOT NULL,
    quantity INT NOT NULL,
    FOREIGN KEY (recipe_id) REFERENCES crafting_recipes(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id)
);

-- Create activity logs table for admin monitoring
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_created_at (created_at),
    INDEX idx_user_id (user_id)
);

-- Create resource goals table
CREATE TABLE IF NOT EXISTS resource_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,
    target_amount INT NOT NULL,
    current_amount INT DEFAULT 0,
    deadline DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    completed_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (resource_id) REFERENCES resources(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_active_goals (is_active, deadline)
);

-- Create leaderboard periods table for tracking weekly/monthly stats
CREATE TABLE IF NOT EXISTS leaderboard_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_type ENUM('weekly', 'monthly') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    UNIQUE KEY unique_period (period_type, start_date, end_date),
    INDEX idx_period_dates (start_date, end_date)
);

-- Create run templates table
CREATE TABLE IF NOT EXISTS run_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    run_type ENUM('spice', 'mining', 'mixed') DEFAULT 'mixed',
    default_location VARCHAR(255),
    default_notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_active_templates (is_active)
);

-- Create run distributions table for tracking resource distribution
CREATE TABLE IF NOT EXISTS run_distributions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    resource_id INT NOT NULL,
    recipient_id INT NOT NULL,
    quantity INT NOT NULL,
    distributed_by INT NOT NULL,
    distributed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (run_id) REFERENCES farming_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id),
    FOREIGN KEY (distributed_by) REFERENCES users(id),
    INDEX idx_run_distributions (run_id, recipient_id)
);

-- Create run metrics table for efficiency tracking
CREATE TABLE IF NOT EXISTS run_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL UNIQUE,
    duration_minutes INT,
    total_resources_collected INT DEFAULT 0,
    unique_resources_collected INT DEFAULT 0,
    resources_per_participant DECIMAL(10,2),
    resources_per_minute DECIMAL(10,2),
    participation_rate DECIMAL(5,2),
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES farming_runs(id) ON DELETE CASCADE,
    INDEX idx_run_metrics (run_id)
);

-- Create run comments table
CREATE TABLE IF NOT EXISTS run_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES farming_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_run_comments (run_id, created_at)
);

-- Create approval requests table for tracking
CREATE TABLE IF NOT EXISTS approval_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    first_login_ip VARCHAR(45),
    discord_username VARCHAR(255),
    discord_discriminator VARCHAR(10),
    notes TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_requested_at (requested_at)
);

-- Create webhook configurations table
CREATE TABLE IF NOT EXISTS webhook_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    webhook_url TEXT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    event_types JSON,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_active (is_active)
);

-- Create webhook log table for tracking sent notifications
CREATE TABLE IF NOT EXISTS webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_config_id INT,
    event_type VARCHAR(100),
    event_data JSON,
    status ENUM('success', 'failed') DEFAULT 'success',
    error_message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (webhook_config_id) REFERENCES webhook_configs(id) ON DELETE CASCADE,
    INDEX idx_sent_at (sent_at),
    INDEX idx_status (status)
);

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

-- Insert core resources with proper IDs and relationships
INSERT INTO resources (id, name, category, description, can_be_refined) VALUES
(1, 'Titanium Ore', 'Raw Materials', 'Raw titanium ore mined from deposits', FALSE),
(2, 'Spice', 'Raw Materials', 'Raw spice harvested from the desert', FALSE),
(3, 'Stravidium Mass', 'Raw Materials', 'Raw stravidium material', TRUE),
(5, 'Melange', 'Refined', 'Refined spice - the most valuable substance', FALSE),
(6, 'Stravidium Fiber', 'Refined', 'Refined fiber made from Stravidium Mass', FALSE),
(7, 'Plastanium Ingot', 'Refined', 'Advanced material made from Titanium and Stravidium Fiber', FALSE);

-- Update refinement relationships
UPDATE resources SET refined_into_id = 6, base_conversion_rate = 0.33 WHERE id = 3;

-- Add Melange crafting recipe (10000 Spice = 200 Melange)
INSERT INTO crafting_recipes (output_resource_id, output_quantity) VALUES (5, 200);
SET @melange_recipe_id = LAST_INSERT_ID();
INSERT INTO recipe_ingredients (recipe_id, resource_id, quantity) VALUES
(@melange_recipe_id, 2, 10000);

-- Add Stravidium Fiber recipe (3 Stravidium Mass = 1 Fiber)
INSERT INTO crafting_recipes (output_resource_id, output_quantity) VALUES (6, 1);
SET @fiber_recipe_id = LAST_INSERT_ID();
INSERT INTO recipe_ingredients (recipe_id, resource_id, quantity) VALUES
(@fiber_recipe_id, 3, 3);

-- Add Plastanium Ingot recipe (4 Titanium Ore + 1 Stravidium Fiber = 1 Ingot)
INSERT INTO crafting_recipes (output_resource_id, output_quantity) VALUES (7, 1);
SET @plastanium_recipe_id = LAST_INSERT_ID();
INSERT INTO recipe_ingredients (recipe_id, resource_id, quantity) VALUES
(@plastanium_recipe_id, 1, 4),
(@plastanium_recipe_id, 6, 1);

-- Insert default tax rate settings
INSERT INTO guild_settings (setting_key, setting_value) VALUES 
    ('guild_tax_rate', '0.10'),
    ('guild_tax_enabled', 'true'),
    ('withdrawal_purposes', '["Guild Event", "Crafting", "Trading", "Personal Use", "Raid Preparation", "Other"]');

-- Create trigger to auto-increment run_number for new runs
DELIMITER $$
CREATE TRIGGER before_insert_farming_runs
BEFORE INSERT ON farming_runs
FOR EACH ROW
BEGIN
    SET NEW.run_number = (SELECT IFNULL(MAX(run_number), 0) + 1 FROM farming_runs);
END$$
DELIMITER ;

-- Create trigger to update current_stock on new contributions
DELIMITER $$
CREATE TRIGGER update_current_stock_on_contribution
AFTER INSERT ON contributions
FOR EACH ROW
BEGIN
    UPDATE resources 
    SET current_stock = current_stock + NEW.quantity 
    WHERE id = NEW.resource_id;
END$$
DELIMITER ;

-- Create trigger to update total_withdrawn on new withdrawals
DELIMITER $$
CREATE TRIGGER update_resource_withdrawn
AFTER INSERT ON withdrawals
FOR EACH ROW
BEGIN
    IF NEW.approval_status = 'approved' THEN
        UPDATE resources 
        SET total_withdrawn = total_withdrawn + NEW.quantity 
        WHERE id = NEW.resource_id;
    END IF;
END$$
DELIMITER ;

-- Create views
DROP VIEW IF EXISTS guild_treasury_totals;
CREATE VIEW guild_treasury_totals AS
SELECT 
    r.id as resource_id,
    r.name as resource_name,
    r.category,
    COALESCE(SUM(gt.quantity), 0) as total_quantity
FROM resources r
LEFT JOIN guild_treasury gt ON r.id = gt.resource_id
GROUP BY r.id, r.name, r.category;

DROP VIEW IF EXISTS resource_availability;
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

-- Record all migrations as applied
INSERT INTO migrations (version, description) VALUES 
(1, 'Initial database schema'),
(2, 'Add farming runs and refinement tracking'),
(3, 'Simplify resources to core four'),
(4, 'Add proper crafting chains for Stravidium and Plastanium'),
(5, 'Update Melange to use proper crafting recipe'),
(6, 'Simplify categories to Raw Materials and Refined'),
(7, 'Remove water from resources and recipes'),
(8, 'Add run_number for auto-incrementing run names'),
(9, 'Add admin role system and activity logs'),
(10, 'Add support for manual users without Discord'),
(11, 'Add resource goals and leaderboard tracking'),
(12, 'Add farming run scheduling, templates, distributions, and metrics'),
(13, 'Allow NULL in created_by columns for user deletion'),
(14, 'Add user approval system'),
(15, 'Add Discord webhook support'),
(16, 'Add guild tax system'),
(16.5, 'Add current_stock to resources'),
(17, 'Add withdrawal tracking system (fixed)');

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;