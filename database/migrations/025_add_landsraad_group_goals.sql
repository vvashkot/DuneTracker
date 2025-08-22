-- Migration: 025_add_landsraad_group_goals.sql
-- Description: Per-house Landsraad weekly point goals

CREATE TABLE IF NOT EXISTS landsraad_group_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    house_name VARCHAR(120) NOT NULL,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    target_points INT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_week_house (week_start, week_end, house_name),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO migrations (version, description)
VALUES (25, 'Add landsraad_group_goals table');


