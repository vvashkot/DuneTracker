<?php
/**
 * Admin Panel - User Management
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Handle user operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    switch ($_POST['action']) {
        case 'update_role':
            $user_id = intval($_POST['user_id']);
            $new_role = $_POST['role'];
            
            if (in_array($new_role, ['member', 'admin'])) {
                if (updateUserRole($user_id, $new_role)) {
                    logActivity($user['db_id'], 'Updated user role', 
                        "User ID: $user_id, New role: $new_role", 
                        $_SERVER['REMOTE_ADDR']);
                    $message = 'User role updated successfully';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to update user role';
                    $message_type = 'error';
                }
            }
            break;
        
        case 'update_ign':
            $target_user_id = intval($_POST['user_id']);
            $in_game_name = trim($_POST['in_game_name'] ?? '');
            if ($target_user_id) {
                if (updateUserInGameName($target_user_id, $in_game_name)) {
                    logActivity($user['db_id'], 'Updated in-game name',
                        "User ID: $target_user_id, IGN: $in_game_name",
                        $_SERVER['REMOTE_ADDR']);
                    $message = 'In-game name updated';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to update in-game name';
                    $message_type = 'error';
                }
            }
            break;
            
        case 'create_manual':
            $username = trim($_POST['username']);
            
            if (empty($username)) {
                $message = 'Username is required';
                $message_type = 'error';
            } else {
                try {
                    $new_user_id = createManualUser($username);
                    logActivity($user['db_id'], 'Created manual user', 
                        "Username: $username, User ID: $new_user_id", 
                        $_SERVER['REMOTE_ADDR']);
                    $message = "Manual user '$username' created successfully";
                    $message_type = 'success';
                } catch (Exception $e) {
                    $message = 'Failed to create user: ' . $e->getMessage();
                    $message_type = 'error';
                }
            }
            break;
            
        case 'merge_users':
            $manual_user_id = intval($_POST['manual_user_id']);
            $discord_user_id = intval($_POST['discord_user_id']);
            
            if ($manual_user_id && $discord_user_id && $manual_user_id !== $discord_user_id) {
                try {
                    mergeUsers($manual_user_id, $discord_user_id);
                    logActivity($user['db_id'], 'Merged users', 
                        "Manual user ID: $manual_user_id into Discord user ID: $discord_user_id", 
                        $_SERVER['REMOTE_ADDR']);
                    $message = 'Users merged successfully';
                    $message_type = 'success';
                } catch (Exception $e) {
                    $message = 'Failed to merge users: ' . $e->getMessage();
                    $message_type = 'error';
                }
            } else {
                $message = 'Invalid user selection for merge';
                $message_type = 'error';
            }
            break;
    }
}

// Get all users
$users = getAllUsersWithRoles();

// Get manual users for merge dropdown
$manual_users = getManualUsers();

// Get Discord users for merge dropdown
$db = getDB();
$discord_users = $db->query("
    SELECT id, username, discord_id 
    FROM users 
    WHERE is_manual = FALSE 
    AND merged_into_user_id IS NULL 
    ORDER BY username
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin Panel</title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <style>
        .admin-nav {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .admin-nav ul {
            list-style: none;
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
        }
        
        .admin-nav a {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-secondary);
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .admin-nav a:hover,
        .admin-nav a.active {
            color: var(--primary-color);
        }
        
        .user-table {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .user-table table {
            width: 100%;
        }
        
        .user-table th {
            background-color: var(--bg-tertiary);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .user-table td {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .user-table tr:hover td {
            background-color: var(--bg-tertiary);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }
        
        .user-details {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .user-id {
            font-size: 0.75rem;
            color: var(--text-tertiary);
        }
        
        .role-select {
            padding: 0.5rem 1rem;
            background-color: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 0.875rem;
        }
        
        .save-btn {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            margin-left: 0.5rem;
        }
        
        .stats-cell {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .last-active {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .manual-user-badge {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            background-color: rgba(255, 193, 7, 0.2);
            color: var(--warning-color);
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
        
        .management-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .management-card {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .management-card h3 {
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .management-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <h1 class="nav-title"><?php echo htmlspecialchars(APP_NAME); ?> - Admin</h1>
            <div class="nav-user">
                <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" alt="Avatar" class="user-avatar">
                <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
                <a href="/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h2>User Management</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Back to Site</a>
            </div>
        </div>

        <nav class="admin-nav">
            <ul>
                <li><a href="/admin/">📊 Dashboard</a></li>
                <li><a href="/admin/users.php" class="active">👥 Users</a></li>
                <li><a href="/admin/contributions.php">💰 Contributions</a></li>
                <li><a href="/admin/withdrawals.php">📤 Withdrawals</a></li>
                <li><a href="/admin/resources.php">📦 Resources</a></li>
                <li><a href="/admin/runs.php">🚜 Farming Runs</a></li>
                <li><a href="/admin/logs.php">📋 Activity Logs</a></li>
                <li><a href="/admin/webhooks.php">🔔 Webhooks</a></li>
                <li><a href="/admin/backup.php">💾 Backup</a></li>
            </ul>
        </nav>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="management-grid">
            <div class="management-card">
                <h3>Create Manual User</h3>
                <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.875rem;">
                    Create a user account for guild members who don't have Discord
                </p>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create_manual">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" 
                               name="username" 
                               id="username" 
                               class="form-control" 
                               placeholder="Enter username"
                               required>
                    </div>
                    <button type="submit" class="btn btn-primary">Create Manual User</button>
                </form>
            </div>
            
            <div class="management-card">
                <h3>Merge Users</h3>
                <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.875rem;">
                    Transfer all data from a manual user to their Discord account
                </p>
                <?php if (empty($manual_users)): ?>
                    <p style="color: var(--text-tertiary);">No manual users available to merge</p>
                <?php else: ?>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="merge_users">
                        <div class="form-group">
                            <label for="manual_user_id">Manual User</label>
                            <select name="manual_user_id" id="manual_user_id" class="form-control" required>
                                <option value="">Select manual user...</option>
                                <?php foreach ($manual_users as $mu): ?>
                                    <option value="<?php echo $mu['id']; ?>">
                                        <?php echo htmlspecialchars($mu['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="discord_user_id">Merge Into Discord User</label>
                            <select name="discord_user_id" id="discord_user_id" class="form-control" required>
                                <option value="">Select Discord user...</option>
                                <?php foreach ($discord_users as $du): ?>
                                    <option value="<?php echo $du['id']; ?>">
                                        <?php echo htmlspecialchars($du['username']); ?>
                                        (<?php echo htmlspecialchars($du['discord_id']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" 
                                class="btn btn-warning"
                                onclick="return confirm('Are you sure? This will transfer all contributions, farming runs, and activity from the manual user to the Discord user. This action cannot be undone.')">
                            Merge Users
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="user-table">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>In-Game Name</th>
                        <th>Role</th>
                        <th>Contributions</th>
                        <th>Runs</th>
                        <th>Last Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <img src="<?php echo htmlspecialchars(getAvatarUrl($u)); ?>" 
                                         alt="Avatar" 
                                         class="user-avatar">
                                    <div class="user-details">
                                        <span class="user-name">
                                            <?php echo htmlspecialchars($u['username']); ?>
                                            <?php if ($u['is_manual']): ?>
                                                <span class="manual-user-badge">Manual</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="user-id">
                                            <?php if ($u['discord_id']): ?>
                                                ID: <?php echo htmlspecialchars($u['discord_id']); ?>
                                            <?php else: ?>
                                                Manual User
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <form method="POST" style="display:flex; gap:0.5rem; align-items:center;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="update_ign">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <input type="text" 
                                           name="in_game_name" 
                                           value="<?php echo htmlspecialchars($u['in_game_name'] ?? ''); ?>" 
                                           class="form-control" 
                                           placeholder="IGN">
                                    <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" style="display: flex; align-items: center;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="update_role">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <select name="role" class="role-select" onchange="this.form.submit()">
                                        <option value="member" <?php echo $u['role'] === 'member' ? 'selected' : ''; ?>>Member</option>
                                        <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    </select>
                                </form>
                            </td>
                            <td class="stats-cell"><?php echo number_format($u['total_contributions']); ?></td>
                            <td class="stats-cell"><?php echo number_format($u['total_runs']); ?></td>
                            <td class="last-active">
                                <?php 
                                echo $u['last_active'] 
                                    ? date('M d, Y H:i', strtotime($u['last_active'])) 
                                    : 'Never';
                                ?>
                            </td>
                            <td>
                                <a href="/contributions.php?user=<?php echo $u['id']; ?>" 
                                   class="btn btn-secondary btn-sm">View Activity</a>
                                <?php if ($u['id'] !== $user['db_id']): ?>
                                    <a href="/admin/delete-user.php?id=<?php echo $u['id']; ?>" 
                                       class="btn btn-danger btn-sm"
                                       onclick="return confirm('Are you sure you want to delete this user?')">Delete</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>