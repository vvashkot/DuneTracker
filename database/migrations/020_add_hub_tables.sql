-- Migration: 020_add_hub_tables.sql
-- Description: Hub chores and weekly registration tracking

-- Weekly registrations for hub duty
CREATE TABLE IF NOT EXISTS hub_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    week_start DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_week (user_id, week_start),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Move-in / Move-out assistance logs
CREATE TABLE IF NOT EXISTS hub_chore_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chore ENUM('move_in','move_out') NOT NULL,
    occurred_at DATETIME NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_chore_time (chore, occurred_at)
);

-- Circuit roster (weekly assignments)
CREATE TABLE IF NOT EXISTS circuit_roster (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    week_start DATE NOT NULL,
    role VARCHAR(50) NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_roster (user_id, week_start),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Optional: store weekly required filters
INSERT INTO guild_settings (setting_key, setting_value)
VALUES ('filters_required_per_week', '0')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

INSERT INTO migrations (version, description)
VALUES (20, 'Add hub tables for registrations, chores, circuit roster');

