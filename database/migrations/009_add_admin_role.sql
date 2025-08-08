-- Migration: 009_add_admin_role.sql
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
INSERT INTO migrations (version, description) VALUES (9, 'Add admin role system and activity logs');