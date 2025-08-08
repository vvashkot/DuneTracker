<?php
/**
 * Admin Panel - Resource Goals Management
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Handle goal operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    $db = getDB();
    
    switch ($_POST['action']) {
        case 'create_goal':
            $resource_id = intval($_POST['resource_id']);
            $target_amount = intval($_POST['target_amount']);
            $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
            
            if ($resource_id && $target_amount > 0) {
                if (createResourceGoal($resource_id, $target_amount, $deadline, $user['db_id'])) {
                    logActivity($user['db_id'], 'Created resource goal', 
                        "Resource ID: $resource_id, Target: $target_amount", 
                        $_SERVER['REMOTE_ADDR']);
                    $message = 'Resource goal created successfully';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to create resource goal';
                    $message_type = 'error';
                }
            } else {
                $message = 'Invalid goal parameters';
                $message_type = 'error';
            }
            break;
            
        case 'complete_goal':
            $goal_id = intval($_POST['goal_id']);
            
            try {
                $stmt = $db->prepare("UPDATE resource_goals SET is_active = FALSE, completed_at = NOW() WHERE id = ?");
                $stmt->execute([$goal_id]);
                
                logActivity($user['db_id'], 'Completed resource goal', 
                    "Goal ID: $goal_id", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = 'Goal marked as completed';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Failed to complete goal';
                $message_type = 'error';
            }
            break;
            
        case 'delete_goal':
            $goal_id = intval($_POST['goal_id']);
            
            try {
                $stmt = $db->prepare("DELETE FROM resource_goals WHERE id = ?");
                $stmt->execute([$goal_id]);
                
                logActivity($user['db_id'], 'Deleted resource goal', 
                    "Goal ID: $goal_id", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = 'Goal deleted successfully';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Failed to delete goal';
                $message_type = 'error';
            }
            break;
    }
}

// Get all goals and resources
$goals = getActiveResourceGoals();
$resources = getAllResources();

// Get completed goals
$db = getDB();
$completed_goals = $db->query("
    SELECT 
        rg.*,
        r.name as resource_name,
        u.username as created_by_name
    FROM resource_goals rg
    JOIN resources r ON rg.resource_id = r.id
    JOIN users u ON rg.created_by = u.id
    WHERE rg.is_active = FALSE
    ORDER BY rg.completed_at DESC
    LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource Goals - Admin Panel</title>
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
        
        .goals-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        .create-goal-form {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }
        
        .goals-table {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .goals-table table {
            width: 100%;
        }
        
        .goals-table th {
            background-color: var(--bg-tertiary);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .goals-table td {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .progress-bar {
            background-color: var(--bg-tertiary);
            border-radius: 4px;
            height: 8px;
            overflow: hidden;
            margin-bottom: 0.25rem;
            width: 150px;
        }
        
        .progress-fill {
            background-color: var(--primary-color);
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .progress-text {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        
        @media (max-width: 768px) {
            .goals-grid {
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
            <h2>Resource Goals Management</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Back to Dashboard</a>
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
                <li><a href="/admin/logs.php">📋 Activity Logs</a></li>
                <li><a href="/admin/webhooks.php">🔔 Webhooks</a></li>
                <li><a href="/admin/backup.php">💾 Backup</a></li>
                <li><a href="/admin/goals.php" class="active">🎯 Goals</a></li>
            </ul>
        </nav>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="create-goal-form">
            <h3>Create New Goal</h3>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create_goal">
                <div class="form-row" style="display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 1rem; align-items: end;">
                    <div class="form-group">
                        <label for="resource_id">Resource</label>
                        <select name="resource_id" id="resource_id" class="form-control" required>
                            <option value="">Select resource...</option>
                            <?php foreach ($resources as $resource): ?>
                                <option value="<?php echo $resource['id']; ?>">
                                    <?php echo htmlspecialchars($resource['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="target_amount">Target Amount</label>
                        <input type="number" name="target_amount" id="target_amount" 
                               class="form-control" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="deadline">Deadline (Optional)</label>
                        <input type="date" name="deadline" id="deadline" 
                               class="form-control" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Create Goal</button>
                </div>
            </form>
        </div>

        <div class="goals-grid">
            <div>
                <h3>Active Goals</h3>
                <div class="goals-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Resource</th>
                                <th>Progress</th>
                                <th>Deadline</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($goals as $goal): 
                                $progress = min(100, ($goal['current_amount'] / $goal['target_amount']) * 100);
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($goal['resource_name']); ?></strong>
                                        <br>
                                        <small class="text-secondary">
                                            Created by <?php echo htmlspecialchars($goal['created_by_name']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                        </div>
                                        <div class="progress-text">
                                            <?php echo number_format($goal['current_amount']); ?> / 
                                            <?php echo number_format($goal['target_amount']); ?>
                                            (<?php echo round($progress); ?>%)
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($goal['deadline']): ?>
                                            <?php echo date('M d, Y', strtotime($goal['deadline'])); ?>
                                        <?php else: ?>
                                            <span class="text-secondary">No deadline</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <?php if ($progress >= 100): ?>
                                                <form method="POST" style="display: inline;">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="complete_goal">
                                                    <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                    <button type="submit" class="btn btn-success btn-sm">Complete</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline;">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_goal">
                                                <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm"
                                                        onclick="return confirm('Delete this goal?')">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($goals)): ?>
                                <tr>
                                    <td colspan="4" class="empty-state">No active goals</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div>
                <h3>Recently Completed</h3>
                <div class="goals-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Resource</th>
                                <th>Target</th>
                                <th>Completed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completed_goals as $goal): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($goal['resource_name']); ?></td>
                                    <td><?php echo number_format($goal['target_amount']); ?></td>
                                    <td><?php echo date('M d', strtotime($goal['completed_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($completed_goals)): ?>
                                <tr>
                                    <td colspan="3" class="empty-state">No completed goals</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>