<?php
/**
 * Admin Panel - Farming Run Management
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Handle run operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    $db = getDB();
    
    switch ($_POST['action']) {
        case 'cancel_run':
            $run_id = intval($_POST['run_id']);
            
            try {
                $stmt = $db->prepare("UPDATE farming_runs SET status = 'cancelled', ended_at = NOW() WHERE id = ?");
                $stmt->execute([$run_id]);
                
                logActivity($user['db_id'], 'Cancelled farming run', 
                    "Run ID: $run_id", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = 'Farming run cancelled';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Failed to cancel run';
                $message_type = 'error';
            }
            break;
            
        case 'complete_run':
            $run_id = intval($_POST['run_id']);
            
            try {
                $stmt = $db->prepare("UPDATE farming_runs SET status = 'completed', ended_at = NOW() WHERE id = ?");
                $stmt->execute([$run_id]);
                
                logActivity($user['db_id'], 'Completed farming run', 
                    "Run ID: $run_id", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = 'Farming run marked as completed';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Failed to complete run';
                $message_type = 'error';
            }
            break;
            
        case 'delete_run':
            $run_id = intval($_POST['run_id']);
            
            try {
                // Start transaction
                $db->beginTransaction();
                
                // Delete related data first
                $db->prepare("DELETE FROM run_refined_outputs WHERE run_id = ?")->execute([$run_id]);
                $db->prepare("DELETE FROM run_collections WHERE run_id = ?")->execute([$run_id]);
                $db->prepare("DELETE FROM run_participants WHERE run_id = ?")->execute([$run_id]);
                
                // Delete the run itself
                $stmt = $db->prepare("DELETE FROM farming_runs WHERE id = ?");
                $stmt->execute([$run_id]);
                
                // Commit transaction
                $db->commit();
                
                logActivity($user['db_id'], 'Deleted farming run', 
                    "Run ID: $run_id", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = 'Farming run deleted successfully';
                $message_type = 'success';
            } catch (PDOException $e) {
                $db->rollBack();
                $message = 'Failed to delete run: ' . $e->getMessage();
                $message_type = 'error';
            }
            break;
    }
}

// Get status filter
$status_filter = $_GET['status'] ?? 'all';

// Build query
$sql = "
    SELECT 
        fr.*,
        u.username as creator_name,
        u.avatar as creator_avatar,
        COUNT(DISTINCT rp.user_id) as participant_count,
        COUNT(DISTINCT rc.id) as collection_count,
        COALESCE(SUM(rc.quantity), 0) as total_collected
    FROM farming_runs fr
    JOIN users u ON fr.created_by = u.id
    LEFT JOIN run_participants rp ON fr.id = rp.run_id
    LEFT JOIN run_collections rc ON fr.id = rc.run_id
";

if ($status_filter !== 'all') {
    $sql .= " WHERE fr.status = :status";
}

$sql .= " GROUP BY fr.id ORDER BY fr.started_at DESC";

$db = getDB();
$stmt = $db->prepare($sql);

if ($status_filter !== 'all') {
    $stmt->execute(['status' => $status_filter]);
} else {
    $stmt->execute();
}

$runs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farming Run Management - Admin Panel</title>
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
            padding: 1rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .filter-btn {
            padding: 0.5rem 1rem;
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        
        .filter-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .filter-btn.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .runs-table {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .runs-table table {
            width: 100%;
        }
        
        .runs-table th {
            background-color: var(--bg-tertiary);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .runs-table td {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .runs-table tr:hover td {
            background-color: var(--bg-tertiary);
        }
        
        .run-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .run-name {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .run-meta {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .stats-cell {
            font-size: 0.875rem;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.active {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--success-color);
        }
        
        .status-badge.completed {
            background-color: rgba(75, 139, 245, 0.2);
            color: var(--primary-color);
        }
        
        .status-badge.cancelled {
            background-color: rgba(244, 67, 54, 0.2);
            color: var(--danger-color);
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
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
            <h2>Farming Run Management</h2>
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
                <li><a href="/admin/runs.php" class="active">🚜 Farming Runs</a></li>
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

        <div class="filter-bar">
            <span>Filter by status:</span>
            <a href="?status=all" class="filter-btn <?php echo $status_filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="?status=active" class="filter-btn <?php echo $status_filter === 'active' ? 'active' : ''; ?>">Active</a>
            <a href="?status=completed" class="filter-btn <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">Completed</a>
            <a href="?status=cancelled" class="filter-btn <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
        </div>

        <div class="runs-table">
            <table>
                <thead>
                    <tr>
                        <th>Run Details</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Participants</th>
                        <th>Collections</th>
                        <th>Total Collected</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $run): ?>
                        <tr>
                            <td>
                                <div class="run-info">
                                    <span class="run-name"><?php echo htmlspecialchars($run['name']); ?></span>
                                    <span class="run-meta">
                                        Started <?php echo date('M d, Y at H:i', strtotime($run['started_at'])); ?>
                                        by <?php echo htmlspecialchars($run['creator_name']); ?>
                                    </span>
                                    <?php if ($run['ended_at']): ?>
                                        <span class="run-meta">
                                            Ended <?php echo date('M d, Y at H:i', strtotime($run['ended_at'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="run-type"><?php echo htmlspecialchars($run['run_type']); ?></span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo htmlspecialchars($run['status']); ?>">
                                    <?php echo htmlspecialchars($run['status']); ?>
                                </span>
                            </td>
                            <td class="stats-cell"><?php echo number_format($run['participant_count']); ?></td>
                            <td class="stats-cell"><?php echo number_format($run['collection_count']); ?></td>
                            <td class="stats-cell"><?php echo number_format($run['total_collected']); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="/farming-run.php?id=<?php echo $run['id']; ?>" 
                                       class="btn btn-secondary btn-sm">View</a>
                                    
                                    <?php if ($run['status'] === 'active'): ?>
                                        <form method="POST" style="display: inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="complete_run">
                                            <input type="hidden" name="run_id" value="<?php echo $run['id']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Complete</button>
                                        </form>
                                        
                                        <form method="POST" style="display: inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="cancel_run">
                                            <input type="hidden" name="run_id" value="<?php echo $run['id']; ?>">
                                            <button type="submit" 
                                                    class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Are you sure you want to cancel this run?')">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="display: inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_run">
                                        <input type="hidden" name="run_id" value="<?php echo $run['id']; ?>">
                                        <button type="submit" 
                                                class="btn btn-danger btn-sm"
                                                onclick="return confirm('Are you sure you want to permanently delete this run? This will delete all associated collections, refinements, and participant data.')">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($runs)): ?>
                        <tr>
                            <td colspan="7" class="empty-state">No farming runs found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>