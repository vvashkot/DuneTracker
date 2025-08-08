<?php
/**
 * My Contributions - Shows logged-in user's contribution history
 */

require_once 'includes/auth.php';
require_once 'includes/db.php';

// Require login
requireLogin();

$user = getCurrentUser();
$contributions = getUserContributions($user['db_id']);
$contribution_totals = getUserContributionTotals($user['db_id']);

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="my-contributions-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, ['Date', 'Resource', 'Category', 'Quantity', 'Notes']);
    
    // CSV data
    foreach ($contributions as $contribution) {
        fputcsv($output, [
            date('Y-m-d H:i:s', strtotime($contribution['date_collected'])),
            $contribution['resource_name'],
            $contribution['resource_category'],
            $contribution['quantity'],
            $contribution['notes'] ?? ''
        ]);
    }
    
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Contributions - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="/css/style-v2.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <h1 class="nav-title"><?php echo htmlspecialchars(APP_NAME); ?></h1>
            <div class="nav-user">
                <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" alt="Avatar" class="user-avatar">
                <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
                <a href="/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h2>My Contributions</h2>
            <div class="header-actions">
                <a href="/submit.php" class="btn btn-primary">Submit New</a>
                <a href="/index.php" class="btn btn-secondary">Dashboard</a>
                <?php if (!empty($contributions)): ?>
                    <a href="?export=csv" class="btn btn-secondary">Export CSV</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="contributions-grid">
            <?php if (!empty($contribution_totals)): ?>
                <section class="contribution-summary">
                    <h3>Your Total Contributions</h3>
                    <div class="summary-cards">
                        <?php 
                        $categories = [];
                        foreach ($contribution_totals as $total) {
                            if (!isset($categories[$total['category']])) {
                                $categories[$total['category']] = [];
                            }
                            $categories[$total['category']][] = $total;
                        }
                        
                        foreach ($categories as $category => $resources): ?>
                            <div class="category-card">
                                <h4><?php echo htmlspecialchars($category ?? 'Uncategorized'); ?></h4>
                                <div class="resource-totals">
                                    <?php foreach ($resources as $resource): ?>
                                        <div class="total-item">
                                            <span class="resource-name"><?php echo htmlspecialchars($resource['name']); ?></span>
                                            <span class="resource-total"><?php echo number_format($resource['total_contributed']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="contribution-history">
                <h3>Contribution History</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Resource</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contributions as $contribution): ?>
                                <tr>
                                    <td><?php echo date('M d, Y H:i', strtotime($contribution['date_collected'])); ?></td>
                                    <td>
                                        <span class="resource-tag resource-<?php echo strtolower(str_replace(' ', '-', $contribution['resource_category'] ?? '')); ?>">
                                            <?php echo htmlspecialchars($contribution['resource_name']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($contribution['resource_category'] ?? 'Uncategorized'); ?></td>
                                    <td class="quantity">+<?php echo number_format($contribution['quantity']); ?></td>
                                    <td class="notes"><?php echo htmlspecialchars($contribution['notes'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($contributions)): ?>
                                <tr>
                                    <td colspan="5" class="empty-state">
                                        You haven't made any contributions yet. 
                                        <a href="/submit.php">Submit your first contribution!</a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <?php if (!empty($contributions)): ?>
            <div class="stats-box">
                <h3>Your Statistics</h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-label">Total Contributions</span>
                        <span class="stat-value"><?php echo count($contributions); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">First Contribution</span>
                        <span class="stat-value"><?php echo date('M d, Y', strtotime(end($contributions)['date_collected'])); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Latest Contribution</span>
                        <span class="stat-value"><?php echo date('M d, Y', strtotime($contributions[0]['date_collected'])); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Resource Types</span>
                        <span class="stat-value"><?php echo count($contribution_totals); ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>