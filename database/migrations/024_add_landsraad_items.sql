-- Migration: 024_add_landsraad_items.sql
-- Description: Catalog of Landsraad turn-in items with base points per unit

CREATE TABLE IF NOT EXISTS landsraad_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    points_per_unit INT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active_name (active, name),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO migrations (version, description)
VALUES (24, 'Add landsraad_items table');


