<?php
/**
 * Configuration file for Dune Awakening Guild Resource Tracker
 * 
 * IMPORTANT: Update these values with your actual credentials before deployment
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Discord OAuth Configuration
define('DISCORD_CLIENT_ID', 'your_discord_client_id');
define('DISCORD_CLIENT_SECRET', 'your_discord_client_secret');
define('DISCORD_REDIRECT_URI', 'https://yourdomain.com/callback.php');
define('DISCORD_SCOPE', 'identify');

// Application Settings
define('APP_NAME', 'Dune Awakening Guild Tracker');
define('APP_URL', 'https://yourdomain.com');
define('SESSION_NAME', 'dune_tracker_session');

// Optional: Guild Verification
// Set to null if you don't want to restrict to a specific guild
define('REQUIRED_GUILD_ID', null); // Replace with your guild ID if needed

// Security Settings
define('SESSION_LIFETIME', 86400); // 24 hours in seconds
define('CSRF_TOKEN_NAME', 'csrf_token');

// Admin Users (Discord IDs)
// Add Discord IDs of users who should have admin access
$ADMIN_USERS = [
    // 'discord_id_here',
];

// Timezone
date_default_timezone_set('UTC');

// Error Reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session Configuration
ini_set('session.name', SESSION_NAME);
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
ini_set('session.cookie_lifetime', SESSION_LIFETIME);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Enable only on HTTPS
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);

// Optional API Keys
if (!defined('OPENAI_API_KEY')) {
    // You can set this constant here, or place it in config.local.php, or use the env var OPENAI_API_KEY
    define('OPENAI_API_KEY', null);
}