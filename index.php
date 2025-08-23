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
// Landsraad goals (active) for sidebar
try {
    $landsraad_goals = $db->query("SELECT id, item_name, points_per_unit, target_points, required_qty, icon_url FROM landsraad_item_goals WHERE active=1 ORDER BY created_at DESC LIMIT 6")->fetchAll();
    $lg_progress = [];
    if (!empty($landsraad_goals)) {
        $ids = array_map(fn($g)=> (int)$g['id'], $landsraad_goals);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT goal_id, COALESCE(SUM(qty),0) as qty FROM landsraad_goal_stock_logs WHERE goal_id IN ($place) GROUP BY goal_id");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $r) { $lg_progress[(int)$r['goal_id']] = (int)$r['qty']; }
    }
} catch (Throwable $e) {
    $landsraad_goals = [];
    $lg_progress = [];
}
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

// Weekly windows for leaderboards
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d 23:59:59', strtotime('sunday this week'));

// Combat weekly top (ground/air)
try {
    $stmt = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, COUNT(*) as cnt FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.type='ground_kill' AND ce.occurred_at BETWEEN ? AND ? GROUP BY ce.user_id ORDER BY cnt DESC LIMIT 5");
    $stmt->execute([$week_start, $week_end]);
    $top_ground_week = $stmt->fetchAll();
    
    $stmt = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, COUNT(*) as cnt FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.type='air_kill' AND ce.occurred_at BETWEEN ? AND ? GROUP BY ce.user_id ORDER BY cnt DESC LIMIT 5");
    $stmt->execute([$week_start, $week_end]);
    $top_air_week = $stmt->fetchAll();
} catch (Throwable $e) {
    $top_ground_week = $top_air_week = [];
}

// Landsraad weekly top
try {
    $stmt = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, SUM(lp.points) as pts FROM landsraad_points lp JOIN users u ON lp.user_id=u.id WHERE lp.occurred_at BETWEEN ? AND ? GROUP BY lp.user_id ORDER BY pts DESC LIMIT 5");
    $stmt->execute([$week_start, $week_end]);
    $landsraad_week = $stmt->fetchAll();
} catch (Throwable $e) {
    $landsraad_week = [];
}

// Dazen kills weekly top (target contains 'DAZEN')
try {
    $stmt = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, COUNT(*) as cnt FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.target LIKE '%DAZEN%' AND ce.occurred_at BETWEEN ? AND ? GROUP BY ce.user_id ORDER BY cnt DESC LIMIT 5");
    $stmt->execute([$week_start, $week_end]);
    $dazen_week = $stmt->fetchAll();
} catch (Throwable $e) {
    $dazen_week = [];
}

// Build weekly series for charts (last 8 ISO weeks)
function buildWeekKeys($weeksBack = 8) {
    $keys = [];
    for ($i = $weeksBack - 1; $i >= 0; $i--) {
        // ISO year-week as integer (e.g., 202536)
        $keys[] = (int)date('oW', strtotime("monday -$i week"));
    }
    return $keys;
}

function renderSparkline(array $series, int $width = 240, int $height = 48, string $color = '#4b8bf5') {
    $n = count($series);
    if ($n === 0) { $series = [0]; $n = 1; }
    $max = max(1, max($series));
    $stepX = $n > 1 ? ($width - 4) / ($n - 1) : 0; // padding 2px
    $points = [];
    for ($i = 0; $i < $n; $i++) {
        $x = 2 + $i * $stepX;
        $y = 2 + ($height - 4) * (1 - ($series[$i] / $max)); // invert, pad 2px
        $points[] = round($x, 1) . ',' . round($y, 1);
    }
    $poly = implode(' ', $points);
    $area = '2,' . ($height-2) . ' ' . $poly . ' ' . ($width-2) . ',' . ($height-2);
    return '<svg width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="sparkline">'
         . '<polyline points="' . htmlspecialchars($area) . '" fill="' . htmlspecialchars($color) . '20" stroke="none"></polyline>'
         . '<polyline points="' . htmlspecialchars($poly) . '" fill="none" stroke="' . htmlspecialchars($color) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>'
         . '</svg>';
}

// Prepare combat and landsraad weekly series
$weekKeys = buildWeekKeys(8);
// Combat: fetch counts grouped by YEARWEEK for both types
try {
    $groundMap = [];
    $airMap = [];
    $rows = $db->query("SELECT YEARWEEK(occurred_at,1) as yw, type, COUNT(*) as cnt FROM combat_events WHERE occurred_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK) GROUP BY YEARWEEK(occurred_at,1), type")->fetchAll();
    foreach ($rows as $r) {
        $key = (int)$r['yw'];
        if ($r['type'] === 'ground_kill') $groundMap[$key] = (int)$r['cnt'];
        if ($r['type'] === 'air_kill') $airMap[$key] = (int)$r['cnt'];
    }
    $groundSeries = array_map(fn($k) => $groundMap[$k] ?? 0, $weekKeys);
    $airSeries = array_map(fn($k) => $airMap[$k] ?? 0, $weekKeys);
} catch (Throwable $e) {
    $groundSeries = $airSeries = array_fill(0, count($weekKeys), 0);
}

