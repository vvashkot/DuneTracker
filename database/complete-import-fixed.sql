-- Create migrations table first
CREATE TABLE IF NOT EXISTS `migrations` (
    `version` int NOT NULL PRIMARY KEY,
    `description` varchar(255),
    `applied_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Now import the rest of the schema