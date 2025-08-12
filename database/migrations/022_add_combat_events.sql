-- Migration: 022_add_combat_events.sql
-- Description: Track combat statistics (ground/air kills)

CREATE TABLE IF NOT EXISTS combat_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('ground_kill','air_kill') NOT NULL,
    weapon VARCHAR(100) NULL,
    target VARCHAR(100) NULL,
    occurred_at DATETIME NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_combat_user_time (user_id, occurred_at),
    INDEX idx_combat_type (type),
    INDEX idx_combat_weapon (weapon)
);

INSERT INTO migrations (version, description)
VALUES (22, 'Add combat_events');

