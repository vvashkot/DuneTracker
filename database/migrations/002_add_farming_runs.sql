-- Migration: 002_add_farming_runs.sql
-- Description: Add farming runs/sessions tracking
-- Date: 2025-08-03

-- Table for farming runs/sessions
CREATE TABLE IF NOT EXISTS farming_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    run_type ENUM('spice', 'mining', 'mixed') DEFAULT 'mixed',
    location VARCHAR(255),
    started_at DATETIME NOT NULL,
    ended_at DATETIME,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    created_by INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_started_at (started_at)
);

-- Table for run participants
CREATE TABLE IF NOT EXISTS run_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('leader', 'member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    share_percentage DECIMAL(5,2) DEFAULT NULL, -- For custom splits
    FOREIGN KEY (run_id) REFERENCES farming_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_run_user (run_id, user_id)
);

-- Table for raw resources collected during the run
CREATE TABLE IF NOT EXISTS run_collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    resource_id INT NOT NULL,
    quantity INT NOT NULL,
    collected_by INT, -- NULL means group collection
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES farming_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id),
    FOREIGN KEY (collected_by) REFERENCES users(id),
    INDEX idx_run_id (run_id)
);

-- Table for refined outputs from the run
CREATE TABLE IF NOT EXISTS run_refined_outputs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    input_resource_id INT NOT NULL,
    output_resource_id INT NOT NULL,
    input_quantity INT NOT NULL,
    output_quantity INT NOT NULL,
    conversion_rate DECIMAL(10,4), -- e.g., 0.85 for 85% efficiency
    refined_by INT,
    refined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (run_id) REFERENCES farming_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (input_resource_id) REFERENCES resources(id),
    FOREIGN KEY (output_resource_id) REFERENCES resources(id),
    FOREIGN KEY (refined_by) REFERENCES users(id),
    INDEX idx_run_id (run_id)
);

-- Add refinement relationship to resources
ALTER TABLE resources 
ADD COLUMN can_be_refined BOOLEAN DEFAULT FALSE,
ADD COLUMN refined_into_id INT DEFAULT NULL,
ADD COLUMN base_conversion_rate DECIMAL(10,4) DEFAULT NULL,
ADD FOREIGN KEY (refined_into_id) REFERENCES resources(id);

-- Update existing resources with refinement data
UPDATE resources SET 
    can_be_refined = TRUE,
    refined_into_id = (SELECT id FROM (SELECT * FROM resources) r2 WHERE r2.name = 'Melange (Spice)' LIMIT 1),
    base_conversion_rate = 0.85
WHERE name = 'Raw Spice';

-- Add raw versions of resources that can be refined
INSERT INTO resources (name, category, description, can_be_refined, refined_into_id, base_conversion_rate) 
SELECT * FROM (
    SELECT 
        'Raw Spice' as name,
        'Raw Material' as category,
        'Unrefined spice harvested from the desert' as description,
        TRUE as can_be_refined,
        (SELECT id FROM resources WHERE name = 'Melange (Spice)') as refined_into_id,
        0.85 as base_conversion_rate
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM resources WHERE name = 'Raw Spice'
);

INSERT INTO resources (name, category, description, can_be_refined) VALUES
('Raw Titanium Ore', 'Raw Material', 'Unprocessed titanium ore', TRUE),
('Raw Stravidium Ore', 'Raw Material', 'Unprocessed stravidium ore', TRUE),
('Titanium Ingot', 'Refined Material', 'Refined titanium ready for crafting', FALSE),
('Stravidium Ingot', 'Refined Material', 'Refined stravidium ready for crafting', FALSE);

-- Update raw materials to point to refined versions
UPDATE resources SET 
    refined_into_id = (SELECT id FROM (SELECT * FROM resources) r2 WHERE r2.name = 'Titanium Ingot' LIMIT 1),
    base_conversion_rate = 0.75
WHERE name = 'Raw Titanium Ore';

UPDATE resources SET 
    refined_into_id = (SELECT id FROM (SELECT * FROM resources) r2 WHERE r2.name = 'Stravidium Ingot' LIMIT 1),
    base_conversion_rate = 0.70
WHERE name = 'Raw Stravidium Ore';

-- Record this migration
INSERT INTO migrations (version, description) VALUES (2, 'Add farming runs and refinement tracking');