// Landsraad: weekly sum of points
try {
    $lrMap = [];
    $rows = $db->query("SELECT YEARWEEK(occurred_at,1) as yw, SUM(points) as pts FROM landsraad_points WHERE occurred_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK) GROUP BY YEARWEEK(occurred_at,1)")->fetchAll();
    foreach ($rows as $r) { $lrMap[(int)$r['yw']] = (int)$r['pts']; }
    $landsraadSeries = array_map(fn($k) => $lrMap[$k] ?? 0, $weekKeys);
} catch (Throwable $e) {
    $landsraadSeries = array_fill(0, count($weekKeys), 0);
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
                <div class="feedback-links">
                  <a href="/feedback.php?type=feature" class="btn btn-secondary btn-sm">Submit Feature</a>
                  <a href="/feedback.php?type=bug" class="btn btn-secondary btn-sm">Submit Bug</a>
                </div>
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
                <a href="/ai-submit.php" class="btn btn-primary">Submit with AI</a>
                <a href="/withdraw.php" class="btn btn-secondary">Withdraw</a>
                <a href="/reports.php" class="btn btn-secondary">Reports</a>
                <a href="/my-contributions.php" class="btn btn-secondary">My Contributions</a>
                <a href="/hub.php" class="btn btn-secondary">My Hub</a>
                <a href="/landsraad.php" class="btn btn-secondary">My Landsraad</a>
                <a href="/combat.php" class="btn btn-secondary">My Combat</a>
                <?php if (isAdmin()): ?>
                    <a href="/admin/guild-settings.php" class="btn btn-secondary">Guild Settings</a>
                    <a href="/admin/" class="btn btn-danger">Admin Panel</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="dashboard-container">
            <!-- Left Sidebar (Goals + Runs) -->
            <div class="left-sidebar">
                <!-- Landsraad Goals -->
                <div class="goals-card">
                    <div class="section-header">
                        <h3 class="section-title">🎯 Landsraad Goals</h3>
                        <?php if (isAdmin()): ?>
                            <a href="/admin/landsraad-goals.php" class="btn btn-secondary btn-sm">Manage</a>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($landsraad_goals)): ?>
                        <p style="color: var(--text-secondary); font-size: 0.875rem;">No active Landsraad goals</p>
                    <?php else: ?>
                        <?php foreach ($landsraad_goals as $g): 
                            $collected = (int)($lg_progress[$g['id']] ?? 0);
                            $pct = $g['required_qty'] > 0 ? min(100, ($collected / (int)$g['required_qty']) * 100) : 0;
                        ?>
                        <div class="goal-item">
                            <div class="goal-header">
                                <span class="goal-name"><?php if (!empty($g['icon_url'])): ?><img src="<?php echo htmlspecialchars($g['icon_url']); ?>" alt="" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;"><?php endif; ?><?php echo htmlspecialchars($g['item_name']); ?></span>
                                <span class="goal-deadline" style="color:var(--text-secondary); font-size:0.75rem;">PPU <?php echo (int)$g['points_per_unit']; ?> • Target <?php echo number_format($g['target_points']); ?></span>
                            </div>
                            <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $pct; ?>%"></div></div>
                            <div class="progress-text"><?php echo number_format($collected); ?> / <?php echo number_format((int)$g['required_qty']); ?> (<?php echo round($pct); ?>%)</div>
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

            <!-- Main Area (Inventory + Recent) -->
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

                <!-- Dazen Kills (Weekly) -->
                <div class="leaderboard-card">
                    <div class="section-header">
                        <h3 class="section-title">😈 Dazen Kills (This Week)</h3>
                        <a href="/combat.php" class="btn btn-secondary btn-sm">My Combat</a>
                    </div>
                    <?php if (empty($dazen_week)): ?>
                        <p class="empty-state" style="font-size:0.85rem;">No Dazen hits yet.</p>
                    <?php else: ?>
                        <?php foreach ($dazen_week as $i => $row): ?>
                            <div class="leaderboard-item">
                                <span class="leaderboard-rank <?php echo $i===0?'gold':($i===1?'silver':($i===2?'bronze':'')); ?>"><?php echo $i+1; ?></span>
                                <div class="leaderboard-user"><span><?php echo htmlspecialchars($row['username']); ?></span></div>
                                <span class="leaderboard-value"><?php echo (int)$row['cnt']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Landsraad Weekly (right sidebar) -->
                <div class="leaderboard-card">
                    <div class="section-header">
                        <h3 class="section-title">🏛️ Landsraad (This Week)</h3>
                        <a href="/landsraad.php" class="btn btn-secondary btn-sm">My Landsraad</a>
                        <a href="/admin/landsraad-goals.php" class="btn btn-secondary btn-sm">Landsraad Progress</a>
                    </div>
                    <div style="margin:0.5rem 0 1rem;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:0.5rem;">
                            <span style="color:var(--text-secondary); font-size:0.85rem;">Points (8 weeks)</span>
                            <div><?php echo renderSparkline($landsraadSeries, 220, 40, '#2196F3'); ?></div>
                        </div>
                        <div style="display:flex; gap:12px; align-items:center; margin-top:6px; font-size:0.75rem; color:var(--text-secondary);">
                            <span style="display:inline-flex; align-items:center; gap:6px;">
                                <span style="display:inline-block; width:10px; height:10px; background:#2196F3; border-radius:2px;"></span>
                                Points
                            </span>
                        </div>
                    </div>
                    <?php if (empty($landsraad_week)): ?>
                        <p class="empty-state" style="font-size:0.85rem;">No points logged</p>
                    <?php else: ?>
                        <?php foreach ($landsraad_week as $i => $row): ?>
                            <div class="leaderboard-item">
                                <span class="leaderboard-rank <?php echo $i===0?'gold':($i===1?'silver':($i===2?'bronze':'')); ?>"><?php echo $i+1; ?></span>
                                <div class="leaderboard-user"><span><?php echo htmlspecialchars($row['username']); ?></span></div>
                                <span class="leaderboard-value"><?php echo number_format($row['pts']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                
                
                <!-- Quick Stats hidden per request -->
                <!--
                <div class="card">
                  ... Guild Statistics hidden ...
                </div>
                -->
            </div>
        </div>
    </div>
</body>
</html>