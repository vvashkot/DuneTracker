<?php
/**
 * Admin Panel - Main Dashboard
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();

// Get dashboard stats
$db = getDB();
$stats = [
    'total_users' => $db->query("SELECT COUNT(*) FROM users WHERE merged_into_user_id IS NULL")->fetchColumn(),
    'active_runs' => $db->query("SELECT COUNT(*) FROM farming_runs WHERE status = 'active'")->fetchColumn(),
    'total_contributions' => $db->query("SELECT COUNT(*) FROM contributions")->fetchColumn(),
    'total_resources' => $db->query("SELECT COUNT(*) FROM resources")->fetchColumn(),
    'pending_approvals' => $db->query("SELECT COUNT(*) FROM users WHERE approval_status = 'pending'")->fetchColumn(),
];

// Get recent activity
$recent_activity = getActivityLogs(20);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?php echo htmlspecialchars(APP_NAME); ?></title>
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
        
        .admin-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        .admin-card {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .admin-card h3 {
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .stat-item {
            background-color: var(--bg-tertiary);
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }
        
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.875rem;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            margin-right: 0.75rem;
        }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-action {
            font-weight: 500;
            color: var(--text-primary);
        }
        
        .activity-time {
            font-size: 0.75rem;
            color: var(--text-tertiary);
        }
        
        .admin-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        @media (max-width: 768px) {
            .admin-grid {
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
            <h2>Admin Dashboard</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Back to Site</a>
            </div>
        </div>

        <nav class="admin-nav">
            <ul>
                <li><a href="/admin/" class="active">📊 Dashboard</a></li>
                <li><a href="/admin/users.php">👥 Users</a></li>
                <li><a href="/admin/approvals.php">✅ Approvals</a></li>
                <li><a href="/admin/contributions.php">💰 Contributions</a></li>
                <li><a href="/admin/hub.php">🏠 Hub</a></li>
                <li><a href="/admin/landsraad.php">🏛️ Landsraad</a></li>
                <li><a href="/admin/combat.php">⚔️ Combat</a></li>
                <li><a href="/admin/withdrawals.php">📤 Withdrawals</a></li>
                <li><a href="/admin/resources.php">📦 Resources</a></li>
                <li><a href="/admin/runs.php">🚜 Farming Runs</a></li>
                <li><a href="/admin/logs.php">📋 Activity Logs</a></li>
                <li><a href="/admin/webhooks.php">🔔 Webhooks</a></li>
                <li><a href="/admin/backup.php">💾 Backup</a></li>
            </ul>
        </nav>

        <?php if ($stats['pending_approvals'] > 0): ?>
            <div class="alert alert-warning" style="margin-bottom: 2rem;">
                <strong>⚠️ Pending Approvals:</strong> 
                There are <?php echo $stats['pending_approvals']; ?> user(s) waiting for approval. 
                <a href="/admin/approvals.php" style="color: inherit; text-decoration: underline;">Review now →</a>
            </div>
        <?php endif; ?>

        <div class="admin-grid">
            <div class="admin-card">
                <h3>📊 Statistics</h3>
                <div class="stat-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo number_format($stats['total_users']); ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo number_format($stats['active_runs']); ?></div>
                        <div class="stat-label">Active Runs</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo number_format($stats['total_contributions']); ?></div>
                        <div class="stat-label">Contributions</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo number_format($stats['total_resources']); ?></div>
                        <div class="stat-label">Resource Types</div>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <h3>🕒 Recent Activity</h3>
                <div class="activity-list">
                    <?php if (empty($recent_activity)): ?>
                        <div class="empty-state">No recent activity</div>
                    <?php else: ?>
                        <?php foreach ($recent_activity as $activity): ?>
                            <div class="activity-item">
                                <img src="<?php echo htmlspecialchars(getAvatarUrl([
                                    'avatar' => $activity['avatar'],
                                    'discord_id' => $activity['discord_id'] ?? $activity['user_id']
                                ])); ?>" alt="Avatar" class="activity-avatar">
                                <div class="activity-details">
                                    <div class="activity-action">
                                        <?php echo htmlspecialchars($activity['username']); ?> - 
                                        <?php echo htmlspecialchars($activity['action']); ?>
                                    </div>
                                    <?php if ($activity['details']): ?>
                                        <div class="activity-time"><?php echo htmlspecialchars($activity['details']); ?></div>
                                    <?php endif; ?>
                                    <div class="activity-time"><?php echo date('M d, H:i', strtotime($activity['created_at'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="admin-actions">
            <a href="/admin/users.php" class="btn btn-primary">Manage Users</a>
            <a href="/admin/resources.php" class="btn btn-primary">Manage Resources</a>
            <a href="/admin/runs.php" class="btn btn-primary">Manage Runs</a>
        </div>
    </div>
</body>
</html>