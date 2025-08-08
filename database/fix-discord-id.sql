-- Fix discord_id column size
ALTER TABLE users MODIFY COLUMN discord_id VARCHAR(50) NOT NULL;

-- Also fix it in other tables that might have discord_id
ALTER TABLE approval_requests MODIFY COLUMN discord_id VARCHAR(50);

-- Ensure the webhook_logs table has correct size too if it has discord_id
-- (only run if column exists)
-- ALTER TABLE webhook_logs MODIFY COLUMN discord_id VARCHAR(50);

-- Show the updated structure
DESCRIBE users;