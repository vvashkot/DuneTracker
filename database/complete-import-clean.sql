-- Create migrations table first
CREATE TABLE IF NOT EXISTS `migrations` (
    `version` int NOT NULL PRIMARY KEY,
    `description` varchar(255),
    `applied_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Now import the rest of the schema-- Dune Awakening Guild Resource Tracker Database Schema

-- Create database (if needed)
-- CREATE DATABASE IF NOT EXISTS dune_tracker;
-- USE dune_tracker;

-- Users table to store Discord OAuth information
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  discord_id VARCHAR(50) UNIQUE NOT NULL,
  username VARCHAR(100) NOT NULL,
  avatar TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Resources table for different resource types
CREATE TABLE IF NOT EXISTS resources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  category VARCHAR(100),
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Contributions table to track resource submissions
CREATE TABLE IF NOT EXISTS contributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  resource_id INT NOT NULL,
  quantity INT NOT NULL,
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

-- Insert sample resource types for Dune Awakening
INSERT INTO resources (name, category, description) VALUES
('Melange (Spice)', 'Rare', 'The most valuable substance in the universe'),
('Water', 'Essential', 'Precious resource on Arrakis'),
('Stillsuit Components', 'Equipment', 'Parts for maintaining stillsuits'),
('Sandworm Teeth', 'Rare', 'Valuable crafting material'),
('Fremkit Components', 'Equipment', 'Traditional Fremen tools and materials'),
('Shield Generators', 'Technology', 'Personal shield technology'),
('Las-Gun Power Cells', 'Technology', 'Energy cells for las-guns'),
('Ornithopter Parts', 'Vehicle', 'Components for ornithopter maintenance'),
('Crysknife Materials', 'Weapon', 'Materials for crafting crysknives'),
('Desert Mouse Meat', 'Food', 'Protein source from desert fauna'),
('Spice Coffee', 'Consumable', 'Energizing beverage with trace melange'),
('Sand Compactor Parts', 'Equipment', 'Components for sand compaction devices'),
('Plasteel Ingots', 'Material', 'Durable construction material'),
('Solari Credits', 'Currency', 'Imperial currency'),
('Guild Seals', 'Currency', 'Guild-specific currency or reputation tokens');-- Migration: 001_initial_schema.sql
-- Description: Initial database schema
-- Date: 2025-08-03

-- This file is for reference only - the initial schema is in schema.sql
-- Future database changes should be added as new migration files-- Migration: 002_add_farming_runs.sql
-- Description: Add farming runs/sessions tracking
-- Date: 2025-08-03

-- Table for farming runs/sessions
CREATE TABLE IF NOT EXISTS farming_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    run_type ENUM('spice', 'mining', 'mixed') DEFAULT 'mixed',
    location VARCHAR(255),
    started_at DATETIME NOT NULL,
    ended_at DATETIME,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    created_by INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_started_at (started_at)
);

-- Table for run participants
CREATE TABLE IF NOT EXISTS run_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('leader', 'member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    share_percentage DECIMAL(5,2) DEFAULT NULL, -- For custom splits
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
    collected_by INT, -- NULL means group collection
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
    conversion_rate DECIMAL(10,4), -- e.g., 0.85 for 85% efficiency
    refined_by INT,
    refined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (run_id) REFERENCES farming_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (input_resource_id) REFERENCES resources(id),
    FOREIGN KEY (output_resource_id) REFERENCES resources(id),
    FOREIGN KEY (refined_by) REFERENCES users(id),
    INDEX idx_run_id (run_id)
);

-- Add refinement relationship to resources
ALTER TABLE resources 
ADD COLUMN can_be_refined BOOLEAN DEFAULT FALSE,
ADD COLUMN refined_into_id INT DEFAULT NULL,
ADD COLUMN base_conversion_rate DECIMAL(10,4) DEFAULT NULL,
ADD FOREIGN KEY (refined_into_id) REFERENCES resources(id);

-- Update existing resources with refinement data
UPDATE resources SET 
    can_be_refined = TRUE,
    refined_into_id = (SELECT id FROM (SELECT * FROM resources) r2 WHERE r2.name = 'Melange (Spice)' LIMIT 1),
    base_conversion_rate = 0.85
WHERE name = 'Raw Spice';

-- Add raw versions of resources that can be refined
INSERT INTO resources (name, category, description, can_be_refined, refined_into_id, base_conversion_rate) 
SELECT * FROM (
    SELECT 
        'Raw Spice' as name,
        'Raw Material' as category,
        'Unrefined spice harvested from the desert' as description,
        TRUE as can_be_refined,
        (SELECT id FROM resources WHERE name = 'Melange (Spice)') as refined_into_id,
        0.85 as base_conversion_rate
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM resources WHERE name = 'Raw Spice'
);

INSERT INTO resources (name, category, description, can_be_refined) VALUES
('Raw Titanium Ore', 'Raw Material', 'Unprocessed titanium ore', TRUE),
('Raw Stravidium Ore', 'Raw Material', 'Unprocessed stravidium ore', TRUE),
('Titanium Ingot', 'Refined Material', 'Refined titanium ready for crafting', FALSE),
('Stravidium Ingot', 'Refined Material', 'Refined stravidium ready for crafting', FALSE);

-- Update raw materials to point to refined versions
UPDATE resources SET 
    refined_into_id = (SELECT id FROM (SELECT * FROM resources) r2 WHERE r2.name = 'Titanium Ingot' LIMIT 1),
    base_conversion_rate = 0.75
WHERE name = 'Raw Titanium Ore';

UPDATE resources SET 
    refined_into_id = (SELECT id FROM (SELECT * FROM resources) r2 WHERE r2.name = 'Stravidium Ingot' LIMIT 1),
    base_conversion_rate = 0.70
WHERE name = 'Raw Stravidium Ore';

-- Record this migration
-- Description: Simplify resources to just Titanium, Spice, Melange, and Stravidium
-- Date: 2025-08-03

-- First, delete all existing contributions and collections to avoid foreign key issues
DELETE FROM contributions;
DELETE FROM run_collections;
DELETE FROM run_refined_outputs;

-- Delete all existing resources
DELETE FROM resources;

-- Reset auto increment
ALTER TABLE resources AUTO_INCREMENT = 1;

-- Insert only the four simple resources
INSERT INTO resources (id, name, category, description, can_be_refined, refined_into_id, base_conversion_rate) VALUES
(1, 'Titanium', 'Metal', 'Strong metal used for crafting', FALSE, NULL, NULL),
(2, 'Spice', 'Raw Material', 'Raw spice harvested from the desert', TRUE, NULL, NULL),
(3, 'Melange', 'Refined', 'Refined spice - the most valuable substance', FALSE, NULL, NULL),
(4, 'Stravidium', 'Metal', 'Rare metal with unique properties', FALSE, NULL, NULL);

-- Update Spice to refine into Melange
UPDATE resources SET refined_into_id = 3, base_conversion_rate = 0.85 WHERE id = 2;

-- Record this migration
-- Description: Update resources with proper crafting chains
-- Date: 2025-08-03

-- Clear existing data
DELETE FROM run_refined_outputs;
DELETE FROM run_collections;
DELETE FROM contributions;
DELETE FROM resources;

-- Reset auto increment
ALTER TABLE resources AUTO_INCREMENT = 1;

-- Create new crafting chain
-- Raw Materials
INSERT INTO resources (id, name, category, description, can_be_refined) VALUES
(1, 'Titanium Ore', 'Raw Material', 'Raw titanium ore mined from deposits', FALSE),
(2, 'Spice', 'Raw Material', 'Raw spice harvested from the desert', TRUE),
(3, 'Stravidium Mass', 'Raw Material', 'Raw stravidium material', TRUE),
(4, 'Water', 'Raw Material', 'Essential resource for crafting', FALSE);

-- Refined/Intermediate Materials
INSERT INTO resources (id, name, category, description, can_be_refined) VALUES
(5, 'Melange', 'Refined', 'Refined spice - the most valuable substance', FALSE),
(6, 'Stravidium Fiber', 'Intermediate', 'Refined fiber made from Stravidium Mass', FALSE);

-- Final Products
INSERT INTO resources (id, name, category, description, can_be_refined) VALUES
(7, 'Plastanium Ingot', 'Final Product', 'Advanced material made from Titanium and Stravidium Fiber', FALSE);

-- Update refinement relationships
UPDATE resources SET refined_into_id = 5, base_conversion_rate = 0.85 WHERE id = 2; -- Spice -> Melange
UPDATE resources SET refined_into_id = 6, base_conversion_rate = 0.33 WHERE id = 3; -- Stravidium Mass -> Fiber (3:1 ratio)

-- Create a new table for complex crafting recipes
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

-- Add Stravidium Fiber recipe (3 Stravidium Mass + 100 Water = 1 Fiber)
INSERT INTO crafting_recipes (output_resource_id, output_quantity) VALUES (6, 1);
SET @fiber_recipe_id = LAST_INSERT_ID();
INSERT INTO recipe_ingredients (recipe_id, resource_id, quantity) VALUES
(@fiber_recipe_id, 3, 3),    -- 3 Stravidium Mass
(@fiber_recipe_id, 4, 100);  -- 100 Water

-- Add Plastanium Ingot recipe (4 Titanium Ore + 1 Stravidium Fiber + 1250 Water = 1 Ingot)
INSERT INTO crafting_recipes (output_resource_id, output_quantity) VALUES (7, 1);
SET @plastanium_recipe_id = LAST_INSERT_ID();
INSERT INTO recipe_ingredients (recipe_id, resource_id, quantity) VALUES
(@plastanium_recipe_id, 1, 4),     -- 4 Titanium Ore
(@plastanium_recipe_id, 6, 1),     -- 1 Stravidium Fiber
(@plastanium_recipe_id, 4, 1250);  -- 1250 Water

-- Record this migration
-- Description: Update Melange to use proper crafting recipe (75000 water + 10000 spice = 200 melange)
-- Date: 2025-08-03

-- Remove the simple refinement relationship for Spice
UPDATE resources SET refined_into_id = NULL, base_conversion_rate = NULL WHERE id = 2;

-- Add Melange crafting recipe (75000 Water + 10000 Spice = 200 Melange)
INSERT INTO crafting_recipes (output_resource_id, output_quantity) VALUES (5, 200);
SET @melange_recipe_id = LAST_INSERT_ID();

INSERT INTO recipe_ingredients (recipe_id, resource_id, quantity) VALUES
(@melange_recipe_id, 2, 10000),   -- 10000 Spice
(@melange_recipe_id, 4, 75000);   -- 75000 Water

-- Update Spice to not be refinable since it uses crafting recipe now
UPDATE resources SET can_be_refined = FALSE WHERE id = 2;

-- Record this migration
-- Description: Simplify resource categories to just Raw Materials and Refined
-- Date: 2025-08-03

-- Update all intermediate and final products to "Refined"
UPDATE resources SET category = 'Refined' WHERE category IN ('Intermediate', 'Final Product');

-- Specifically update our resources to ensure correct categories
UPDATE resources SET category = 'Raw Materials' WHERE name IN ('Titanium Ore', 'Spice', 'Stravidium Mass', 'Water');
UPDATE resources SET category = 'Refined' WHERE name IN ('Melange', 'Stravidium Fiber', 'Plastanium Ingot');

-- Record this migration
-- Description: Remove water from resources and recipes
-- Date: 2025-08-03

-- Remove water from recipe ingredients
DELETE FROM recipe_ingredients WHERE resource_id = (SELECT id FROM resources WHERE name = 'Water');

-- Update recipes to remove water requirements
-- For Melange: was 10000 Spice + 75000 Water = 200 Melange, now just 10000 Spice = 200 Melange
-- For Stravidium Fiber: was 3 Stravidium Mass + 100 Water = 1 Fiber, now just 3 Stravidium Mass = 1 Fiber  
-- For Plastanium Ingot: was 4 Titanium Ore + 1 Stravidium Fiber + 1250 Water = 1 Ingot, now just 4 Titanium Ore + 1 Stravidium Fiber = 1 Ingot

-- Delete water resource (this will cascade delete from recipe_ingredients due to foreign key)
DELETE FROM resources WHERE name = 'Water';

-- Record this migration
-- Description: Add run_number for auto-incrementing run names
-- Date: 2025-08-03

-- Add run_number column for auto-incrementing
ALTER TABLE farming_runs 
ADD COLUMN run_number INT UNSIGNED NOT NULL DEFAULT 0 AFTER id;

-- Update existing runs with sequential numbers
SET @row_number = 0;
UPDATE farming_runs 
SET run_number = (@row_number:=@row_number + 1)
ORDER BY created_at;

-- Create a trigger to auto-increment run_number for new runs
DELIMITER $$
CREATE TRIGGER before_insert_farming_runs
BEFORE INSERT ON farming_runs
FOR EACH ROW
BEGIN
    SET NEW.run_number = (SELECT IFNULL(MAX(run_number), 0) + 1 FROM farming_runs);
END$$
DELIMITER ;

-- Record this migration
-- Description: Add admin role system for user management
-- Date: 2025-08-03

-- Add role column to users table
ALTER TABLE users 
ADD COLUMN role ENUM('member', 'admin') DEFAULT 'member' AFTER avatar;

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

-- Record this migration
-- Description: Add support for manual users without Discord
-- Date: 2025-08-03

-- Add is_manual flag to users table
ALTER TABLE users 
ADD COLUMN is_manual BOOLEAN DEFAULT FALSE AFTER role,
ADD COLUMN merged_into_user_id INT NULL AFTER is_manual,
ADD INDEX idx_manual_users (is_manual),
ADD INDEX idx_merged_users (merged_into_user_id),
ADD FOREIGN KEY (merged_into_user_id) REFERENCES users(id);

-- Make discord_id nullable for manual users
ALTER TABLE users 
MODIFY COLUMN discord_id VARCHAR(20) NULL;

-- Record this migration
-- Description: Add resource goals and leaderboard tracking
-- Date: 2025-08-03

-- Create resource goals table
CREATE TABLE IF NOT EXISTS resource_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,
    target_amount INT NOT NULL,
    current_amount INT DEFAULT 0,
    deadline DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
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

-- Record this migration
-- Description: Add scheduling, templates, distributions, and metrics for farming runs
-- Date: 2025-08-03

-- Add scheduling fields to farming_runs
ALTER TABLE farming_runs 
ADD COLUMN scheduled_for DATETIME NULL AFTER started_at,
ADD COLUMN is_scheduled BOOLEAN DEFAULT FALSE AFTER status,
ADD COLUMN reminder_sent BOOLEAN DEFAULT FALSE AFTER is_scheduled,
ADD INDEX idx_scheduled_runs (is_scheduled, scheduled_for);

-- Create run templates table
CREATE TABLE IF NOT EXISTS run_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    run_type ENUM('spice', 'mining', 'mixed') DEFAULT 'mixed',
    default_location VARCHAR(255),
    default_notes TEXT,
    created_by INT NOT NULL,
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
    participation_rate DECIMAL(5,2), -- percentage of members who joined
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

-- Record this migration
-- Description: Allow NULL in created_by columns for user deletion
-- Date: 2025-08-03

-- Allow NULL in farming_runs.created_by
ALTER TABLE farming_runs 
MODIFY COLUMN created_by INT NULL;

-- Allow NULL in resource_goals.created_by
ALTER TABLE resource_goals 
MODIFY COLUMN created_by INT NULL;

-- Allow NULL in run_templates.created_by
ALTER TABLE run_templates 
MODIFY COLUMN created_by INT NULL;

-- Record this migration
-- Description: Add user approval system
-- Date: 2025-08-04

-- Add approval status to users table
ALTER TABLE users 
ADD COLUMN approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' AFTER role,
ADD COLUMN approved_at DATETIME NULL AFTER approval_status,
ADD COLUMN approved_by INT NULL AFTER approved_at,
ADD COLUMN rejection_reason TEXT NULL AFTER approved_by,
ADD INDEX idx_approval_status (approval_status),
ADD FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- Approve all existing users (grandfathering them in)
UPDATE users 
SET approval_status = 'approved', 
    approved_at = NOW() 
WHERE approval_status = 'pending';

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

-- Record this migration
-- Description: Add Discord webhook configuration
-- Date: 2025-08-04

-- Create webhook configurations table
CREATE TABLE IF NOT EXISTS webhook_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    webhook_url TEXT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    event_types JSON,  -- Array of event types this webhook should receive
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

-- Add notification preferences to users table
ALTER TABLE users
ADD COLUMN notification_preferences JSON AFTER rejection_reason;

-- Record this migration
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
ON DUPLICATE KEY UPDATE description = 'Add guild tax system';-- Migration: 016_add_guild_tax_system.sql
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
-- Description: Add current_stock column to resources table
-- Date: 2025-08-04

-- Add current_stock column if it doesn't exist
-- This column tracks the total contributions for each resource
SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'resources' 
AND COLUMN_NAME = 'current_stock';

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE resources ADD COLUMN current_stock DECIMAL(15,2) DEFAULT 0',
    'SELECT "Column current_stock already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update current_stock with existing contribution totals
UPDATE resources r
SET current_stock = (
    SELECT COALESCE(SUM(c.quantity), 0)
    FROM contributions c
    WHERE c.resource_id = r.id
);

-- Create trigger to update current_stock on new contributions
DELIMITER //
CREATE TRIGGER IF NOT EXISTS update_current_stock_on_contribution
AFTER INSERT ON contributions
FOR EACH ROW
BEGIN
    UPDATE resources 
    SET current_stock = current_stock + NEW.quantity 
    WHERE id = NEW.resource_id;
END//
DELIMITER ;

-- Record this migration
ON DUPLICATE KEY UPDATE description = 'Add current_stock to resources';-- Migration: 017_add_withdrawals_system_fix.sql
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
UPDATE migrations SET description = 'Add withdrawal tracking system (fixed)' WHERE version = 17;-- Migration: 017_add_withdrawals_system.sql
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
