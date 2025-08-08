-- Script to delete a user and handle their related data
-- Replace USER_ID_HERE with the actual user ID you want to delete

-- First, check what data exists for this user
SELECT 'User Info:' as '';
SELECT id, username, discord_id, role, is_manual FROM users WHERE id = USER_ID_HERE;

SELECT 'Contributions:' as '';
SELECT COUNT(*) as contribution_count FROM contributions WHERE user_id = USER_ID_HERE;

SELECT 'Farming Run Participations:' as '';
SELECT COUNT(*) as run_count FROM run_participants WHERE user_id = USER_ID_HERE;

SELECT 'Collections:' as '';
SELECT COUNT(*) as collection_count FROM run_collections WHERE collected_by = USER_ID_HERE;

-- Option 1: Transfer ownership to another user before deletion
-- UPDATE contributions SET user_id = NEW_USER_ID WHERE user_id = USER_ID_HERE;
-- UPDATE run_participants SET user_id = NEW_USER_ID WHERE user_id = USER_ID_HERE;
-- UPDATE run_collections SET collected_by = NEW_USER_ID WHERE collected_by = USER_ID_HERE;
-- UPDATE run_refined_outputs SET refined_by = NEW_USER_ID WHERE refined_by = USER_ID_HERE;
-- UPDATE activity_logs SET user_id = NEW_USER_ID WHERE user_id = USER_ID_HERE;

-- Option 2: Delete all related data (CASCADE)
-- This will delete the user and all their associated data
-- DELETE FROM users WHERE id = USER_ID_HERE;

-- Option 3: Soft delete by marking as merged (keeps data but hides user)
-- UPDATE users SET merged_into_user_id = USER_ID_HERE WHERE id = USER_ID_HERE;