<?php
/**
 * Admin Panel - Delete User
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';
$user_to_delete = null;

// Get user ID from query string
$delete_user_id = intval($_GET['id'] ?? 0);

if ($delete_user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$delete_user_id]);
    $user_to_delete = $stmt->fetch();
    
    if (!$user_to_delete) {
        header('Location: /admin/users.php');
        exit();
    }
    
    // Don't allow deleting yourself
    if ($delete_user_id === $user['db_id']) {
        $message = "You cannot delete your own account";
        $message_type = 'error';
        $user_to_delete = null;
    }
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    verifyPOST();
    
    $confirm_username = trim($_POST['confirm_username'] ?? '');
    $delete_option = $_POST['delete_option'] ?? '';
    
    if ($user_to_delete && $confirm_username === $user_to_delete['username']) {
        try {
            $db->beginTransaction();
            
            if ($delete_option === 'hard_delete') {
                // Hard delete - removes user and all their data
                // First, handle foreign key constraints that don't have CASCADE
                
                // Update farming runs created by this user to NULL or reassign
                $stmt = $db->prepare("UPDATE farming_runs SET created_by = NULL WHERE created_by = ?");
                $stmt->execute([$delete_user_id]);
                
                // Update resource goals created by this user
                $stmt = $db->prepare("UPDATE resource_goals SET created_by = NULL WHERE created_by = ?");
                $stmt->execute([$delete_user_id]);
                
                // Update run templates created by this user
                $stmt = $db->prepare("UPDATE run_templates SET created_by = NULL WHERE created_by = ?");
                $stmt->execute([$delete_user_id]);
                
                // Now delete the user (CASCADE will handle the rest)
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$delete_user_id]);
                
                logActivity($user['db_id'], 'Deleted user (hard delete)', 
                    "Username: {$user_to_delete['username']}, User ID: $delete_user_id", 
                    $_SERVER['REMOTE_ADDR']);
                    
                $message = "User and all associated data deleted permanently";
                
            } elseif ($delete_option === 'soft_delete') {
                // Soft delete - marks as merged to self (hides from UI but keeps data)
                $stmt = $db->prepare("UPDATE users SET merged_into_user_id = ? WHERE id = ?");
                $stmt->execute([$delete_user_id, $delete_user_id]);
                
                logActivity($user['db_id'], 'Deleted user (soft delete)', 
                    "Username: {$user_to_delete['username']}, User ID: $delete_user_id", 
                    $_SERVER['REMOTE_ADDR']);
                    
                $message = "User hidden from system (data preserved)";
                
            } elseif ($delete_option === 'anonymize') {
                // Anonymize - remove personal info but keep contributions
                $anonymous_name = "Deleted User #" . $delete_user_id;
                $stmt = $db->prepare("UPDATE users SET username = ?, discord_id = NULL, avatar = NULL, merged_into_user_id = ? WHERE id = ?");
                $stmt->execute([$anonymous_name, $delete_user_id, $delete_user_id]);
                
                logActivity($user['db_id'], 'Anonymized user', 
                    "Original username: {$user_to_delete['username']}, User ID: $delete_user_id", 
                    $_SERVER['REMOTE_ADDR']);
                    
                $message = "User anonymized (contributions preserved)";
            }
            
            $db->commit();
            $message_type = 'success';
            
            // Redirect after successful deletion
            header('Location: /admin/users.php?deleted=1');
            exit();
            
        } catch (Exception $e) {
            $db->rollBack();
            $message = "Failed to delete user: " . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = "Username confirmation did not match";
        $message_type = 'error';
    }
}

// Get user's data statistics
$stats = [];
if ($user_to_delete) {
    $db = getDB();
    
    // Contributions
    $stmt = $db->prepare("SELECT COUNT(*) FROM contributions WHERE user_id = ?");
    $stmt->execute([$delete_user_id]);
    $stats['contributions'] = $stmt->fetchColumn();
    
    // Farming runs
    $stmt = $db->prepare("SELECT COUNT(*) FROM run_participants WHERE user_id = ?");
    $stmt->execute([$delete_user_id]);
    $stats['runs'] = $stmt->fetchColumn();
    
    // Collections
    $stmt = $db->prepare("SELECT COUNT(*) FROM run_collections WHERE collected_by = ?");
    $stmt->execute([$delete_user_id]);
    $stats['collections'] = $stmt->fetchColumn();
    
    // Activity logs
    $stmt = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE user_id = ?");
    $stmt->execute([$delete_user_id]);
    $stats['logs'] = $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete User - Admin Panel</title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <style>
        .delete-container {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .warning-box {
            background-color: rgba(220, 53, 69, 0.1);
            border: 2px solid var(--danger-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .warning-box h3 {
            color: var(--danger-color);
            margin-bottom: 1rem;
        }
        
        .user-info-box {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .user-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .user-avatar-large {
            width: 60px;
            height: 60px;
            border-radius: 50%;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .stat-item {
            background-color: var(--bg-tertiary);
            padding: 1rem;
            border-radius: var(--radius-sm);
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .delete-options {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .option-item {
            margin-bottom: 1rem;
            padding: 1rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .option-item:hover {
            background-color: var(--bg-input);
        }
        
        .option-item input[type="radio"] {
            margin-right: 0.5rem;
        }
        
        .option-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .option-description {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-left: 1.5rem;
        }
        
        .confirm-section {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--danger-color);
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
        <div class="delete-container">
            <div class="page-header">
                <h2>Delete User</h2>
                <div class="header-actions">
                    <a href="/admin/users.php" class="btn btn-secondary">Cancel</a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($user_to_delete): ?>
                <div class="warning-box">
                    <h3>⚠️ Warning: This action cannot be undone</h3>
                    <p>You are about to delete a user account. Please review the information below carefully.</p>
                </div>

                <div class="user-info-box">
                    <div class="user-header">
                        <img src="<?php echo htmlspecialchars(getAvatarUrl($user_to_delete)); ?>" 
                             alt="Avatar" 
                             class="user-avatar-large">
                        <div>
                            <h3><?php echo htmlspecialchars($user_to_delete['username']); ?></h3>
                            <p style="color: var(--text-secondary);">
                                <?php if ($user_to_delete['discord_id']): ?>
                                    Discord ID: <?php echo htmlspecialchars($user_to_delete['discord_id']); ?>
                                <?php else: ?>
                                    Manual User
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo number_format($stats['contributions']); ?></div>
                            <div class="stat-label">Contributions</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo number_format($stats['runs']); ?></div>
                            <div class="stat-label">Farming Runs</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo number_format($stats['collections']); ?></div>
                            <div class="stat-label">Collections</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo number_format($stats['logs']); ?></div>
                            <div class="stat-label">Activity Logs</div>
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete_user">
                    
                    <div class="delete-options">
                        <h3>Choose Deletion Method</h3>
                        
                        <label class="option-item">
                            <input type="radio" name="delete_option" value="soft_delete" checked>
                            <span class="option-title">Soft Delete (Recommended)</span>
                            <div class="option-description">
                                Hides the user from the system but preserves all data. Can be reversed by database admin.
                            </div>
                        </label>
                        
                        <label class="option-item">
                            <input type="radio" name="delete_option" value="anonymize">
                            <span class="option-title">Anonymize User</span>
                            <div class="option-description">
                                Removes personal information but keeps contributions. Username becomes "Deleted User #<?php echo $delete_user_id; ?>".
                            </div>
                        </label>
                        
                        <label class="option-item">
                            <input type="radio" name="delete_option" value="hard_delete">
                            <span class="option-title">Hard Delete (Permanent)</span>
                            <div class="option-description">
                                Permanently removes the user and ALL associated data. This cannot be undone.
                            </div>
                        </label>
                    </div>
                    
                    <div class="confirm-section">
                        <h3>Confirm Deletion</h3>
                        <p>Type the username exactly as shown to confirm: <strong><?php echo htmlspecialchars($user_to_delete['username']); ?></strong></p>
                        <div class="form-group">
                            <input type="text" 
                                   name="confirm_username" 
                                   class="form-control" 
                                   placeholder="Enter username to confirm"
                                   required>
                        </div>
                        <button type="submit" class="btn btn-danger">Delete User</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-error">
                    No user selected for deletion.
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>