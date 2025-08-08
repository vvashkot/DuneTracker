<?php
// Admin fix script
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/config-loader.php';
require_once 'includes/db.php';

session_name(SESSION_NAME);
session_start();

echo "<h2>Admin Access Diagnostic & Fix</h2>";

// Check config
echo "<h3>1. Config Check:</h3>";
echo "Admin users in config: <pre>" . print_r($ADMIN_USERS, true) . "</pre>";
echo "Your Discord ID should be: 305074630744342528<br>";

// Check session
echo "<h3>2. Session Check:</h3>";
echo "Session contents: <pre>" . print_r($_SESSION, true) . "</pre>";

if (isset($_SESSION['user_id'])) {
    echo "Logged in as user ID: " . $_SESSION['user_id'] . "<br>";
    echo "Discord ID in session: " . ($_SESSION['discord_id'] ?? 'NOT SET') . "<br>";
} else {
    echo "Not logged in!<br>";
}

// Check database
echo "<h3>3. Database Check:</h3>";
try {
    $db = getDB();
    
    // Get your user record
    if (isset($_SESSION['discord_id'])) {
        $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ?");
        $stmt->execute([$_SESSION['discord_id']]);
    } elseif (isset($_SESSION['user_id'])) {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = '305074630744342528'");
        $stmt->execute();
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "User found in database:<br>";
        echo "- Database ID: " . $user['id'] . "<br>";
        echo "- Discord ID: " . $user['discord_id'] . "<br>";
        echo "- Username: " . $user['username'] . "<br>";
        echo "- Role: <strong>" . $user['role'] . "</strong><br>";
        echo "- Approval: " . $user['approval_status'] . "<br>";
        
        // FIX: Update to admin if needed
        if ($user['discord_id'] == '305074630744342528' && $user['role'] != 'admin') {
            echo "<br><strong>FIXING: Making you admin...</strong><br>";
            $stmt = $db->prepare("UPDATE users SET role = 'admin', approval_status = 'approved' WHERE discord_id = ?");
            $stmt->execute(['305074630744342528']);
            echo "✓ You are now admin!<br>";
            
            // Update session
            $_SESSION['role'] = 'admin';
            $_SESSION['is_admin'] = true;
        }
        
        // Update session with correct values
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['discord_id'] = $user['discord_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['is_admin'] = ($user['role'] == 'admin' || in_array($user['discord_id'], $ADMIN_USERS));
        
        echo "<br>✓ Session updated!<br>";
        
    } else {
        echo "User not found in database!<br>";
        echo "You need to login first: <a href='/login.php'>Login with Discord</a><br>";
    }
    
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "<br>";
}

// Check the isAdmin function
echo "<h3>4. Admin Check Function:</h3>";
if (function_exists('isAdmin')) {
    $is_admin = isAdmin();
    echo "isAdmin() returns: " . ($is_admin ? 'TRUE' : 'FALSE') . "<br>";
} else {
    echo "isAdmin function not found<br>";
}

// Show how to check admin status
echo "<h3>5. Admin Status:</h3>";
$admin_check_results = [
    'In $ADMIN_USERS array' => in_array($_SESSION['discord_id'] ?? '', $ADMIN_USERS),
    'Role is admin' => ($_SESSION['role'] ?? '') == 'admin',
    'Session is_admin flag' => $_SESSION['is_admin'] ?? false,
    'Database role' => ($user['role'] ?? '') == 'admin'
];

foreach ($admin_check_results as $check => $result) {
    echo $check . ": " . ($result ? '✓ YES' : '✗ NO') . "<br>";
}

echo "<hr>";
echo "<h3>Actions:</h3>";
echo "<a href='/admin/' class='button'>Try Admin Panel</a> | ";
echo "<a href='/logout.php' class='button'>Logout</a> | ";
echo "<a href='/login.php' class='button'>Login Again</a> | ";
echo "<a href='/' class='button'>Homepage</a>";

echo "<hr>";
echo "<p style='color:red;'>⚠️ Delete this file after fixing admin access!</p>";
?>