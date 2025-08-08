-- Migration: 003_simplify_resources.sql
-- Description: Simplify resources to just Titanium, Spice, Melange, and Stravidium
-- Date: 2025-08-03

-- First, delete all existing contributions and collections to avoid foreign key issues
DELETE FROM contributions;
DELETE FROM run_collections;
DELETE FROM run_refined_outputs;

-- Delete all existing resources
DELETE FROM resources;

-- Reset auto increment
ALTER TABLE resources AUTO_INCREMENT = 1;

-- Insert only the four simple resources
INSERT INTO resources (id, name, category, description, can_be_refined, refined_into_id, base_conversion_rate) VALUES
(1, 'Titanium', 'Metal', 'Strong metal used for crafting', FALSE, NULL, NULL),
(2, 'Spice', 'Raw Material', 'Raw spice harvested from the desert', TRUE, NULL, NULL),
(3, 'Melange', 'Refined', 'Refined spice - the most valuable substance', FALSE, NULL, NULL),
(4, 'Stravidium', 'Metal', 'Rare metal with unique properties', FALSE, NULL, NULL);

-- Update Spice to refine into Melange
UPDATE resources SET refined_into_id = 3, base_conversion_rate = 0.85 WHERE id = 2;

-- Record this migration
INSERT INTO migrations (version, description) VALUES (3, 'Simplify resources to core four');