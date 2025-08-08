-- Dune Awakening Guild Resource Tracker Database Schema

-- Create database (if needed)
-- CREATE DATABASE IF NOT EXISTS dune_tracker;
-- USE dune_tracker;

-- Users table to store Discord OAuth information
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  discord_id VARCHAR(50) UNIQUE NOT NULL,
  username VARCHAR(100) NOT NULL,
  avatar TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Resources table for different resource types
CREATE TABLE IF NOT EXISTS resources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  category VARCHAR(100),
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Contributions table to track resource submissions
CREATE TABLE IF NOT EXISTS contributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  resource_id INT NOT NULL,
  quantity INT NOT NULL,
  date_collected DATETIME DEFAULT CURRENT_TIMESTAMP,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE RESTRICT,
  INDEX idx_user_id (user_id),
  INDEX idx_resource_id (resource_id),
  INDEX idx_date_collected (date_collected)
);

-- Distributions table for tracking resource allocations
CREATE TABLE IF NOT EXISTS distributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  resource_id INT NOT NULL,
  quantity INT NOT NULL,
  date_given DATETIME DEFAULT CURRENT_TIMESTAMP,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE RESTRICT,
  INDEX idx_user_id (user_id),
  INDEX idx_resource_id (resource_id),
  INDEX idx_date_given (date_given)
);

-- Insert sample resource types for Dune Awakening
INSERT INTO resources (name, category, description) VALUES
('Melange (Spice)', 'Rare', 'The most valuable substance in the universe'),
('Water', 'Essential', 'Precious resource on Arrakis'),
('Stillsuit Components', 'Equipment', 'Parts for maintaining stillsuits'),
('Sandworm Teeth', 'Rare', 'Valuable crafting material'),
('Fremkit Components', 'Equipment', 'Traditional Fremen tools and materials'),
('Shield Generators', 'Technology', 'Personal shield technology'),
('Las-Gun Power Cells', 'Technology', 'Energy cells for las-guns'),
('Ornithopter Parts', 'Vehicle', 'Components for ornithopter maintenance'),
('Crysknife Materials', 'Weapon', 'Materials for crafting crysknives'),
('Desert Mouse Meat', 'Food', 'Protein source from desert fauna'),
('Spice Coffee', 'Consumable', 'Energizing beverage with trace melange'),
('Sand Compactor Parts', 'Equipment', 'Components for sand compaction devices'),
('Plasteel Ingots', 'Material', 'Durable construction material'),
('Solari Credits', 'Currency', 'Imperial currency'),
('Guild Seals', 'Currency', 'Guild-specific currency or reputation tokens');