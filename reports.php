<?php
/**
 * Advanced Reports & Analytics
 */

require_once 'includes/auth.php';
require_once 'includes/db.php';

// Require login
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Get date range from query params
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$resource_filter = $_GET['resource'] ?? 'all';

// Get list of resources for filter
$resources = $db->query("SELECT id, name, category FROM resources ORDER BY category, name")->fetchAll();

// Get resource trends data
$resource_trends_query = "
    SELECT 
        DATE(c.date_collected) as date,
        r.id as resource_id,
        r.name as resource_name,
        r.category,
        SUM(c.quantity) as daily_total
    FROM contributions c
    JOIN resources r ON c.resource_id = r.id
    WHERE DATE(c.date_collected) BETWEEN ? AND ?
";

$params = [$start_date, $end_date];

if ($resource_filter !== 'all') {
    $resource_trends_query .= " AND r.id = ?";
    $params[] = $resource_filter;
}

$resource_trends_query .= " GROUP BY DATE(c.date_collected), r.id
    ORDER BY date, r.name";

$stmt = $db->prepare($resource_trends_query);
$stmt->execute($params);
$resource_trends = $stmt->fetchAll();

// Process data for Chart.js
$dates = [];
$datasets = [];
$resource_data = [];

foreach ($resource_trends as $trend) {
    $date = $trend['date'];
    if (!in_array($date, $dates)) {
        $dates[] = $date;
    }
    
    if (!isset($resource_data[$trend['resource_id']])) {
        $resource_data[$trend['resource_id']] = [
            'name' => $trend['resource_name'],
            'data' => []
        ];
    }
    
    $resource_data[$trend['resource_id']]['data'][$date] = $trend['daily_total'];
}

// Fill in missing dates with 0
foreach ($resource_data as $resource_id => $data) {
    $filled_data = [];
    foreach ($dates as $date) {
        $filled_data[] = $data['data'][$date] ?? 0;
    }
    
    $datasets[] = [
        'label' => $data['name'],
        'data' => $filled_data,
        'borderWidth' => 2,
        'fill' => false
    ];
}

// Get top contributors data
$top_contributors_query = "
    SELECT 
        u.id,
        COALESCE(u.in_game_name, u.username) as username,
        u.avatar,
        COUNT(DISTINCT c.id) as contribution_count,
        COUNT(DISTINCT DATE(c.date_collected)) as active_days,
        SUM(c.quantity) as total_contributed,
        AVG(c.quantity) as avg_contribution
    FROM contributions c
    JOIN users u ON c.user_id = u.id
    WHERE DATE(c.date_collected) BETWEEN ? AND ?
    GROUP BY u.id
    ORDER BY total_contributed DESC
    LIMIT 10
";

$stmt = $db->prepare($top_contributors_query);
$stmt->execute([$start_date, $end_date]);
$top_contributors = $stmt->fetchAll();

// Get farming run efficiency data
$farming_efficiency_query = "
    SELECT 
        fr.id,
        fr.name,
        fr.run_type,
        fr.started_at,
        fr.ended_at,
        TIMESTAMPDIFF(MINUTE, fr.started_at, fr.ended_at) as duration_minutes,
        COUNT(DISTINCT rp.user_id) as participant_count,
        COUNT(DISTINCT rc.id) as collection_count,
        COALESCE(SUM(rc.quantity), 0) as total_collected,
        COUNT(DISTINCT rc.resource_id) as resource_variety
    FROM farming_runs fr
    LEFT JOIN run_participants rp ON fr.id = rp.run_id
    LEFT JOIN run_collections rc ON fr.id = rc.run_id
    WHERE fr.status = 'completed'
    AND DATE(fr.started_at) BETWEEN ? AND ?
    GROUP BY fr.id
    HAVING duration_minutes > 0
    ORDER BY fr.started_at DESC
";

$stmt = $db->prepare($farming_efficiency_query);
$stmt->execute([$start_date, $end_date]);
$farming_runs = $stmt->fetchAll();

// Calculate efficiency metrics
foreach ($farming_runs as &$run) {
    $run['resources_per_minute'] = $run['duration_minutes'] > 0 ? 
        round($run['total_collected'] / $run['duration_minutes'], 2) : 0;
    $run['resources_per_participant'] = $run['participant_count'] > 0 ? 
        round($run['total_collected'] / $run['participant_count'], 2) : 0;
    $run['efficiency_score'] = $run['participant_count'] > 0 && $run['duration_minutes'] > 0 ? 
        round(($run['total_collected'] / ($run['participant_count'] * $run['duration_minutes'])) * 100, 2) : 0;
}

// Get resource distribution data
$resource_distribution_query = "
    SELECT 
        r.category,
        SUM(c.quantity) as category_total
    FROM contributions c
    JOIN resources r ON c.resource_id = r.id
    WHERE DATE(c.date_collected) BETWEEN ? AND ?
    GROUP BY r.category
    ORDER BY category_total DESC
";

