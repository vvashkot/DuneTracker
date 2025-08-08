-- Migration: 007_remove_water.sql
-- Description: Remove water from resources and recipes
-- Date: 2025-08-03

-- Remove water from recipe ingredients
DELETE FROM recipe_ingredients WHERE resource_id = (SELECT id FROM resources WHERE name = 'Water');

-- Update recipes to remove water requirements
-- For Melange: was 10000 Spice + 75000 Water = 200 Melange, now just 10000 Spice = 200 Melange
-- For Stravidium Fiber: was 3 Stravidium Mass + 100 Water = 1 Fiber, now just 3 Stravidium Mass = 1 Fiber  
-- For Plastanium Ingot: was 4 Titanium Ore + 1 Stravidium Fiber + 1250 Water = 1 Ingot, now just 4 Titanium Ore + 1 Stravidium Fiber = 1 Ingot

-- Delete water resource (this will cascade delete from recipe_ingredients due to foreign key)
DELETE FROM resources WHERE name = 'Water';

-- Record this migration
INSERT INTO migrations (version, description) VALUES (7, 'Remove water from resources and recipes');