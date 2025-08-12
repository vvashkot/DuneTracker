<?php
/**
 * Dashboard - Enhanced with goals, leaderboards, and trends
 */

require_once 'includes/auth.php';
require_once 'includes/db.php';

// Landing for guests; dashboard for logged-in users
$isLoggedIn = isLoggedIn();
if (!$isLoggedIn) {
    // Public landing page with login CTA
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars(GUILD_NAME ?? APP_NAME); ?> - Welcome</title>
        <link rel="stylesheet" href="/css/style-v2.css">
        <style>
            .hero { padding: 4rem 0; text-align: center; }
            .hero h1 { font-size: 2.25rem; margin-bottom: 0.5rem; }
            .hero p { color: var(--text-secondary); max-width: 720px; margin: 0.5rem auto 1.5rem; }
            .features { display: grid; gap: 1rem; max-width: 900px; margin: 2rem auto; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
            .feature { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 1rem; }
            .footer { text-align: center; color: var(--text-secondary); margin-top: 2rem; font-size: 0.875rem; }
        </style>
    </head>
    <body>
        <nav class="navbar">
            <div class="nav-container">
                <h1 class="nav-title"><?php echo htmlspecialchars(GUILD_NAME ?? APP_NAME); ?></h1>
                <div class="nav-user">
                    <a href="/login.php" class="btn btn-primary">Login with Discord</a>
                </div>
            </div>
        </nav>
        <div class="container">
            <section class="hero">
                <h1>Welcome to <?php echo htmlspecialchars(GUILD_NAME ?? 'our guild'); ?></h1>
                <p>
                    A community hub for Dune: Awakening. Track farming runs, contributions, and refined outputs like Melange and Plastanium.
                    Join with your Discord to participate and see guild dashboards.
                </p>
                <a href="/login.php" class="btn btn-primary" style="font-size:1.1rem; padding:0.75rem 1.25rem;">Login with Discord</a>
            </section>
            <section class="features">
                <div class="feature">
                    <h3>🚜 Farming Runs</h3>
                    <p>Organize runs, log collections, and compute fair distributions.</p>
                </div>
                <div class="feature">
                    <h3>📦 Contributions</h3>
                    <p>Submit resources individually or as a group and track history.</p>
                </div>
                <div class="feature">
                    <h3>⚗️ Refining</h3>
                    <p>Preview Spice → Melange and two-input recipes; distribute results.</p>
                </div>
            </section>
            <div class="footer">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(GUILD_NAME ?? APP_NAME); ?></div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$user = getCurrentUser();

// Get resource totals with availability
$db = getDB();
$resource_totals = $db->query("
    SELECT 
        r.id,
        r.name,
        r.category,
        r.current_stock,
        r.total_withdrawn,
        (r.current_stock - r.total_withdrawn) as available_stock,
        CASE 
            WHEN (r.current_stock - r.total_withdrawn) <= 0 THEN 'depleted'
            WHEN (r.current_stock - r.total_withdrawn) < r.current_stock * 0.2 THEN 'low'
            ELSE 'adequate'
        END as stock_status
    FROM resources r
    WHERE r.current_stock > 0
    ORDER BY r.category, r.name
")->fetchAll();

$recent_contributions = getRecentContributions(10);
$active_goals = getActiveResourceGoals();
$top_contributors_week = getTopContributors('week', 5);
$upcoming_runs = getUpcomingFarmingRuns(3);

// Get guild treasury info
$db = getDB();
$treasury_totals = $db->query("
    SELECT 
        COUNT(DISTINCT resource_id) as resource_types,
        COALESCE(SUM(quantity), 0) as total_resources
    FROM guild_treasury
    WHERE quantity > 0
")->fetch();

// Get tax settings
$tax_enabled = false;
$tax_rate = 0;
$stmt = $db->query("SELECT setting_key, setting_value FROM guild_settings WHERE setting_key IN ('guild_tax_enabled', 'guild_tax_rate')");
while ($row = $stmt->fetch()) {
    if ($row['setting_key'] === 'guild_tax_enabled') {
        $tax_enabled = $row['setting_value'] === 'true';
    } else if ($row['setting_key'] === 'guild_tax_rate') {
        $tax_rate = floatval($row['setting_value']) * 100;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <style>
        .dashboard-container {
            display: grid;
            grid-template-columns: 300px 1fr 350px;
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .left-sidebar, .right-sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .main-area {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .goals-card {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .goal-item {
            margin-bottom: 1rem;
        }
        
        .goal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .goal-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .goal-deadline {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        
        .progress-bar {
            background-color: var(--bg-tertiary);
            border-radius: 4px;
            height: 8px;
            overflow: hidden;
            margin-bottom: 0.25rem;
        }
        
        .progress-fill {
            background-color: var(--primary-color);
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .progress-text {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-align: right;
        }
        
        .leaderboard-card {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .leaderboard-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
        }
        
        .leaderboard-rank {
            width: 24px;
            font-weight: 700;
            color: var(--text-secondary);
        }
        
        .leaderboard-rank.gold { color: #FFD700; }
        .leaderboard-rank.silver { color: #C0C0C0; }
        .leaderboard-rank.bronze { color: #CD7F32; }
        
        .leaderboard-user {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .leaderboard-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
        }
        
        .leaderboard-value {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .upcoming-runs {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .run-item {
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
        }
        
        .run-name {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .run-details {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .section-title {
            font-size: 1.125rem;
            font-weight: 600;
        }
        
        @media (max-width: 1200px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            
            .left-sidebar, .right-sidebar {
                order: 2;
            }
            
            .main-area {
                order: 1;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <h1 class="nav-title"><?php echo htmlspecialchars(APP_NAME); ?></h1>
            <div class="nav-user">
                <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" alt="Avatar" class="user-avatar">
                <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
                <a href="/settings.php" class="btn btn-secondary">Settings</a>
                <a href="/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h2>Guild Resource Dashboard</h2>
            <div class="header-actions">
                <a href="/farming-runs.php" class="btn btn-primary">Farming Runs</a>
                <a href="/submit.php" class="btn btn-secondary">Quick Submit</a>
                <a href="/withdraw.php" class="btn btn-secondary">Withdraw</a>
                <a href="/reports.php" class="btn btn-secondary">Reports</a>
                <a href="/my-contributions.php" class="btn btn-secondary">My Contributions</a>
                <?php if (isAdmin()): ?>
                    <a href="/admin/guild-settings.php" class="btn btn-secondary">Guild Settings</a>
                    <a href="/admin/" class="btn btn-danger">Admin Panel</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="dashboard-container">
            <!-- Left Sidebar -->
            <div class="left-sidebar">
                <!-- Resource Goals -->
                <div class="goals-card">
                    <div class="section-header">
                        <h3 class="section-title">📎 Resource Goals</h3>
                        <?php if (isAdmin()): ?>
                            <a href="/admin/goals.php" class="btn btn-secondary btn-sm">Manage</a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($active_goals)): ?>
                        <p style="color: var(--text-secondary); font-size: 0.875rem;">No active goals</p>
                    <?php else: ?>
                        <?php foreach ($active_goals as $goal): 
                            $progress = min(100, ($goal['current_amount'] / $goal['target_amount']) * 100);
                        ?>
                            <div class="goal-item">
                                <div class="goal-header">
                                    <span class="goal-name"><?php echo htmlspecialchars($goal['resource_name']); ?></span>
                                    <?php if ($goal['deadline']): ?>
                                        <span class="goal-deadline">Due <?php echo date('M d', strtotime($goal['deadline'])); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                                <div class="progress-text">
                                    <?php echo number_format($goal['current_amount']); ?> / <?php echo number_format($goal['target_amount']); ?>
                                    (<?php echo round($progress); ?>%)
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Upcoming Runs -->
                <div class="upcoming-runs">
                    <div class="section-header">
                        <h3 class="section-title">🚜 Active Runs</h3>
                        <a href="/farming-runs.php" class="btn btn-secondary btn-sm">View All</a>
                    </div>
                    
                    <?php if (empty($upcoming_runs)): ?>
                        <p style="color: var(--text-secondary); font-size: 0.875rem;">No active runs</p>
                    <?php else: ?>
                        <?php foreach ($upcoming_runs as $run): ?>
                            <div class="run-item">
                                <div class="run-name"><?php echo htmlspecialchars($run['name']); ?></div>
                                <div class="run-details">
                                    <?php echo $run['participant_count']; ?> participants • 
                                    Started <?php echo date('g:i A', strtotime($run['started_at'])); ?>
                                </div>
                                <a href="/farming-run.php?id=<?php echo $run['id']; ?>" 
                                   class="btn btn-primary btn-sm" style="margin-top: 0.5rem;">Join Run</a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Main Area -->
            <div class="main-area">
                <!-- Resource Inventory -->
                <section class="card">
                    <h3>Current Guild Inventory</h3>
                    <div class="stats-grid" style="margin-top: 1rem;">
                        <?php 
                        $categories = [];
                        foreach ($resource_totals as $resource) {
                            if (!isset($categories[$resource['category']])) {
                                $categories[$resource['category']] = [];
                            }
                            $categories[$resource['category']][] = $resource;
                        }
                        
                        foreach ($categories as $category => $resources): ?>
                            <div style="margin-bottom: 1.5rem;">
                                <h4 style="color: var(--text-secondary); font-size: 0.875rem; margin-bottom: 0.75rem;">
                                    <?php echo htmlspecialchars($category ?? 'Uncategorized'); ?>
                                </h4>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.75rem;">
                                    <?php foreach ($resources as $resource): ?>
                                        <div class="stat-card" style="padding: 1rem; position: relative;">
                                            <div class="stock-indicator <?php echo $resource['stock_status']; ?>" 
                                                 style="position: absolute; top: 0.5rem; right: 0.5rem; width: 8px; height: 8px; border-radius: 50%; background-color: <?php 
                                                     echo $resource['stock_status'] === 'adequate' ? 'var(--success-color)' : 
                                                         ($resource['stock_status'] === 'low' ? 'var(--warning-color)' : 'var(--danger-color)'); 
                                                 ?>;"
                                                 title="Stock status: <?php echo $resource['stock_status']; ?>">
                                            </div>
                                            <div style="font-weight: 600; margin-bottom: 0.5rem;">
                                                <?php echo htmlspecialchars($resource['name']); ?>
                                            </div>
                                            <div style="font-size: 1.25rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.25rem;">
                                                <?php echo number_format($resource['available_stock']); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                                <?php if ($resource['total_withdrawn'] > 0): ?>
                                                    Total: <?php echo number_format($resource['current_stock']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- Recent Contributions -->
                <section class="card">
                    <div class="section-header">
                        <h3>Recent Contributions</h3>
                        <a href="/contributions.php" class="btn btn-secondary btn-sm">View All</a>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Contributor</th>
                                    <th>Resource</th>
                                    <th>Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_contributions as $contribution): ?>
                                    <tr>
                                        <td><?php echo date('M d, H:i', strtotime($contribution['date_collected'])); ?></td>
                                        <td><?php echo htmlspecialchars($contribution['username']); ?></td>
                                        <td>
                                            <span class="resource-tag resource-<?php echo strtolower(str_replace(' ', '-', $contribution['resource_category'] ?? '')); ?>">
                                                <?php echo htmlspecialchars($contribution['resource_name']); ?>
                                            </span>
                                        </td>
                                        <td class="quantity">+<?php echo number_format($contribution['quantity']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recent_contributions)): ?>
                                    <tr>
                                        <td colspan="4" class="empty-state">No contributions yet. Be the first to contribute!</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- Right Sidebar -->
            <div class="right-sidebar">
                <!-- Top Contributors -->
                <div class="leaderboard-card">
                    <div class="section-header">
                        <h3 class="section-title">🏆 Top Contributors</h3>
                        <span style="font-size: 0.75rem; color: var(--text-secondary);">This Week</span>
                    </div>
                    
                    <?php if (empty($top_contributors_week)): ?>
                        <p style="color: var(--text-secondary); font-size: 0.875rem;">No contributions this week</p>
                    <?php else: ?>
                        <?php foreach ($top_contributors_week as $index => $contributor): ?>
                            <div class="leaderboard-item">
                                <span class="leaderboard-rank <?php 
                                    echo $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : '')); 
                                ?>">
                                    <?php echo $index + 1; ?>
                                </span>
                                <div class="leaderboard-user">
                                    <img src="<?php echo htmlspecialchars(getAvatarUrl($contributor)); ?>" 
                                         alt="Avatar" 
                                         class="leaderboard-avatar">
                                    <span><?php echo htmlspecialchars($contributor['username']); ?></span>
                                </div>
                                <span class="leaderboard-value"><?php echo number_format($contributor['total_contributed']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Stats -->
                <div class="card">
                    <h3>Guild Statistics</h3>
                    <div style="display: grid; gap: 1rem; margin-top: 1rem;">
                        <div class="stat-item" style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-tertiary); border-radius: var(--radius-sm);">
                            <span style="color: var(--text-secondary);">Total Members</span>
                            <span style="font-weight: 600; color: var(--primary-color);">
                                <?php 
                                $db = getDB();
                                echo $db->query("SELECT COUNT(*) FROM users WHERE merged_into_user_id IS NULL")->fetchColumn();
                                ?>
                            </span>
                        </div>
                        <div class="stat-item" style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-tertiary); border-radius: var(--radius-sm);">
                            <span style="color: var(--text-secondary);">Active Runs</span>
                            <span style="font-weight: 600; color: var(--success-color);">
                                <?php echo count($upcoming_runs); ?>
                            </span>
                        </div>
                        <div class="stat-item" style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-tertiary); border-radius: var(--radius-sm);">
                            <span style="color: var(--text-secondary);">Today's Contributions</span>
                            <span style="font-weight: 600; color: var(--warning-color);">
                                <?php 
                                $stmt = $db->query("SELECT COUNT(*) FROM contributions WHERE DATE(date_collected) = CURDATE()");
                                echo $stmt->fetchColumn();
                                ?>
                            </span>
                        </div>
                        <div class="stat-item" style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-tertiary); border-radius: var(--radius-sm);">
                            <span style="color: var(--text-secondary);">Guild Treasury</span>
                            <span style="font-weight: 600; color: var(--primary-color);">
                                <?php echo number_format($treasury_totals['total_resources']); ?>
                            </span>
                        </div>
                        <?php if ($tax_enabled): ?>
                        <div class="stat-item" style="display: flex; justify-content: space-between; padding: 0.75rem; background: var(--bg-tertiary); border-radius: var(--radius-sm);">
                            <span style="color: var(--text-secondary);">Guild Tax Rate</span>
                            <span style="font-weight: 600; color: var(--success-color);">
                                <?php echo number_format($tax_rate, 1); ?>%
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>