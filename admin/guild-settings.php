<?php
/**
 * Admin Panel - Guild Settings Management
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Handle settings updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    $db = getDB();
    
    switch ($_POST['action']) {
        case 'update_tax_settings':
            $tax_enabled = isset($_POST['tax_enabled']) ? 'true' : 'false';
            $tax_rate = floatval($_POST['tax_rate']) / 100; // Convert percentage to decimal
            
            // Validate tax rate
            if ($tax_rate < 0 || $tax_rate > 1) {
                $message = "Tax rate must be between 0% and 100%";
                $message_type = 'error';
                break;
            }
            
            try {
                $db->beginTransaction();
                
                // Update settings
                $stmt = $db->prepare("
                    INSERT INTO guild_settings (setting_key, setting_value, updated_by) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        setting_value = VALUES(setting_value), 
                        updated_by = VALUES(updated_by),
                        updated_at = NOW()
                ");
                
                $stmt->execute(['guild_tax_enabled', $tax_enabled, $user['db_id']]);
                $stmt->execute(['guild_tax_rate', $tax_rate, $user['db_id']]);
                
                $db->commit();
                
                logActivity($user['db_id'], 'Updated guild tax settings', 
                    "Enabled: $tax_enabled, Rate: " . ($tax_rate * 100) . "%", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = "Guild tax settings updated successfully";
                $message_type = 'success';
            } catch (PDOException $e) {
                $db->rollBack();
                $message = "Failed to update settings: " . $e->getMessage();
                $message_type = 'error';
            }
            break;
            
        case 'withdraw_treasury':
            $resource_id = intval($_POST['resource_id']);
            $quantity = floatval($_POST['quantity']);
            $reason = trim($_POST['reason']);
            
            if ($quantity <= 0) {
                $message = "Quantity must be greater than 0";
                $message_type = 'error';
                break;
            }
            
            try {
                $db->beginTransaction();
                
                // Check available quantity
                $stmt = $db->prepare("
                    SELECT COALESCE(SUM(quantity), 0) as available 
                    FROM guild_treasury 
                    WHERE resource_id = ?
                ");
                $stmt->execute([$resource_id]);
                $available = $stmt->fetchColumn();
                
                if ($quantity > $available) {
                    throw new Exception("Insufficient funds in treasury. Available: " . number_format($available, 2));
                }
                
                // Record withdrawal
                $stmt = $db->prepare("
                    INSERT INTO guild_treasury (resource_id, quantity, source_type, source_user_id, notes)
                    VALUES (?, ?, 'admin_adjustment', ?, ?)
                ");
                $stmt->execute([$resource_id, -$quantity, $user['db_id'], "Withdrawal: $reason"]);
                
                // Get resource name for logging
                $stmt = $db->prepare("SELECT name FROM resources WHERE id = ?");
                $stmt->execute([$resource_id]);
                $resource_name = $stmt->fetchColumn();
                
                $db->commit();
                
                logActivity($user['db_id'], 'Withdrew from guild treasury', 
                    "Resource: $resource_name, Quantity: $quantity, Reason: $reason", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = "Successfully withdrew " . number_format($quantity, 2) . " $resource_name from guild treasury";
                $message_type = 'success';
            } catch (Exception $e) {
                $db->rollBack();
                $message = $e->getMessage();
                $message_type = 'error';
            }
            break;
    }
}

// Get current settings
$db = getDB();
$settings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM guild_settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$tax_enabled = ($settings['guild_tax_enabled'] ?? 'true') === 'true';
$tax_rate = floatval($settings['guild_tax_rate'] ?? 0.10) * 100; // Convert to percentage

// Get treasury totals
$treasury_totals = $db->query("
    SELECT 
        r.id,
        r.name,
        r.category,
        COALESCE(SUM(gt.quantity), 0) as total_quantity
    FROM resources r
    LEFT JOIN guild_treasury gt ON r.id = gt.resource_id
    GROUP BY r.id, r.name, r.category
    HAVING total_quantity > 0
    ORDER BY r.category, r.name
")->fetchAll();

// Get recent treasury activity
$recent_activity = $db->query("
    SELECT 
        gt.*,
        r.name as resource_name,
        u.username as user_name
    FROM guild_treasury gt
    JOIN resources r ON gt.resource_id = r.id
    LEFT JOIN users u ON gt.source_user_id = u.id
    ORDER BY gt.created_at DESC
    LIMIT 50
")->fetchAll();

// Get all resources for withdrawal form
$all_resources = $db->query("
    SELECT id, name, category 
    FROM resources 
    ORDER BY category, name
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guild Settings - Admin Panel</title>
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
        
        .settings-section {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 1.5rem;
        }
        
        .tax-input-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .tax-input {
            width: 100px;
        }
        
        .treasury-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .treasury-card {
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            text-align: center;
        }
        
        .treasury-resource {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .treasury-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .treasury-category {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }
        
        .activity-table {
            margin-top: 1.5rem;
        }
        
        .activity-table table {
            width: 100%;
        }
        
        .activity-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .activity-positive {
            color: var(--success-color);
        }
        
        .activity-negative {
            color: var(--danger-color);
        }
        
        .withdrawal-form {
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .tax-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .tax-status.enabled {
            background-color: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }
        
        .tax-status.disabled {
            background-color: rgba(220, 53, 69, 0.2);
            color: var(--danger-color);
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
            <h2>Guild Settings</h2>
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
                <li><a href="/admin/withdrawals.php">📤 Withdrawals</a></li>
                <li><a href="/admin/resources.php">📦 Resources</a></li>
                <li><a href="/admin/runs.php">🚜 Farming Runs</a></li>
                <li><a href="/admin/logs.php">📋 Activity Logs</a></li>
                <li><a href="/admin/webhooks.php">🔔 Webhooks</a></li>
                <li><a href="/admin/guild-settings.php" class="active">⚙️ Guild Settings</a></li>
                <li><a href="/admin/backup.php">💾 Backup</a></li>
            </ul>
        </nav>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Tax Settings -->
        <div class="settings-section">
            <h3>Guild Tax Settings</h3>
            <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                Configure automatic tax deduction from all resource contributions
            </p>
            
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_tax_settings">
                
                <div class="settings-grid">
                    <div>
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" 
                                   name="tax_enabled" 
                                   value="1" 
                                   <?php echo $tax_enabled ? 'checked' : ''; ?>>
                            <span>Enable Guild Tax</span>
                            <span class="tax-status <?php echo $tax_enabled ? 'enabled' : 'disabled'; ?>">
                                <?php echo $tax_enabled ? 'ENABLED' : 'DISABLED'; ?>
                            </span>
                        </label>
                    </div>
                    
                    <div>
                        <label for="tax_rate">Tax Rate</label>
                        <div class="tax-input-group">
                            <input type="number" 
                                   name="tax_rate" 
                                   id="tax_rate" 
                                   class="form-control tax-input" 
                                   value="<?php echo number_format($tax_rate, 2); ?>" 
                                   min="0" 
                                   max="100" 
                                   step="0.01"
                                   required>
                            <span>%</span>
                        </div>
                        <small style="color: var(--text-secondary);">
                            Default: 10%. Users can opt to contribute more.
                        </small>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary">Update Tax Settings</button>
                </div>
            </form>
        </div>

        <!-- Guild Treasury -->
        <div class="settings-section">
            <h3>Guild Treasury</h3>
            <p style="color: var(--text-secondary);">
                Resources collected through guild tax
            </p>
            
            <?php if (empty($treasury_totals)): ?>
                <p style="color: var(--text-secondary); margin-top: 1rem;">
                    No resources in guild treasury yet.
                </p>
            <?php else: ?>
                <div class="treasury-grid">
                    <?php foreach ($treasury_totals as $resource): ?>
                        <div class="treasury-card">
                            <div class="treasury-resource"><?php echo htmlspecialchars($resource['name']); ?></div>
                            <div class="treasury-amount"><?php echo number_format($resource['total_quantity'], 2); ?></div>
                            <div class="treasury-category"><?php echo htmlspecialchars($resource['category']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Withdrawal Form -->
            <div class="withdrawal-form">
                <h4>Withdraw from Treasury</h4>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="withdraw_treasury">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="resource_id">Resource</label>
                            <select name="resource_id" id="resource_id" class="form-control" required>
                                <option value="">Select resource...</option>
                                <?php foreach ($all_resources as $resource): ?>
                                    <option value="<?php echo $resource['id']; ?>">
                                        <?php echo htmlspecialchars($resource['name']); ?> 
                                        (<?php echo htmlspecialchars($resource['category']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="quantity">Quantity</label>
                            <input type="number" 
                                   name="quantity" 
                                   id="quantity" 
                                   class="form-control" 
                                   min="0.01" 
                                   step="0.01" 
                                   required>
                        </div>
                        
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="reason">Reason</label>
                            <input type="text" 
                                   name="reason" 
                                   id="reason" 
                                   class="form-control" 
                                   placeholder="e.g., Guild event prizes, crafting materials..." 
                                   required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Withdraw Resources</button>
                </form>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="settings-section">
            <h3>Recent Treasury Activity</h3>
            
            <?php if (empty($recent_activity)): ?>
                <p style="color: var(--text-secondary);">No treasury activity yet.</p>
            <?php else: ?>
                <div class="activity-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Resource</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>User</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity as $activity): ?>
                                <tr>
                                    <td><?php echo date('M d, H:i', strtotime($activity['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($activity['resource_name']); ?></td>
                                    <td class="<?php echo $activity['quantity'] > 0 ? 'activity-positive' : 'activity-negative'; ?>">
                                        <?php echo $activity['quantity'] > 0 ? '+' : ''; ?>
                                        <?php echo number_format($activity['quantity'], 2); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($activity['source_type']); ?></td>
                                    <td><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></td>
                                    <td><?php echo htmlspecialchars($activity['notes'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>