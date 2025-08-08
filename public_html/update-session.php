<?php
// Fix session structure
require_once 'includes/config-loader.php';
require_once 'includes/db.php';

session_name(SESSION_NAME);
session_start();

echo "<h2>Fixing Session Structure</h2>";

// Get user from database
$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ?");
$stmt->execute(['305074630744342528']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    // Restructure session properly
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['discord_id'] = $user['discord_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['avatar'] = $user['avatar'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['is_admin'] = true; // Since you're in ADMIN_USERS and role is admin
    $_SESSION['logged_in'] = true;
    
    // Keep the existing data too
    $_SESSION['login_time'] = $_SESSION['login_time'] ?? time();
    $_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    
    echo "✓ Session restructured!<br><br>";
    echo "Session now contains:<br>";
    echo "- user_id: " . $_SESSION['user_id'] . "<br>";
    echo "- discord_id: " . $_SESSION['discord_id'] . "<br>";
    echo "- username: " . $_SESSION['username'] . "<br>";
    echo "- role: " . $_SESSION['role'] . "<br>";
    echo "- is_admin: " . ($_SESSION['is_admin'] ? 'true' : 'false') . "<br>";
    echo "- logged_in: " . ($_SESSION['logged_in'] ? 'true' : 'false') . "<br>";
    
    echo "<br><strong>✓ You should now have admin access!</strong><br><br>";
    
    echo "<a href='/admin/' style='padding:10px; background:#007bff; color:white; text-decoration:none;'>Go to Admin Panel</a> ";
    echo "<a href='/' style='padding:10px; background:#28a745; color:white; text-decoration:none;'>Go to Homepage</a>";
    
} else {
    echo "Error: User not found in database!";
}

echo "<hr><p style='color:red;'>⚠️ Delete this file after use!</p>";
?>