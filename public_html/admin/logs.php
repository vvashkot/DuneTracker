<?php
/**
 * Admin Panel - Activity Logs
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();

// Get filter parameters
$user_filter = isset($_GET['user']) ? intval($_GET['user']) : null;
$limit = 100;

// Get activity logs
$logs = getActivityLogs($limit, $user_filter);

// Get all users for filter dropdown
$db = getDB();
$users = $db->query("SELECT id, username FROM users ORDER BY username")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - Admin Panel</title>
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
        
        .filter-bar {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .logs-table {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .logs-table table {
            width: 100%;
        }
        
        .logs-table th {
            background-color: var(--bg-tertiary);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .logs-table td {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.875rem;
        }
        
        .logs-table tr:hover td {
            background-color: var(--bg-tertiary);
        }
        
        .log-user {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .log-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
        }
        
        .log-action {
            font-weight: 500;
            color: var(--text-primary);
        }
        
        .log-details {
            color: var(--text-secondary);
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
        
        .log-time {
            color: var(--text-tertiary);
            white-space: nowrap;
        }
        
        .log-ip {
            color: var(--text-tertiary);
            font-family: monospace;
            font-size: 0.75rem;
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
            <h2>Activity Logs</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Back to Site</a>
            </div>
        </div>

        <nav class="admin-nav">
            <ul>
                <li><a href="/admin/">📊 Dashboard</a></li>
                <li><a href="/admin/users.php">👥 Users</a></li>
                <li><a href="/admin/contributions.php">💰 Contributions</a></li>
                <li><a href="/admin/withdrawals.php">📤 Withdrawals</a></li>
                <li><a href="/admin/resources.php">📦 Resources</a></li>
                <li><a href="/admin/runs.php">🚜 Farming Runs</a></li>
                <li><a href="/admin/logs.php" class="active">📋 Activity Logs</a></li>
                <li><a href="/admin/webhooks.php">🔔 Webhooks</a></li>
                <li><a href="/admin/backup.php">💾 Backup</a></li>
            </ul>
        </nav>

        <div class="filter-bar">
            <form method="GET" style="display: flex; gap: 1rem; align-items: center;">
                <label for="user">Filter by user:</label>
                <select name="user" id="user" class="form-control" style="width: auto;">
                    <option value="">All Users</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" 
                                <?php echo $user_filter == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($user_filter): ?>
                    <a href="?" class="btn btn-secondary">Clear Filter</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="logs-table">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="log-time">
                                <?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?>
                            </td>
                            <td>
                                <div class="log-user">
                                    <img src="<?php echo htmlspecialchars(getAvatarUrl([
                                        'avatar' => $log['avatar'],
                                        'discord_id' => $log['discord_id']
                                    ])); ?>" alt="Avatar" class="log-avatar">
                                    <span><?php echo htmlspecialchars($log['username']); ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="log-action"><?php echo htmlspecialchars($log['action']); ?></div>
                            </td>
                            <td>
                                <?php if ($log['details']): ?>
                                    <div class="log-details"><?php echo htmlspecialchars($log['details']); ?></div>
                                <?php else: ?>
                                    <span class="text-tertiary">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['ip_address']): ?>
                                    <span class="log-ip"><?php echo htmlspecialchars($log['ip_address']); ?></span>
                                <?php else: ?>
                                    <span class="text-tertiary">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="empty-state">No activity logs found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 1rem; text-align: center; color: var(--text-secondary); font-size: 0.875rem;">
            Showing up to <?php echo $limit; ?> most recent activities
        </div>
    </div>
</body>
</html>