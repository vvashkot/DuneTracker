<?php
/**
 * Admin Panel - User Approvals
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    $target_user_id = intval($_POST['user_id']);
    
    switch ($_POST['action']) {
        case 'approve':
            $db = getDB();
            $stmt = $db->prepare("
                UPDATE users 
                SET approval_status = 'approved', 
                    approved_at = NOW(), 
                    approved_by = ? 
                WHERE id = ? AND approval_status = 'pending'
            ");
            $stmt->execute([$user['db_id'], $target_user_id]);
            
            // Get user info for logging
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$target_user_id]);
            $username = $stmt->fetchColumn();
            
            logActivity($user['db_id'], 'Approved user', 
                "User: $username (ID: $target_user_id)", 
                $_SERVER['REMOTE_ADDR']);
            
            // Send webhook notification
            require_once '../includes/webhooks.php';
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$target_user_id]);
            $approved_user = $stmt->fetch();
            if ($approved_user) {
                notifyUserApproved($approved_user, $user);
            }
            
            $message = "User approved successfully";
            $message_type = 'success';
            break;
            
        case 'reject':
            $reason = trim($_POST['reason'] ?? '');
            
            $db = getDB();
            $stmt = $db->prepare("
                UPDATE users 
                SET approval_status = 'rejected', 
                    rejection_reason = ?,
                    approved_at = NOW(),
                    approved_by = ? 
                WHERE id = ? AND approval_status = 'pending'
            ");
            $stmt->execute([$reason, $user['db_id'], $target_user_id]);
            
            // Get user info for logging
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$target_user_id]);
            $username = $stmt->fetchColumn();
            
            logActivity($user['db_id'], 'Rejected user', 
                "User: $username (ID: $target_user_id), Reason: $reason", 
                $_SERVER['REMOTE_ADDR']);
            
            $message = "User rejected";
            $message_type = 'warning';
            break;
    }
}

// Get pending approvals
$db = getDB();
$pending_users = $db->query("
    SELECT 
        u.*,
        ar.requested_at,
        ar.first_login_ip,
        ar.notes
    FROM users u
    LEFT JOIN approval_requests ar ON u.id = ar.user_id
    WHERE u.approval_status = 'pending'
    ORDER BY u.created_at DESC
")->fetchAll();

// Get recent approval actions
$recent_actions = $db->query("
    SELECT 
        u.id,
        u.username,
        u.avatar,
        u.discord_id,
        u.approval_status,
        u.approved_at,
        u.rejection_reason,
        approver.username as approver_name
    FROM users u
    LEFT JOIN users approver ON u.approved_by = approver.id
    WHERE u.approval_status IN ('approved', 'rejected')
    AND u.approved_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY u.approved_at DESC
    LIMIT 20
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Approvals - Admin Panel</title>
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
        
        .approval-grid {
            display: grid;
            gap: 1rem;
            margin-bottom: 3rem;
        }
        
        .approval-card {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .approval-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
        }
        
        .user-details h3 {
            margin: 0;
            font-size: 1.125rem;
        }
        
        .user-meta {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }
        
        .approval-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
        }
        
        .info-item {
            font-size: 0.875rem;
        }
        
        .info-label {
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            color: var(--text-primary);
            font-weight: 500;
        }
        
        .reject-form {
            margin-top: 1rem;
            padding: 1rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            display: none;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: var(--warning-color);
        }
        
        .badge-approved {
            background-color: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }
        
        .badge-rejected {
            background-color: rgba(220, 53, 69, 0.2);
            color: var(--danger-color);
        }
        
        .recent-section {
            margin-top: 3rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }
        
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
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
            <h2>User Approvals</h2>
            <div class="header-actions">
                <a href="/admin/" class="btn btn-secondary">Back to Admin</a>
            </div>
        </div>

        <nav class="admin-nav">
            <ul>
                <li><a href="/admin/">📊 Dashboard</a></li>
                <li><a href="/admin/users.php">👥 Users</a></li>
                <li><a href="/admin/approvals.php" class="active">✅ Approvals</a></li>
                <li><a href="/admin/contributions.php">💰 Contributions</a></li>
                <li><a href="/admin/withdrawals.php">📤 Withdrawals</a></li>
                <li><a href="/admin/resources.php">📦 Resources</a></li>
                <li><a href="/admin/runs.php">🚜 Farming Runs</a></li>
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

        <h3>Pending Approvals (<?php echo count($pending_users); ?>)</h3>
        
        <?php if (empty($pending_users)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">✅</div>
                <h3>No Pending Approvals</h3>
                <p>All user registrations have been reviewed.</p>
            </div>
        <?php else: ?>
            <div class="approval-grid">
                <?php foreach ($pending_users as $pending_user): ?>
                    <div class="approval-card">
                        <div class="approval-header">
                            <div class="user-info">
                                <img src="<?php echo htmlspecialchars(getAvatarUrl($pending_user)); ?>" 
                                     alt="Avatar" 
                                     class="user-avatar">
                                <div class="user-details">
                                    <h3><?php echo htmlspecialchars($pending_user['username']); ?></h3>
                                    <div class="user-meta">
                                        Discord ID: <?php echo htmlspecialchars($pending_user['discord_id']); ?>
                                    </div>
                                </div>
                            </div>
                            <span class="badge badge-pending">Pending</span>
                        </div>
                        
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Registered</div>
                                <div class="info-value">
                                    <?php echo date('M d, Y H:i', strtotime($pending_user['created_at'])); ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">IP Address</div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($pending_user['first_login_ip'] ?? 'Unknown'); ?>
                                </div>
                            </div>
                            <?php if ($pending_user['notes']): ?>
                                <div class="info-item">
                                    <div class="info-label">Notes</div>
                                    <div class="info-value">
                                        <?php echo htmlspecialchars($pending_user['notes']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="approval-actions">
                            <form method="POST" style="display: inline;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="user_id" value="<?php echo $pending_user['id']; ?>">
                                <button type="submit" class="btn btn-primary">Approve</button>
                            </form>
                            <button type="button" 
                                    class="btn btn-danger" 
                                    onclick="toggleRejectForm(<?php echo $pending_user['id']; ?>)">
                                Reject
                            </button>
                        </div>
                        
                        <div id="reject-form-<?php echo $pending_user['id']; ?>" class="reject-form">
                            <form method="POST">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="user_id" value="<?php echo $pending_user['id']; ?>">
                                <div class="form-group">
                                    <label for="reason-<?php echo $pending_user['id']; ?>">Rejection Reason (Optional)</label>
                                    <input type="text" 
                                           name="reason" 
                                           id="reason-<?php echo $pending_user['id']; ?>" 
                                           class="form-control" 
                                           placeholder="e.g., Not a guild member">
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                                    <button type="button" 
                                            class="btn btn-secondary" 
                                            onclick="toggleRejectForm(<?php echo $pending_user['id']; ?>)">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="recent-section">
            <h3>Recent Actions</h3>
            <?php if (empty($recent_actions)): ?>
                <p style="color: var(--text-secondary);">No recent approval actions.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Approved By</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_actions as $action): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <img src="<?php echo htmlspecialchars(getAvatarUrl($action)); ?>" 
                                                 alt="Avatar" 
                                                 class="small-avatar">
                                            <span><?php echo htmlspecialchars($action['username']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $action['approval_status']; ?>">
                                            <?php echo ucfirst($action['approval_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y H:i', strtotime($action['approved_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($action['approver_name']); ?></td>
                                    <td>
                                        <?php if ($action['rejection_reason']): ?>
                                            <?php echo htmlspecialchars($action['rejection_reason']); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleRejectForm(userId) {
            const form = document.getElementById('reject-form-' + userId);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>
</html>