-- Migration: 011_add_resource_goals.sql
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
INSERT INTO migrations (version, description) VALUES (11, 'Add resource goals and leaderboard tracking');