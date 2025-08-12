-- Migration: 021_add_landsraad_points.sql
-- Description: Track Landsraad points per player

CREATE TABLE IF NOT EXISTS landsraad_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    points INT NOT NULL,
    category VARCHAR(50) NULL,
    occurred_at DATETIME NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_points_user_time (user_id, occurred_at)
);

INSERT INTO migrations (version, description)
VALUES (21, 'Add landsraad_points');

