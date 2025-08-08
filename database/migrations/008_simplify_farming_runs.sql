-- Migration: 008_simplify_farming_runs.sql
-- Description: Add run_number for auto-incrementing run names
-- Date: 2025-08-03

-- Add run_number column for auto-incrementing
ALTER TABLE farming_runs 
ADD COLUMN run_number INT UNSIGNED NOT NULL DEFAULT 0 AFTER id;

-- Update existing runs with sequential numbers
SET @row_number = 0;
UPDATE farming_runs 
SET run_number = (@row_number:=@row_number + 1)
ORDER BY created_at;

-- Create a trigger to auto-increment run_number for new runs
DELIMITER $$
CREATE TRIGGER before_insert_farming_runs
BEFORE INSERT ON farming_runs
FOR EACH ROW
BEGIN
    SET NEW.run_number = (SELECT IFNULL(MAX(run_number), 0) + 1 FROM farming_runs);
END$$
DELIMITER ;

-- Record this migration
INSERT INTO migrations (version, description) VALUES (8, 'Add run_number for auto-incrementing run names');