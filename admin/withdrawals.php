<?php
/**
 * Admin Panel - Withdrawal Management
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Handle withdrawal operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    $db = getDB();
    
    switch ($_POST['action']) {
        case 'delete_withdrawal':
            $withdrawal_id = intval($_POST['withdrawal_id']);
            
            try {
                $db->beginTransaction();
                
                // Get withdrawal details before deleting
                $stmt = $db->prepare("
                    SELECT w.*, r.name as resource_name, u.username 
                    FROM withdrawals w
                    JOIN resources r ON w.resource_id = r.id
                    JOIN users u ON w.user_id = u.id
                    WHERE w.id = ?
                ");
                $stmt->execute([$withdrawal_id]);
                $withdrawal = $stmt->fetch();
                
                if ($withdrawal) {
                    // Delete the withdrawal
                    $stmt = $db->prepare("DELETE FROM withdrawals WHERE id = ?");
                    $stmt->execute([$withdrawal_id]);
                    
                    // Update resource total_withdrawn
                    $stmt = $db->prepare("
                        UPDATE resources 
                        SET total_withdrawn = total_withdrawn - ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$withdrawal['quantity'], $withdrawal['resource_id']]);
                    
                    logActivity($user['db_id'], 'Deleted withdrawal', 
                        "User: {$withdrawal['username']}, Resource: {$withdrawal['resource_name']}, Quantity: {$withdrawal['quantity']}", 
                        $_SERVER['REMOTE_ADDR']);
                    
                    $message = "Withdrawal deleted and resources restored";
                    $message_type = 'success';
                }
                
                $db->commit();
            } catch (PDOException $e) {
                $db->rollBack();
                $message = "Failed to delete withdrawal: " . $e->getMessage();
                $message_type = 'error';
            }
            break;
    }
}

// Get filter parameters
$filter_user = $_GET['user'] ?? '';
$filter_resource = $_GET['resource'] ?? '';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');

// Build query with filters
$db = getDB();
$query = "
    SELECT 
        w.*,
        r.name as resource_name,
        r.category as resource_category,
        u.username,
        u.avatar,
        u.discord_id
    FROM withdrawals w
    JOIN resources r ON w.resource_id = r.id
    JOIN users u ON w.user_id = u.id
    WHERE 1=1
";

$params = [];

if ($filter_user) {
    $query .= " AND u.username LIKE ?";
    $params[] = "%$filter_user%";
}

if ($filter_resource) {
    $query .= " AND w.resource_id = ?";
    $params[] = $filter_resource;
}

if ($filter_date_from) {
    $query .= " AND DATE(w.created_at) >= ?";
    $params[] = $filter_date_from;
}

if ($filter_date_to) {
    $query .= " AND DATE(w.created_at) <= ?";
    $params[] = $filter_date_to;
}

$query .= " ORDER BY w.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$withdrawals = $stmt->fetchAll();

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT w.user_id) as unique_users,
        COUNT(*) as total_withdrawals,
        COALESCE(SUM(w.quantity), 0) as total_quantity,
        COUNT(DISTINCT w.resource_id) as unique_resources
    FROM withdrawals w
    WHERE 1=1
";

$stmt = $db->prepare(str_replace('WHERE 1=1', 'WHERE 1=1' . 
    ($filter_user ? " AND u.username LIKE ?" : "") . 
    ($filter_resource ? " AND w.resource_id = ?" : "") . 
    ($filter_date_from ? " AND DATE(w.created_at) >= ?" : "") . 
    ($filter_date_to ? " AND DATE(w.created_at) <= ?" : ""), 
    str_replace('FROM withdrawals w', 'FROM withdrawals w JOIN users u ON w.user_id = u.id', $stats_query)));
$stmt->execute($params);
$stats = $stmt->fetch();

// Get all resources for filter dropdown
$resources = $db->query("SELECT id, name, category FROM resources ORDER BY category, name")->fetchAll();

// Get top withdrawers
$top_withdrawers = $db->query("
    SELECT 
        u.username,
        u.avatar,
        COUNT(*) as withdrawal_count,
        COALESCE(SUM(w.quantity), 0) as total_withdrawn
    FROM withdrawals w
    JOIN users u ON w.user_id = u.id
    WHERE DATE(w.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY w.user_id
    ORDER BY total_withdrawn DESC
    LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawal Management - Admin Panel</title>
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .filter-form {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .withdrawals-table {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .withdrawals-table table {
            width: 100%;
        }
        
        .withdrawals-table th {
            background-color: var(--bg-tertiary);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .withdrawals-table td {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .withdrawals-table tr:hover td {
            background-color: var(--bg-tertiary);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
        }
        
        .withdrawal-quantity {
            font-weight: 700;
            color: var(--danger-color);
        }
        
        .top-withdrawers {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .withdrawer-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
        }
        
        .withdrawer-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .withdrawer-stats {
            text-align: right;
        }
        
        .withdrawer-total {
            font-weight: 700;
            color: var(--danger-color);
        }
        
        .withdrawer-count {
            font-size: 0.875rem;
            color: var(--text-secondary);
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
            <h2>Withdrawal Management</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Back to Site</a>
            </div>
        </div>

        <nav class="admin-nav">
            <ul>
                <li><a href="/admin/">📊 Dashboard</a></li>
                <li><a href="/admin/users.php">👥 Users</a></li>
                <li><a href="/admin/approvals.php">✅ Approvals</a></li>
                <li><a href="/admin/contributions.php">💰 Contributions</a></li>
                <li><a href="/admin/withdrawals.php" class="active">📤 Withdrawals</a></li>
                <li><a href="/admin/resources.php">📦 Resources</a></li>
                <li><a href="/admin/runs.php">🚜 Farming Runs</a></li>
                <li><a href="/admin/logs.php">📋 Activity Logs</a></li>
                <li><a href="/admin/webhooks.php">🔔 Webhooks</a></li>
                <li><a href="/admin/guild-settings.php">⚙️ Guild Settings</a></li>
                <li><a href="/admin/backup.php">💾 Backup</a></li>
            </ul>
        </nav>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['total_withdrawals']); ?></div>
                <div class="stat-label">Total Withdrawals</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['unique_users']); ?></div>
                <div class="stat-label">Unique Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['total_quantity']); ?></div>
                <div class="stat-label">Resources Withdrawn</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['unique_resources']); ?></div>
                <div class="stat-label">Resource Types</div>
            </div>
        </div>

        <!-- Top Withdrawers -->
        <div class="top-withdrawers">
            <h3>Top Withdrawers (Last 30 Days)</h3>
            <?php foreach ($top_withdrawers as $withdrawer): ?>
                <div class="withdrawer-item">
                    <div class="withdrawer-info">
                        <img src="<?php echo htmlspecialchars(getAvatarUrl($withdrawer)); ?>" 
                             alt="Avatar" 
                             class="user-avatar">
                        <span><?php echo htmlspecialchars($withdrawer['username']); ?></span>
                    </div>
                    <div class="withdrawer-stats">
                        <div class="withdrawer-total"><?php echo number_format($withdrawer['total_withdrawn']); ?></div>
                        <div class="withdrawer-count"><?php echo $withdrawer['withdrawal_count']; ?> withdrawals</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Filters -->
        <div class="filter-form">
            <h3>Filter Withdrawals</h3>
            <form method="GET">
                <div class="filter-grid">
                    <div class="form-group">
                        <label for="user">Username</label>
                        <input type="text" 
                               name="user" 
                               id="user" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($filter_user); ?>"
                               placeholder="Search by username...">
                    </div>
                    
                    <div class="form-group">
                        <label for="resource">Resource</label>
                        <select name="resource" id="resource" class="form-control">
                            <option value="">All Resources</option>
                            <?php foreach ($resources as $resource): ?>
                                <option value="<?php echo $resource['id']; ?>" 
                                        <?php echo $filter_resource == $resource['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($resource['name']); ?> 
                                    (<?php echo htmlspecialchars($resource['category']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="date_from">From Date</label>
                        <input type="date" 
                               name="date_from" 
                               id="date_from" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="date_to">To Date</label>
                        <input type="date" 
                               name="date_to" 
                               id="date_to" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="withdrawals.php" class="btn btn-secondary">Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Withdrawals Table -->
        <div class="withdrawals-table">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>User</th>
                        <th>Resource</th>
                        <th>Quantity</th>
                        <th>Purpose</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($withdrawals as $withdrawal): ?>
                        <tr>
                            <td><?php echo date('M d, Y H:i', strtotime($withdrawal['created_at'])); ?></td>
                            <td>
                                <div class="user-info">
                                    <img src="<?php echo htmlspecialchars(getAvatarUrl($withdrawal)); ?>" 
                                         alt="Avatar" 
                                         class="user-avatar">
                                    <span><?php echo htmlspecialchars($withdrawal['username']); ?></span>
                                </div>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($withdrawal['resource_name']); ?>
                                <small style="color: var(--text-secondary);">
                                    (<?php echo htmlspecialchars($withdrawal['resource_category']); ?>)
                                </small>
                            </td>
                            <td class="withdrawal-quantity">-<?php echo number_format($withdrawal['quantity'], 2); ?></td>
                            <td><?php echo htmlspecialchars($withdrawal['purpose']); ?></td>
                            <td><?php echo htmlspecialchars($withdrawal['notes'] ?? '-'); ?></td>
                            <td>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Delete this withdrawal and restore resources to inventory?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete_withdrawal">
                                    <input type="hidden" name="withdrawal_id" value="<?php echo $withdrawal['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($withdrawals)): ?>
                        <tr>
                            <td colspan="7" class="empty-state">No withdrawals found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>