$stmt = $db->prepare($resource_distribution_query);
$stmt->execute([$start_date, $end_date]);
$resource_distribution = $stmt->fetchAll();

// Get daily activity data
$daily_activity_query = "
    SELECT 
        DATE(date_collected) as date,
        COUNT(DISTINCT user_id) as active_users,
        COUNT(*) as contribution_count,
        SUM(quantity) as total_quantity
    FROM contributions
    WHERE DATE(date_collected) BETWEEN ? AND ?
    GROUP BY DATE(date_collected)
    ORDER BY date
";

$stmt = $db->prepare($daily_activity_query);
$stmt->execute([$start_date, $end_date]);
$daily_activity = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <style>
        .reports-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .filter-bar {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .filter-form {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }
        
        .chart-section {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin-top: 1rem;
        }
        
        .chart-container.small {
            height: 300px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            text-align: center;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .contributors-table {
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            overflow: hidden;
            margin-top: 1rem;
        }
        
        .contributors-table table {
            width: 100%;
        }
        
        .contributors-table th {
            background-color: var(--bg-secondary);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .contributors-table td {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
        }
        
        .efficiency-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .efficiency-badge.high {
            background-color: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }
        
        .efficiency-badge.medium {
            background-color: rgba(255, 193, 7, 0.2);
            color: var(--warning-color);
        }
        
        .efficiency-badge.low {
            background-color: rgba(220, 53, 69, 0.2);
            color: var(--danger-color);
        }
        
        .export-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .chart-legend {
            margin-top: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.875rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .legend-color {
            width: 20px;
            height: 3px;
            border-radius: 2px;
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
            <h2>Reports & Analytics</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Back to Dashboard</a>
                <?php if (isAdmin()): ?>
                    <a href="/admin/" class="btn btn-danger">Admin Panel</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="reports-container">
            <!-- Filter Bar -->
            <div class="filter-bar">
                <h3>Report Filters</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" 
                               name="start_date" 
                               id="start_date" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" 
                               name="end_date" 
                               id="end_date" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="resource">Resource Filter</label>
                        <select name="resource" id="resource" class="form-control">
                            <option value="all">All Resources</option>
                            <?php 
                            $current_category = '';
                            foreach ($resources as $resource): 
                                if ($resource['category'] !== $current_category):
                                    if ($current_category !== '') echo '</optgroup>';
                                    $current_category = $resource['category'];
                                    echo '<optgroup label="' . htmlspecialchars($current_category) . '">';
                                endif;
                            ?>
                                <option value="<?php echo $resource['id']; ?>" 
                                        <?php echo $resource_filter == $resource['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($resource['name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($current_category !== '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Update Report</button>
                    <a href="reports.php" class="btn btn-secondary">Reset</a>
                </form>
            </div>

            <!-- Summary Statistics -->
            <div class="stats-grid">
                <?php
                // Calculate summary stats
                $total_contributions = $db->prepare("
                    SELECT COUNT(*) FROM contributions 
                    WHERE DATE(date_collected) BETWEEN ? AND ?
                ");
                $total_contributions->execute([$start_date, $end_date]);
                $total_count = $total_contributions->fetchColumn();

                $total_quantity = $db->prepare("
                    SELECT COALESCE(SUM(quantity), 0) FROM contributions 
                    WHERE DATE(date_collected) BETWEEN ? AND ?
                ");
                $total_quantity->execute([$start_date, $end_date]);
                $total_qty = $total_quantity->fetchColumn();

                $active_contributors = $db->prepare("
                    SELECT COUNT(DISTINCT user_id) FROM contributions 
                    WHERE DATE(date_collected) BETWEEN ? AND ?
                ");
                $active_contributors->execute([$start_date, $end_date]);
                $active_count = $active_contributors->fetchColumn();

                $completed_runs = $db->prepare("
                    SELECT COUNT(*) FROM farming_runs 
                    WHERE status = 'completed' 
                    AND DATE(started_at) BETWEEN ? AND ?
                ");
                $completed_runs->execute([$start_date, $end_date]);
                $runs_count = $completed_runs->fetchColumn();
                ?>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($total_count); ?></div>
                    <div class="stat-label">Total Contributions</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($total_qty); ?></div>
                    <div class="stat-label">Resources Collected</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($active_count); ?></div>
                    <div class="stat-label">Active Contributors</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($runs_count); ?></div>
                    <div class="stat-label">Farming Runs</div>
                </div>
            </div>

            <!-- Resource Trends Chart -->
            <div class="chart-section">
                <h3>Resource Collection Trends</h3>
                <div class="chart-container">
                    <canvas id="resourceTrendsChart"></canvas>
                </div>
                <div class="export-buttons">
                    <button onclick="exportChart('resourceTrendsChart', 'resource-trends')" class="btn btn-secondary btn-sm">
                        Export as Image
                    </button>
                </div>
            </div>

            <!-- Daily Activity Chart -->
            <div class="chart-section">
                <h3>Daily Activity</h3>
                <div class="chart-container small">
                    <canvas id="dailyActivityChart"></canvas>
                </div>
            </div>

            <!-- Resource Distribution -->
            <div class="chart-section">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                    <div>
                        <h3>Resource Distribution by Category</h3>
                        <div class="chart-container small">
                            <canvas id="resourceDistributionChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Top Contributors -->
                    <div>
                        <h3>Top Contributors</h3>
                        <div class="contributors-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Contributor</th>
                                        <th>Total</th>
                                        <th>Avg/Day</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_contributors as $index => $contributor): ?>
                                        <tr>
                                            <td>
                                                <?php 
                                                $medals = ['🥇', '🥈', '🥉'];
                                                echo $index < 3 ? $medals[$index] : ($index + 1);
                                                ?>
                                            </td>
                                            <td>
                                                <div class="user-info">
                                                    <img src="<?php echo htmlspecialchars(getAvatarUrl($contributor)); ?>" 
                                                         alt="Avatar" 
                                                         class="user-avatar">
                                                    <span><?php echo htmlspecialchars($contributor['username']); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo number_format($contributor['total_contributed']); ?></td>
                                            <td>
                                                <?php 
                                                $days = $contributor['active_days'] ?: 1;
                                                echo number_format($contributor['total_contributed'] / $days, 1);
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Farming Run Efficiency -->
            <div class="chart-section">
                <h3>Farming Run Efficiency Analysis</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Run Name</th>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Participants</th>
                                <th>Total Collected</th>
                                <th>Per Minute</th>
                                <th>Per Person</th>
                                <th>Efficiency</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($farming_runs, 0, 10) as $run): ?>
                                <tr>
                                    <td>
                                        <a href="/farming-run.php?id=<?php echo $run['id']; ?>">
                                            <?php echo htmlspecialchars($run['name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($run['run_type']); ?></td>
                                    <td><?php echo $run['duration_minutes']; ?> min</td>
                                    <td><?php echo $run['participant_count']; ?></td>
                                    <td><?php echo number_format($run['total_collected']); ?></td>
                                    <td><?php echo number_format($run['resources_per_minute'], 1); ?></td>
                                    <td><?php echo number_format($run['resources_per_participant'], 1); ?></td>
                                    <td>
                                        <?php
                                        $efficiency_class = $run['efficiency_score'] > 50 ? 'high' : 
                                                          ($run['efficiency_score'] > 20 ? 'medium' : 'low');
                                        ?>
                                        <span class="efficiency-badge <?php echo $efficiency_class; ?>">
                                            <?php echo $run['efficiency_score']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Export Section -->
            <div class="chart-section">
                <h3>Export Data</h3>
                <p>Download the report data for the selected date range.</p>
                <div class="export-buttons">
                    <form method="POST" action="export-report.php" style="display: inline;">
                        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                        <input type="hidden" name="format" value="csv">
                        <button type="submit" class="btn btn-primary">Export as CSV</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Chart.js default settings
        Chart.defaults.color = '#b0b0b0';
        Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.1)';
        
        // Resource Trends Chart
        const resourceTrendsCtx = document.getElementById('resourceTrendsChart').getContext('2d');
        const resourceTrendsChart = new Chart(resourceTrendsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: <?php echo json_encode($datasets); ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: '#333',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        }
                    }
                }
            }
        });

        // Daily Activity Chart
        const dailyActivityCtx = document.getElementById('dailyActivityChart').getContext('2d');
        const dailyActivityChart = new Chart(dailyActivityCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($daily_activity, 'date')); ?>,
                datasets: [{
                    label: 'Active Users',
                    data: <?php echo json_encode(array_column($daily_activity, 'active_users')); ?>,
                    backgroundColor: 'rgba(75, 139, 245, 0.5)',
                    yAxisID: 'y',
                }, {
                    label: 'Total Quantity',
                    data: <?php echo json_encode(array_column($daily_activity, 'total_quantity')); ?>,
                    type: 'line',
                    borderColor: 'rgba(255, 193, 7, 1)',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    yAxisID: 'y1',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    },
                }
            }
        });

        // Resource Distribution Chart
        const resourceDistCtx = document.getElementById('resourceDistributionChart').getContext('2d');
        const resourceDistChart = new Chart(resourceDistCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($resource_distribution, 'category')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($resource_distribution, 'category_total')); ?>,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.5)',
                        'rgba(54, 162, 235, 0.5)',
                        'rgba(255, 205, 86, 0.5)',
                        'rgba(75, 192, 192, 0.5)',
                        'rgba(153, 102, 255, 0.5)',
                        'rgba(255, 159, 64, 0.5)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });

        // Export chart as image
        function exportChart(chartId, filename) {
            const canvas = document.getElementById(chartId);
            const url = canvas.toDataURL('image/png');
            const link = document.createElement('a');
            link.download = filename + '.png';
            link.href = url;
            link.click();
        }
    </script>
</body>
</html>