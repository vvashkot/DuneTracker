-- Migration: 016b_add_current_stock.sql
-- Description: Add current_stock column to resources table
-- Date: 2025-08-04

-- Add current_stock column if it doesn't exist
-- This column tracks the total contributions for each resource
SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'resources' 
AND COLUMN_NAME = 'current_stock';

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE resources ADD COLUMN current_stock DECIMAL(15,2) DEFAULT 0',
    'SELECT "Column current_stock already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update current_stock with existing contribution totals
UPDATE resources r
SET current_stock = (
    SELECT COALESCE(SUM(c.quantity), 0)
    FROM contributions c
    WHERE c.resource_id = r.id
);

-- Create trigger to update current_stock on new contributions
DELIMITER //
CREATE TRIGGER IF NOT EXISTS update_current_stock_on_contribution
AFTER INSERT ON contributions
FOR EACH ROW
BEGIN
    UPDATE resources 
    SET current_stock = current_stock + NEW.quantity 
    WHERE id = NEW.resource_id;
END//
DELIMITER ;

-- Record this migration
INSERT INTO migrations (version, description) VALUES (16.5, 'Add current_stock to resources')
ON DUPLICATE KEY UPDATE description = 'Add current_stock to resources';