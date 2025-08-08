-- Migration: 006_simplify_categories.sql
-- Description: Simplify resource categories to just Raw Materials and Refined
-- Date: 2025-08-03

-- Update all intermediate and final products to "Refined"
UPDATE resources SET category = 'Refined' WHERE category IN ('Intermediate', 'Final Product');

-- Specifically update our resources to ensure correct categories
UPDATE resources SET category = 'Raw Materials' WHERE name IN ('Titanium Ore', 'Spice', 'Stravidium Mass', 'Water');
UPDATE resources SET category = 'Refined' WHERE name IN ('Melange', 'Stravidium Fiber', 'Plastanium Ingot');

-- Record this migration
INSERT INTO migrations (version, description) VALUES (6, 'Simplify categories to Raw Materials and Refined');