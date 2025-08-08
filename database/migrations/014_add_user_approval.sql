-- Migration: 014_add_user_approval.sql
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
INSERT INTO migrations (version, description) VALUES (14, 'Add user approval system');