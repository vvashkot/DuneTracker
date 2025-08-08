<?php
/**
 * Production Configuration for Hostinger Hosting
 * Copy this to config.local.php and update with your values
 */

// Database Configuration - Update these with your Hostinger MySQL details
define('DB_HOST', 'localhost'); // Usually localhost on Hostinger
define('DB_NAME', 'your_database_name'); // Your Hostinger database name
define('DB_USER', 'your_database_user'); // Your Hostinger database user
define('DB_PASS', 'your_database_password'); // Your Hostinger database password

// Discord OAuth Configuration
define('DISCORD_CLIENT_ID', '1401618920301133906');
define('DISCORD_CLIENT_SECRET', 'CHv44GjgACyh4hS_krkDjL6lCiStClc8');

// Production domain
define('BASE_URL', 'https://houserubi-ka.com'); // No trailing slash
define('DISCORD_REDIRECT_URI', BASE_URL . '/callback.php');
define('DISCORD_SCOPE', 'identify');

// Application Settings
define('APP_NAME', 'Dune Awakening Tracker');
define('GUILD_NAME', 'House Rubi-Ka');
define('APP_URL', 'https://houserubi-ka.com');

// Session Configuration
define('SESSION_NAME', 'dune_tracker_session');
define('SESSION_LIFETIME', 86400); // 24 hours

// Security
define('CSRF_TOKEN_NAME', 'csrf_token');

// Optional: Guild Verification
define('REQUIRED_GUILD_ID', null);

// Admin Users (Discord IDs)
$ADMIN_USERS = [
    '305074630744342528', // Charlie Anderson
];

// Error Reporting - Disable for production
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Timezone
date_default_timezone_set('UTC');

// File Upload Settings
ini_set('upload_max_filesize', '10M');
ini_set('post_max_size', '10M');

// Create logs directory if it doesn't exist
$log_dir = dirname(__DIR__) . '/logs';
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}