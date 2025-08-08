-- Fix avatar column if it's the wrong type
-- This fixes the common issue where avatar is varchar(255) but needs to be TEXT

ALTER TABLE users MODIFY COLUMN avatar TEXT;

-- Also ensure other columns that might cause issues are correct
ALTER TABLE users MODIFY COLUMN username VARCHAR(100) NOT NULL;
ALTER TABLE users MODIFY COLUMN discord_id VARCHAR(50) NOT NULL;

-- Check if approval_status exists and add it if missing
ALTER TABLE users 
  MODIFY COLUMN approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending';

-- Ensure your admin user is approved
UPDATE users 
SET approval_status = 'approved', role = 'admin' 
WHERE discord_id = '305074630744342528';

-- Show the current structure to verify
DESCRIBE users;