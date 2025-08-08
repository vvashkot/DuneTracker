-- Migration: 004_update_crafting_chains.sql
-- Description: Update resources with proper crafting chains
-- Date: 2025-08-03

-- Clear existing data
DELETE FROM run_refined_outputs;
DELETE FROM run_collections;
DELETE FROM contributions;
DELETE FROM resources;

-- Reset auto increment
ALTER TABLE resources AUTO_INCREMENT = 1;

-- Create new crafting chain
-- Raw Materials
INSERT INTO resources (id, name, category, description, can_be_refined) VALUES
(1, 'Titanium Ore', 'Raw Material', 'Raw titanium ore mined from deposits', FALSE),
(2, 'Spice', 'Raw Material', 'Raw spice harvested from the desert', TRUE),
(3, 'Stravidium Mass', 'Raw Material', 'Raw stravidium material', TRUE),
(4, 'Water', 'Raw Material', 'Essential resource for crafting', FALSE);

-- Refined/Intermediate Materials
INSERT INTO resources (id, name, category, description, can_be_refined) VALUES
(5, 'Melange', 'Refined', 'Refined spice - the most valuable substance', FALSE),
(6, 'Stravidium Fiber', 'Intermediate', 'Refined fiber made from Stravidium Mass', FALSE);

-- Final Products
INSERT INTO resources (id, name, category, description, can_be_refined) VALUES
(7, 'Plastanium Ingot', 'Final Product', 'Advanced material made from Titanium and Stravidium Fiber', FALSE);

-- Update refinement relationships
UPDATE resources SET refined_into_id = 5, base_conversion_rate = 0.85 WHERE id = 2; -- Spice -> Melange
UPDATE resources SET refined_into_id = 6, base_conversion_rate = 0.33 WHERE id = 3; -- Stravidium Mass -> Fiber (3:1 ratio)

-- Create a new table for complex crafting recipes
CREATE TABLE IF NOT EXISTS crafting_recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    output_resource_id INT NOT NULL,
    output_quantity INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (output_resource_id) REFERENCES resources(id)
);

-- Create recipe ingredients table
CREATE TABLE IF NOT EXISTS recipe_ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT NOT NULL,
    resource_id INT NOT NULL,
    quantity INT NOT NULL,
    FOREIGN KEY (recipe_id) REFERENCES crafting_recipes(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id)
);

-- Add Stravidium Fiber recipe (3 Stravidium Mass + 100 Water = 1 Fiber)
INSERT INTO crafting_recipes (output_resource_id, output_quantity) VALUES (6, 1);
SET @fiber_recipe_id = LAST_INSERT_ID();
INSERT INTO recipe_ingredients (recipe_id, resource_id, quantity) VALUES
(@fiber_recipe_id, 3, 3),    -- 3 Stravidium Mass
(@fiber_recipe_id, 4, 100);  -- 100 Water

-- Add Plastanium Ingot recipe (4 Titanium Ore + 1 Stravidium Fiber + 1250 Water = 1 Ingot)
INSERT INTO crafting_recipes (output_resource_id, output_quantity) VALUES (7, 1);
SET @plastanium_recipe_id = LAST_INSERT_ID();
INSERT INTO recipe_ingredients (recipe_id, resource_id, quantity) VALUES
(@plastanium_recipe_id, 1, 4),     -- 4 Titanium Ore
(@plastanium_recipe_id, 6, 1),     -- 1 Stravidium Fiber
(@plastanium_recipe_id, 4, 1250);  -- 1250 Water

-- Record this migration
INSERT INTO migrations (version, description) VALUES (4, 'Add proper crafting chains for Stravidium and Plastanium');