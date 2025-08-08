-- Migration: 018_add_in_game_name.sql
-- Description: Add in-game name field to users for associating game identity with Discord
-- Date: 2025-08-08

-- Add in_game_name to users table
ALTER TABLE users
ADD COLUMN in_game_name VARCHAR(100) NULL AFTER username,
ADD INDEX idx_in_game_name (in_game_name);

-- Record this migration
INSERT INTO migrations (version, description) VALUES (18, 'Add in_game_name to users');

