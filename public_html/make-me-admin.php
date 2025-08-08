<?php
require_once 'includes/config-loader.php';
require_once 'includes/db.php';

// Your Discord ID
$discord_id = '305074630744342528';

try {
    $db = getDB();
    
    // Check if user exists
    $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ?");
    $stmt->execute([$discord_id]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Update to admin
        $stmt = $db->prepare("
            UPDATE users 
            SET role = 'admin', 
                approval_status = 'approved',
                approved_at = NOW()
            WHERE discord_id = ?
        ");
        $stmt->execute([$discord_id]);
        
        echo "<h2>Success!</h2>";
        echo "User '{$user['username']}' (ID: {$discord_id}) is now an admin!<br><br>";
        echo "Current status:<br>";
        echo "- Role: admin<br>";
        echo "- Approval: approved<br><br>";
        echo "<a href='/'>Go to Homepage</a> | <a href='/admin/'>Go to Admin Panel</a>";
    } else {
        echo "User with Discord ID {$discord_id} not found.<br>";
        echo "Please login first at <a href='/login.php'>Login Page</a>";
    }
    
    // Show all users
    echo "<hr><h3>All Users:</h3>";
    $stmt = $db->query("SELECT discord_id, username, role, approval_status FROM users");
    $users = $stmt->fetchAll();
    echo "<table border='1'>";
    echo "<tr><th>Discord ID</th><th>Username</th><th>Role</th><th>Status</th></tr>";
    foreach ($users as $u) {
        echo "<tr>";
        echo "<td>{$u['discord_id']}</td>";
        echo "<td>{$u['username']}</td>";
        echo "<td>{$u['role']}</td>";
        echo "<td>{$u['approval_status']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// Delete this file after use for security
echo "<hr><p style='color:red;'>⚠️ Delete this file after making yourself admin!</p>";
?>