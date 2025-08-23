-- Migration: 026_add_landsraad_item_goals.sql
-- Description: Goals for Landsraad item turn-ins with grouping and stock logs

CREATE TABLE IF NOT EXISTS landsraad_item_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(120) NOT NULL,
    points_per_unit INT NOT NULL,
    target_points INT NOT NULL,
    required_qty INT NOT NULL,
    icon_url VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_active_item (active, item_name)
);

CREATE TABLE IF NOT EXISTS landsraad_goal_members (
    goal_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (goal_id, user_id),
    FOREIGN KEY (goal_id) REFERENCES landsraad_item_goals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS landsraad_goal_stock_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    goal_id INT NOT NULL,
    user_id INT NOT NULL,
    qty INT NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) NULL,
    FOREIGN KEY (goal_id) REFERENCES landsraad_item_goals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_goal_time (goal_id, occurred_at)
);

INSERT INTO migrations (version, description)
VALUES (26, 'Add landsraad item goals, members, and stock logs');


