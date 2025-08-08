-- Migration: 013_allow_null_created_by.sql
-- Description: Allow NULL in created_by columns for user deletion
-- Date: 2025-08-03

-- Allow NULL in farming_runs.created_by
ALTER TABLE farming_runs 
MODIFY COLUMN created_by INT NULL;

-- Allow NULL in resource_goals.created_by
ALTER TABLE resource_goals 
MODIFY COLUMN created_by INT NULL;

-- Allow NULL in run_templates.created_by
ALTER TABLE run_templates 
MODIFY COLUMN created_by INT NULL;

-- Record this migration
INSERT INTO migrations (version, description) VALUES (13, 'Allow NULL in created_by columns for user deletion');