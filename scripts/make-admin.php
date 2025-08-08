#!/usr/bin/env php
<?php
/**
 * Script to make a user an admin
 * Usage: php make-admin.php <discord_id>
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

if ($argc < 2) {
    echo "Usage: php make-admin.php <discord_id>\n";
    echo "Example: php make-admin.php 123456789012345678\n";
    exit(1);
}

$discord_id = $argv[1];

// Load database connection
require_once __DIR__ . '/../public_html/includes/db.php';

try {
    $db = getDB();
    
    // Check if user exists
    $stmt = $db->prepare("SELECT id, username, role FROM users WHERE discord_id = ?");
    $stmt->execute([$discord_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "Error: User with Discord ID $discord_id not found.\n";
        echo "The user must log in at least once before they can be made an admin.\n";
        exit(1);
    }
    
    if ($user['role'] === 'admin') {
        echo "User {$user['username']} is already an admin.\n";
        exit(0);
    }
    
    // Update user role to admin
    $stmt = $db->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    echo "Success: User {$user['username']} (Discord ID: $discord_id) is now an admin.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}