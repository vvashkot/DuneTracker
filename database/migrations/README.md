# Database Migrations

This directory contains incremental database changes. Each migration should be a numbered SQL file.

## Naming Convention
- `001_initial_schema.sql`
- `002_add_user_roles.sql`
- `003_add_resource_icons.sql`

## How to Apply Migrations

1. Check current migration version:
```sql
SELECT * FROM migrations ORDER BY version DESC LIMIT 1;
```

2. Apply new migrations in order:
```sql
SOURCE /path/to/migration/002_add_user_roles.sql;
INSERT INTO migrations (version, description, applied_at) 
VALUES (2, 'Add user roles', NOW());
```

## Creating the Migrations Table

Run this once on production:
```sql
CREATE TABLE IF NOT EXISTS migrations (
    version INT PRIMARY KEY,
    description VARCHAR(255),
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```