-- Migration: 012_farming_run_enhancements.sql
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
INSERT INTO migrations (version, description) VALUES (12, 'Add farming run scheduling, templates, distributions, and metrics');