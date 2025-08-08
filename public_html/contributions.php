<?php
/**
 * View User Contributions
 */

require_once 'includes/auth.php';
require_once 'includes/db.php';

// Require login
requireLogin();

$current_user = getCurrentUser();

// Get user ID from query parameter
$view_user_id = intval($_GET['user'] ?? $current_user['db_id']);

// Get user details
$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$view_user_id]);
$view_user = $stmt->fetch();

if (!$view_user) {
    header('Location: /index.php');
    exit();
}

// Check if current user can view this page
$can_edit = isAdmin();
$is_own_profile = $view_user_id === $current_user['db_id'];

// Get user's contributions
$contributions = getUserContributions($view_user_id);
$contribution_totals = getUserContributionTotals($view_user_id);

// Get user's farming run history
$sql = "
    SELECT 
        fr.id,
        fr.name,
        fr.run_type,
        fr.started_at,
        fr.ended_at,
        fr.status,
        rp.role,
        COUNT(DISTINCT rc.id) as collections_count,
        SUM(rc.quantity) as total_collected
    FROM run_participants rp
    JOIN farming_runs fr ON rp.run_id = fr.id
    LEFT JOIN run_collections rc ON rc.run_id = fr.id AND rc.collected_by = ?
    WHERE rp.user_id = ?
    GROUP BY fr.id, rp.role
    ORDER BY fr.started_at DESC
    LIMIT 20
";
$stmt = $db->prepare($sql);
$stmt->execute([$view_user_id, $view_user_id]);
$farming_history = $stmt->fetchAll();

// Calculate stats
$total_contributions = count($contributions);
$total_runs = count($farming_history);
$total_quantity = array_sum(array_column($contribution_totals, 'total_contributed'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($view_user['username']); ?>'s Contributions - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <style>
        .profile-header {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .profile-info {
            display: flex;
            align-items: center;
            gap: 2rem;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
        }
        
        .profile-details h2 {
            margin: 0 0 0.5rem 0;
        }
        
        .profile-meta {
            color: var(--text-secondary);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .stat-card {
            background-color: var(--bg-tertiary);
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            text-align: center;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }
        
        .content-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-weight: 500;
            cursor: pointer;
            position: relative;
            transition: color 0.2s;
        }
        
        .tab:hover {
            color: var(--text-primary);
        }
        
        .tab.active {
            color: var(--primary-color);
        }
        
        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background-color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .contributions-section {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .resource-summary {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .resource-card {
            background-color: var(--bg-tertiary);
            padding: 1rem;
            border-radius: var(--radius-sm);
        }
        
        .resource-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .resource-amount {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .admin-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background-color: var(--danger-color);
            color: var(--bg-dark);
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 1rem;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <h1 class="nav-title"><?php echo htmlspecialchars(APP_NAME); ?></h1>
            <div class="nav-user">
                <img src="<?php echo htmlspecialchars(getAvatarUrl($current_user)); ?>" alt="Avatar" class="user-avatar">
                <span class="user-name"><?php echo htmlspecialchars($current_user['username']); ?></span>
                <a href="/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h2>Contribution History</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Back to Dashboard</a>
                <?php if ($can_edit): ?>
                    <a href="/admin/contributions.php?user=<?php echo urlencode($view_user['username']); ?>" 
                       class="btn btn-danger">Admin Edit</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="profile-header">
            <div class="profile-info">
                <img src="<?php echo htmlspecialchars(getAvatarUrl($view_user)); ?>" 
                     alt="Avatar" 
                     class="profile-avatar">
                <div class="profile-details">
                    <h2>
                        <?php echo htmlspecialchars($view_user['username']); ?>
                        <?php if ($view_user['role'] === 'admin'): ?>
                            <span class="admin-badge">ADMIN</span>
                        <?php endif; ?>
                    </h2>
                    <div class="profile-meta">
                        <?php if ($view_user['discord_id']): ?>
                            Discord ID: <?php echo htmlspecialchars($view_user['discord_id']); ?><br>
                        <?php endif; ?>
                        Member since: <?php echo date('M d, Y', strtotime($view_user['created_at'])); ?>
                    </div>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($total_contributions); ?></div>
                    <div class="stat-label">Contributions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($total_quantity); ?></div>
                    <div class="stat-label">Total Resources</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($total_runs); ?></div>
                    <div class="stat-label">Farming Runs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($contribution_totals); ?></div>
                    <div class="stat-label">Resource Types</div>
                </div>
            </div>
        </div>

        <div class="content-tabs">
            <button class="tab active" onclick="switchTab('summary')">Summary</button>
            <button class="tab" onclick="switchTab('history')">Contribution History</button>
            <button class="tab" onclick="switchTab('runs')">Farming Runs</button>
        </div>

        <!-- Summary Tab -->
        <div id="summary-tab" class="tab-content active">
            <div class="contributions-section">
                <h3>Resource Totals</h3>
                <div class="resource-summary">
                    <?php foreach ($contribution_totals as $total): ?>
                        <div class="resource-card">
                            <div class="resource-name"><?php echo htmlspecialchars($total['name']); ?></div>
                            <div class="resource-amount"><?php echo number_format($total['total_contributed']); ?></div>
                            <div style="font-size: 0.875rem; color: var(--text-secondary);">
                                <?php echo htmlspecialchars($total['category']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- History Tab -->
        <div id="history-tab" class="tab-content">
            <div class="contributions-section">
                <h3>Recent Contributions</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Resource</th>
                                <th>Quantity</th>
                                <th>Notes</th>
                                <?php if ($can_edit): ?>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contributions as $contribution): ?>
                                <tr>
                                    <td><?php echo date('M d, Y H:i', strtotime($contribution['date_collected'])); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($contribution['resource_name']); ?>
                                        <small style="color: var(--text-secondary);">
                                            (<?php echo htmlspecialchars($contribution['resource_category']); ?>)
                                        </small>
                                    </td>
                                    <td class="quantity"><?php echo number_format($contribution['quantity']); ?></td>
                                    <td class="notes"><?php echo htmlspecialchars($contribution['notes'] ?? '-'); ?></td>
                                    <?php if ($can_edit): ?>
                                        <td>
                                            <a href="/admin/contributions.php#edit-<?php echo $contribution['id']; ?>" 
                                               class="btn btn-secondary btn-sm">Edit</a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Farming Runs Tab -->
        <div id="runs-tab" class="tab-content">
            <div class="contributions-section">
                <h3>Farming Run History</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Run Name</th>
                                <th>Type</th>
                                <th>Role</th>
                                <th>Date</th>
                                <th>Collections</th>
                                <th>Total Collected</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($farming_history as $run): ?>
                                <tr>
                                    <td>
                                        <a href="/farming-run.php?id=<?php echo $run['id']; ?>">
                                            <?php echo htmlspecialchars($run['name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($run['run_type']); ?></td>
                                    <td>
                                        <?php if ($run['role'] === 'leader'): ?>
                                            <span class="role-badge">Leader</span>
                                        <?php else: ?>
                                            Member
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($run['started_at'])); ?></td>
                                    <td><?php echo $run['collections_count']; ?></td>
                                    <td class="quantity"><?php echo number_format($run['total_collected'] ?? 0); ?></td>
                                    <td>
                                        <span class="run-status <?php echo $run['status']; ?>">
                                            <?php echo ucfirst($run['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Update tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Update content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabName + '-tab').classList.add('active');
        }
    </script>
</body>
</html>