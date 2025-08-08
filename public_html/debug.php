<?php
// Debug script to check what's wrong
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Debug Information</h2>";

// Check if config can be loaded
echo "<h3>1. Config Loading:</h3>";
if (file_exists('config.local.php')) {
    echo "✓ config.local.php exists<br>";
    require_once 'config.local.php';
    echo "✓ Config loaded<br>";
    echo "Database: " . DB_NAME . "<br>";
    echo "OAuth Redirect: " . DISCORD_REDIRECT_URI . "<br>";
} else {
    echo "✗ config.local.php NOT FOUND<br>";
}

// Test database connection
echo "<h3>2. Database Connection:</h3>";
try {
    $conn = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✓ Database connected<br>";
    
    // Check users table structure
    echo "<h3>3. Users Table Structure:</h3>";
    $stmt = $conn->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    foreach ($columns as $col) {
        echo $col['Field'] . " - " . $col['Type'] . "\n";
    }
    echo "</pre>";
    
    // Check for any users
    $stmt = $conn->query("SELECT COUNT(*) as count FROM users");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total users: " . $count['count'] . "<br>";
    
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "<br>";
}

// Check PHP version
echo "<h3>4. PHP Version:</h3>";
echo phpversion() . "<br>";

// Check session
echo "<h3>5. Session:</h3>";
session_start();
if (session_id()) {
    echo "✓ Sessions working<br>";
} else {
    echo "✗ Sessions not working<br>";
}

echo "<hr>";
echo "<p><a href='/'>Try Homepage</a> | <a href='/login.php'>Try Login</a></p>";