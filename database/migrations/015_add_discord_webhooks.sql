-- Migration: 015_add_discord_webhooks.sql
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
INSERT INTO migrations (version, description) VALUES (15, 'Add Discord webhook support');