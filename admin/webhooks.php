<?php
/**
 * Admin Panel - Discord Webhook Management
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/webhooks.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Handle webhook operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    switch ($_POST['action']) {
        case 'create_webhook':
            $name = trim($_POST['name']);
            $webhook_url = trim($_POST['webhook_url']);
            $event_types = $_POST['event_types'] ?? [];
            
            if ($name && $webhook_url && filter_var($webhook_url, FILTER_VALIDATE_URL)) {
                $db = getDB();
                $stmt = $db->prepare("
                    INSERT INTO webhook_configs (name, webhook_url, event_types, created_by)
                    VALUES (?, ?, ?, ?)
                ");
                
                $event_types_json = !empty($event_types) ? json_encode($event_types) : null;
                $stmt->execute([$name, $webhook_url, $event_types_json, $user['db_id']]);
                
                logActivity($user['db_id'], 'Created webhook', 
                    "Name: $name", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = "Webhook created successfully";
                $message_type = 'success';
            } else {
                $message = "Please provide a valid name and webhook URL";
                $message_type = 'error';
            }
            break;
            
        case 'update_webhook':
            $webhook_id = intval($_POST['webhook_id']);
            $name = trim($_POST['name']);
            $webhook_url = trim($_POST['webhook_url']);
            $event_types = $_POST['event_types'] ?? [];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if ($name && $webhook_url && filter_var($webhook_url, FILTER_VALIDATE_URL)) {
                $db = getDB();
                $stmt = $db->prepare("
                    UPDATE webhook_configs 
                    SET name = ?, webhook_url = ?, event_types = ?, is_active = ?
                    WHERE id = ?
                ");
                
                $event_types_json = !empty($event_types) ? json_encode($event_types) : null;
                $stmt->execute([$name, $webhook_url, $event_types_json, $is_active, $webhook_id]);
                
                logActivity($user['db_id'], 'Updated webhook', 
                    "ID: $webhook_id, Name: $name", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = "Webhook updated successfully";
                $message_type = 'success';
            }
            break;
            
        case 'delete_webhook':
            $webhook_id = intval($_POST['webhook_id']);
            
            $db = getDB();
            $stmt = $db->prepare("DELETE FROM webhook_configs WHERE id = ?");
            $stmt->execute([$webhook_id]);
            
            logActivity($user['db_id'], 'Deleted webhook', 
                "ID: $webhook_id", 
                $_SERVER['REMOTE_ADDR']);
            
            $message = "Webhook deleted successfully";
            $message_type = 'success';
            break;
            
        case 'test_webhook':
            $webhook_id = intval($_POST['webhook_id']);
            
            // Send a test notification
            $test_embed = [
                'title' => '🧪 Test Notification',
                'description' => 'This is a test notification from ' . APP_NAME . '.',
                'fields' => [
                    [
                        'name' => 'Status',
                        'value' => '✅ Webhook is working correctly!',
                        'inline' => false
                    ],
                    [
                        'name' => 'Timestamp',
                        'value' => date('M d, Y H:i:s'),
                        'inline' => true
                    ]
                ],
                'color' => 3447003 // Blue
            ];
            
            // Get specific webhook
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM webhook_configs WHERE id = ?");
            $stmt->execute([$webhook_id]);
            $webhook = $stmt->fetch();
            
            if ($webhook) {
                // Temporarily set only this webhook as active for testing
                $original_webhooks = $db->query("SELECT * FROM webhook_configs WHERE is_active = TRUE")->fetchAll();
                $db->exec("UPDATE webhook_configs SET is_active = FALSE");
                $db->exec("UPDATE webhook_configs SET is_active = TRUE WHERE id = $webhook_id");
                
                $result = sendDiscordWebhook('test', $test_embed, "Test notification from " . APP_NAME);
                
                // Restore original active states
                foreach ($original_webhooks as $w) {
                    if ($w['is_active']) {
                        $db->exec("UPDATE webhook_configs SET is_active = TRUE WHERE id = {$w['id']}");
                    }
                }
                
                if ($result > 0) {
                    $message = "Test notification sent successfully!";
                    $message_type = 'success';
                } else {
                    $message = "Failed to send test notification. Check webhook URL and logs.";
                    $message_type = 'error';
                }
            }
            break;
    }
}

// Get all webhooks
$db = getDB();
$webhooks = $db->query("
    SELECT wc.*, u.username as creator_name
    FROM webhook_configs wc
    LEFT JOIN users u ON wc.created_by = u.id
    ORDER BY wc.created_at DESC
")->fetchAll();

// Get recent webhook logs
$recent_logs = $db->query("
    SELECT wl.*, wc.name as webhook_name
    FROM webhook_logs wl
    JOIN webhook_configs wc ON wl.webhook_config_id = wc.id
    ORDER BY wl.sent_at DESC
    LIMIT 50
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Webhooks - Admin Panel</title>
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
        
        .webhook-grid {
            display: grid;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .webhook-card {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .webhook-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        
        .webhook-title {
            font-size: 1.125rem;
            font-weight: 600;
        }
        
        .webhook-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .webhook-status.active {
            background-color: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }
        
        .webhook-status.inactive {
            background-color: rgba(220, 53, 69, 0.2);
            color: var(--danger-color);
        }
        
        .webhook-url {
            font-family: monospace;
            font-size: 0.875rem;
            color: var(--text-secondary);
            word-break: break-all;
            margin-bottom: 1rem;
        }
        
        .event-types {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .event-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
        }
        
        .create-form {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .event-checkboxes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 0.75rem;
            margin-top: 0.5rem;
        }
        
        .event-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .logs-section {
            margin-top: 3rem;
        }
        
        .log-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            margin-bottom: 0.5rem;
        }
        
        .log-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .log-status.success {
            background-color: var(--success-color);
        }
        
        .log-status.failed {
            background-color: var(--danger-color);
        }
        
        .edit-form {
            display: none;
            margin-top: 1rem;
            padding: 1rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
        }
        
        .edit-form.active {
            display: block;
        }
        
        .webhook-help {
            background-color: var(--bg-tertiary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .webhook-help h4 {
            margin-bottom: 1rem;
        }
        
        .webhook-help ol {
            margin-left: 1.5rem;
            color: var(--text-secondary);
        }
        
        .webhook-help li {
            margin-bottom: 0.5rem;
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
            <h2>Discord Webhooks</h2>
            <div class="header-actions">
                <a href="/admin/" class="btn btn-secondary">Back to Admin</a>
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
                <li><a href="/admin/webhooks.php" class="active">🔔 Webhooks</a></li>
                <li><a href="/admin/backup.php">💾 Backup</a></li>
            </ul>
        </nav>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="webhook-help">
            <h4>📖 How to Create a Discord Webhook</h4>
            <ol>
                <li>Go to your Discord server settings</li>
                <li>Navigate to "Integrations" → "Webhooks"</li>
                <li>Click "New Webhook"</li>
                <li>Choose a channel and name for the webhook</li>
                <li>Copy the webhook URL and paste it below</li>
            </ol>
        </div>

        <div class="create-form">
            <h3>Create New Webhook</h3>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create_webhook">
                
                <div class="form-group">
                    <label for="name">Webhook Name</label>
                    <input type="text" 
                           name="name" 
                           id="name" 
                           class="form-control" 
                           placeholder="e.g., Main Channel Notifications"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="webhook_url">Discord Webhook URL</label>
                    <input type="url" 
                           name="webhook_url" 
                           id="webhook_url" 
                           class="form-control" 
                           placeholder="https://discord.com/api/webhooks/..."
                           required>
                </div>
                
                <div class="form-group">
                    <label>Event Types (leave empty for all events)</label>
                    <div class="event-checkboxes">
                        <?php foreach (WEBHOOK_EVENTS as $event => $label): ?>
                            <label class="event-checkbox">
                                <input type="checkbox" 
                                       name="event_types[]" 
                                       value="<?php echo $event; ?>">
                                <?php echo $label; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Create Webhook</button>
            </form>
        </div>

        <h3>Configured Webhooks</h3>
        <div class="webhook-grid">
            <?php if (empty($webhooks)): ?>
                <div class="empty-state">
                    <p>No webhooks configured yet. Create one above!</p>
                </div>
            <?php else: ?>
                <?php foreach ($webhooks as $webhook): ?>
                    <div class="webhook-card">
                        <div class="webhook-header">
                            <div>
                                <div class="webhook-title"><?php echo htmlspecialchars($webhook['name']); ?></div>
                                <div class="webhook-status <?php echo $webhook['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $webhook['is_active'] ? 'Active' : 'Inactive'; ?>
                                </div>
                            </div>
                            <div>
                                <button class="btn btn-secondary btn-sm" 
                                        onclick="toggleEdit(<?php echo $webhook['id']; ?>)">
                                    Edit
                                </button>
                                <form method="POST" style="display: inline;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="test_webhook">
                                    <input type="hidden" name="webhook_id" value="<?php echo $webhook['id']; ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">Test</button>
                                </form>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Delete this webhook?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete_webhook">
                                    <input type="hidden" name="webhook_id" value="<?php echo $webhook['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="webhook-url"><?php echo htmlspecialchars($webhook['webhook_url']); ?></div>
                        
                        <?php if ($webhook['event_types']): ?>
                            <div class="event-types">
                                <?php 
                                $events = json_decode($webhook['event_types'], true);
                                foreach ($events as $event): 
                                ?>
                                    <span class="event-badge">
                                        <?php echo WEBHOOK_EVENTS[$event] ?? $event; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="color: var(--text-secondary); font-size: 0.875rem;">
                                Receives all event types
                            </p>
                        <?php endif; ?>
                        
                        <div style="font-size: 0.875rem; color: var(--text-secondary);">
                            Created by <?php echo htmlspecialchars($webhook['creator_name'] ?? 'Unknown'); ?> • 
                            <?php echo date('M d, Y', strtotime($webhook['created_at'])); ?>
                        </div>
                        
                        <!-- Edit Form -->
                        <div id="edit-<?php echo $webhook['id']; ?>" class="edit-form">
                            <form method="POST">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="update_webhook">
                                <input type="hidden" name="webhook_id" value="<?php echo $webhook['id']; ?>">
                                
                                <div class="form-group">
                                    <label>Name</label>
                                    <input type="text" 
                                           name="name" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars($webhook['name']); ?>"
                                           required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Webhook URL</label>
                                    <input type="url" 
                                           name="webhook_url" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars($webhook['webhook_url']); ?>"
                                           required>
                                </div>
                                
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" 
                                               name="is_active" 
                                               value="1"
                                               <?php echo $webhook['is_active'] ? 'checked' : ''; ?>>
                                        Active
                                    </label>
                                </div>
                                
                                <div class="form-group">
                                    <label>Event Types</label>
                                    <div class="event-checkboxes">
                                        <?php 
                                        $current_events = $webhook['event_types'] ? json_decode($webhook['event_types'], true) : [];
                                        foreach (WEBHOOK_EVENTS as $event => $label): 
                                        ?>
                                            <label class="event-checkbox">
                                                <input type="checkbox" 
                                                       name="event_types[]" 
                                                       value="<?php echo $event; ?>"
                                                       <?php echo in_array($event, $current_events) ? 'checked' : ''; ?>>
                                                <?php echo $label; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Update</button>
                                    <button type="button" 
                                            class="btn btn-secondary" 
                                            onclick="toggleEdit(<?php echo $webhook['id']; ?>)">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="logs-section">
            <h3>Recent Webhook Activity</h3>
            <?php if (empty($recent_logs)): ?>
                <p style="color: var(--text-secondary);">No webhook activity yet.</p>
            <?php else: ?>
                <div class="webhook-logs">
                    <?php foreach ($recent_logs as $log): ?>
                        <div class="log-item">
                            <div class="log-status <?php echo $log['status']; ?>"></div>
                            <div style="flex: 1;">
                                <strong><?php echo htmlspecialchars($log['webhook_name']); ?></strong> • 
                                <?php echo WEBHOOK_EVENTS[$log['event_type']] ?? $log['event_type']; ?> • 
                                <?php echo date('M d, H:i', strtotime($log['sent_at'])); ?>
                                <?php if ($log['error_message']): ?>
                                    <div style="color: var(--danger-color); font-size: 0.875rem;">
                                        Error: <?php echo htmlspecialchars($log['error_message']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleEdit(id) {
            const form = document.getElementById('edit-' + id);
            form.classList.toggle('active');
        }
    </script>
</body>
</html>