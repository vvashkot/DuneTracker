-- Migration: 005_update_melange_recipe.sql
-- Description: Update Melange to use proper crafting recipe (75000 water + 10000 spice = 200 melange)
-- Date: 2025-08-03

-- Remove the simple refinement relationship for Spice
UPDATE resources SET refined_into_id = NULL, base_conversion_rate = NULL WHERE id = 2;

-- Add Melange crafting recipe (75000 Water + 10000 Spice = 200 Melange)
INSERT INTO crafting_recipes (output_resource_id, output_quantity) VALUES (5, 200);
SET @melange_recipe_id = LAST_INSERT_ID();

INSERT INTO recipe_ingredients (recipe_id, resource_id, quantity) VALUES
(@melange_recipe_id, 2, 10000),   -- 10000 Spice
(@melange_recipe_id, 4, 75000);   -- 75000 Water

-- Update Spice to not be refinable since it uses crafting recipe now
UPDATE resources SET can_be_refined = FALSE WHERE id = 2;

-- Record this migration
INSERT INTO migrations (version, description) VALUES (5, 'Update Melange to use proper crafting recipe');