-- Migration: 010_add_manual_users.sql
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
INSERT INTO migrations (version, description) VALUES (10, 'Add support for manual users without Discord');