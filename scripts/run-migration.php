#!/usr/bin/env php
<?php
/**
 * Run a specific migration file
 * Usage: php run-migration.php <migration_file>
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

if ($argc < 2) {
    echo "Usage: php run-migration.php <migration_file>\n";
    echo "Example: php run-migration.php database/migrations/009_add_admin_role.sql\n";
    exit(1);
}

$migration_file = $argv[1];

if (!file_exists($migration_file)) {
    echo "Error: Migration file not found: $migration_file\n";
    exit(1);
}

// Load database connection
require_once __DIR__ . '/../public_html/includes/db.php';

try {
    $db = getDB();
    
    // Read migration file
    $sql = file_get_contents($migration_file);
    
    // Split by semicolons but not within strings
    $queries = preg_split('/;\s*$/m', $sql);
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($queries as $query) {
        $query = trim($query);
        if (empty($query)) {
            continue;
        }
        
        try {
            // Add semicolon back
            $query .= ';';
            
            echo "Executing: " . substr($query, 0, 60) . "...\n";
            $db->exec($query);
            $success_count++;
        } catch (PDOException $e) {
            echo "Error: " . $e->getMessage() . "\n";
            $error_count++;
            
            // Continue with other queries
            continue;
        }
    }
    
    echo "\nMigration complete:\n";
    echo "- Successful queries: $success_count\n";
    echo "- Failed queries: $error_count\n";
    
    if ($error_count > 0) {
        exit(1);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}