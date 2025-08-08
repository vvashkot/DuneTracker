<?php
// Test what's happening on index.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Testing Index Page</h2>";

// Start session
session_name('dune_tracker_session');
session_start();

echo "<h3>Session Status:</h3>";
echo "Session ID: " . session_id() . "<br>";
echo "Session contents: <pre>" . print_r($_SESSION, true) . "</pre>";

// Try to include auth
echo "<h3>Loading Auth:</h3>";
try {
    require_once 'includes/auth.php';
    echo "✓ Auth loaded<br>";
    
    if (isLoggedIn()) {
        echo "User is logged in<br>";
        echo "User ID: " . $_SESSION['user_id'] . "<br>";
    } else {
        echo "User is NOT logged in - will redirect to login<br>";
        echo "<a href='/login.php'>Go to Login</a>";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>