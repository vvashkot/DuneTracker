<?php
/**
 * Admin Panel - Contributions Management
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Handle contribution updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    switch ($_POST['action']) {
        case 'update_contribution':
            $contribution_id = intval($_POST['contribution_id']);
            $new_quantity = intval($_POST['quantity']);
            $reason = trim($_POST['reason'] ?? '');
            
            if ($new_quantity >= 0) {
                $db = getDB();
                
                // Get original contribution details
                $stmt = $db->prepare("
                    SELECT c.*, COALESCE(u.in_game_name, u.username) as username, r.name as resource_name 
                    FROM contributions c
                    JOIN users u ON c.user_id = u.id
                    JOIN resources r ON c.resource_id = r.id
                    WHERE c.id = ?
                ");
                $stmt->execute([$contribution_id]);
                $original = $stmt->fetch();
                
                if ($original) {
                    // Update the contribution
                    $stmt = $db->prepare("UPDATE contributions SET quantity = ? WHERE id = ?");
                    $stmt->execute([$new_quantity, $contribution_id]);
                    
                    // Log the change
                    logActivity($user['db_id'], 'Updated contribution', 
                        "ID: $contribution_id, User: {$original['username']}, Resource: {$original['resource_name']}, " .
                        "Changed from {$original['quantity']} to $new_quantity" . 
                        ($reason ? ", Reason: $reason" : ""), 
                        $_SERVER['REMOTE_ADDR']);
                    
                    $message = "Contribution updated successfully";
                    $message_type = 'success';
                }
            } else {
                $message = "Invalid quantity";
                $message_type = 'error';
            }
            break;
            
        case 'delete_contribution':
            $contribution_id = intval($_POST['contribution_id']);
            $reason = trim($_POST['reason'] ?? '');
            
            $db = getDB();
            
            // Get contribution details before deletion
            $stmt = $db->prepare("
                SELECT c.*, COALESCE(u.in_game_name, u.username) as username, r.name as resource_name 
                FROM contributions c
                JOIN users u ON c.user_id = u.id
                JOIN resources r ON c.resource_id = r.id
                WHERE c.id = ?
            ");
            $stmt->execute([$contribution_id]);
            $original = $stmt->fetch();
            
            if ($original) {
                // Delete the contribution
                $stmt = $db->prepare("DELETE FROM contributions WHERE id = ?");
                $stmt->execute([$contribution_id]);
                
                // Log the deletion
                logActivity($user['db_id'], 'Deleted contribution', 
                    "ID: $contribution_id, User: {$original['username']}, Resource: {$original['resource_name']}, " .
                    "Quantity: {$original['quantity']}" . 
                    ($reason ? ", Reason: $reason" : ""), 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = "Contribution deleted successfully";
                $message_type = 'success';
            }
            break;
            
        case 'adjust_total':
            $resource_id = intval($_POST['resource_id']);
            $adjustment = intval($_POST['adjustment']);
            $reason = trim($_POST['reason'] ?? '');
            
            if ($adjustment != 0 && $reason) {
                $db = getDB();
                
                // Create a system adjustment contribution
                // First, get or create a system user
                $stmt = $db->prepare("SELECT id FROM users WHERE username = 'System' AND is_manual = TRUE");
                $stmt->execute();
                $system_user_id = $stmt->fetchColumn();
                
                if (!$system_user_id) {
                    $stmt = $db->prepare("INSERT INTO users (username, is_manual, role, approval_status) VALUES ('System', TRUE, 'member', 'approved')");
                    $stmt->execute();
                    $system_user_id = $db->lastInsertId();
                }
                
                // Add the adjustment as a contribution
                $stmt = $db->prepare("INSERT INTO contributions (user_id, resource_id, quantity, notes, date_collected) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$system_user_id, $resource_id, $adjustment, "Admin adjustment: $reason"]);
                
                // Get resource name for logging
                $stmt = $db->prepare("SELECT name FROM resources WHERE id = ?");
                $stmt->execute([$resource_id]);
                $resource_name = $stmt->fetchColumn();
                
                // Log the adjustment
                logActivity($user['db_id'], 'Adjusted resource total', 
                    "Resource: $resource_name, Adjustment: " . ($adjustment > 0 ? "+$adjustment" : "$adjustment") . 
                    ", Reason: $reason", 
                    $_SERVER['REMOTE_ADDR']);
                
                // Send webhook notification
                require_once '../includes/webhooks.php';
                $resource = ['name' => $resource_name];
                notifyAdminAdjustment($user, $resource, $adjustment, $reason);
                
                $message = "Resource total adjusted successfully";
                $message_type = 'success';
            } else {
                $message = "Please provide both an adjustment amount and reason";
                $message_type = 'error';
            }
            break;
    }
}

// Get filter parameters
$filter_user = $_GET['user'] ?? '';
$filter_resource = $_GET['resource'] ?? '';
$filter_date = $_GET['date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build query
$where_clauses = ["u.merged_into_user_id IS NULL"];
$params = [];

if ($filter_user) {
    $where_clauses[] = "u.username LIKE ?";
    $params[] = "%$filter_user%";
}

if ($filter_resource) {
    $where_clauses[] = "r.id = ?";
    $params[] = $filter_resource;
}

if ($filter_date) {
    $where_clauses[] = "DATE(c.date_collected) = ?";
    $params[] = $filter_date;
}

$where_sql = implode(" AND ", $where_clauses);

// Get total count
$db = getDB();
$count_sql = "
    SELECT COUNT(*) 
    FROM contributions c
    JOIN users u ON c.user_id = u.id
    JOIN resources r ON c.resource_id = r.id
    WHERE $where_sql
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_count = $stmt->fetchColumn();
$total_pages = ceil($total_count / $per_page);

// Get contributions
$sql = "
    SELECT 
        c.*,
        u.username,
        u.avatar,
        u.discord_id,
        u.is_manual,
        r.name as resource_name,
        r.category as resource_category
    FROM contributions c
    JOIN users u ON c.user_id = u.id
    JOIN resources r ON c.resource_id = r.id
    WHERE $where_sql
    ORDER BY c.date_collected DESC
    LIMIT ? OFFSET ?
";
$params[] = $per_page;
$params[] = $offset;

    $stmt = $db->prepare($sql);
$stmt->execute($params);
$contributions = $stmt->fetchAll();

// Get all resources for filter dropdown
$resources = getAllResources();

// Get resource totals
$totals_sql = "
    SELECT 
        r.id,
        r.name,
        r.category,
        SUM(c.quantity) as total_quantity,
        COUNT(DISTINCT c.user_id) as contributor_count
    FROM resources r
    LEFT JOIN contributions c ON r.id = c.resource_id
    GROUP BY r.id, r.name, r.category
    ORDER BY r.category, r.name
";
$resource_totals = $db->query($totals_sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contributions Management - Admin Panel</title>
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
        
        .filter-section {
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
            align-items: end;
        }
        
        .contributions-table {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .edit-form {
            display: none;
            padding: 0.5rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            margin-top: 0.5rem;
        }
        
        .edit-form.active {
            display: block;
        }
        
        .edit-form input,
        .edit-form textarea {
            margin-right: 0.5rem;
        }
        
        .totals-section {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-top: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .totals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .total-card {
            background-color: var(--bg-tertiary);
            padding: 1rem;
            border-radius: var(--radius-sm);
        }
        
        .total-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .total-name {
            font-weight: 600;
        }
        
        .total-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .total-meta {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .adjust-form {
            display: none;
            margin-top: 1rem;
            padding: 1rem;
            background-color: var(--bg-secondary);
            border-radius: var(--radius-sm);
        }
        
        .adjust-form.active {
            display: block;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            text-decoration: none;
        }
        
        .pagination .active {
            background-color: var(--primary-color);
            color: var(--bg-dark);
            border-color: var(--primary-color);
        }
        
        .pagination a:hover {
            border-color: var(--primary-color);
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
            <h2>Contributions Management</h2>
            <div class="header-actions">
                <a href="/admin/" class="btn btn-secondary">Back to Admin</a>
            </div>
        </div>

        <nav class="admin-nav">
            <ul>
                <li><a href="/admin/">📊 Dashboard</a></li>
                <li><a href="/admin/users.php">👥 Users</a></li>
                <li><a href="/admin/approvals.php">✅ Approvals</a></li>
                <li><a href="/admin/contributions.php" class="active">💰 Contributions</a></li>
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

        <!-- Filter Section -->
        <div class="filter-section">
            <h3>Filter Contributions</h3>
            <form method="GET" class="filter-grid">
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
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="date">Date</label>
                    <input type="date" 
                           name="date" 
                           id="date" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>
                
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="/admin/contributions.php" class="btn btn-secondary">Clear</a>
            </form>
        </div>

        <!-- Contributions Table -->
        <div class="contributions-table">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>User</th>
                        <th>Resource</th>
                        <th>Quantity</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contributions as $contribution): ?>
                        <tr>
                            <td>#<?php echo $contribution['id']; ?></td>
                            <td><?php echo date('M d, Y H:i', strtotime($contribution['date_collected'])); ?></td>
                            <td>
                                <div class="user-info">
                                    <img src="<?php echo htmlspecialchars(getAvatarUrl($contribution)); ?>" 
                                         alt="Avatar" 
                                         class="small-avatar">
                                    <span>
                                        <?php echo htmlspecialchars($contribution['username']); ?>
                                        <?php if ($contribution['is_manual']): ?>
                                            <small style="color: var(--text-secondary);">(Manual)</small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($contribution['resource_name']); ?>
                                <small style="color: var(--text-secondary);">
                                    (<?php echo htmlspecialchars($contribution['resource_category']); ?>)
                                </small>
                            </td>
                            <td class="quantity"><?php echo number_format($contribution['quantity']); ?></td>
                            <td class="notes"><?php echo htmlspecialchars($contribution['notes'] ?? '-'); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-secondary btn-sm" 
                                            onclick="toggleEditForm(<?php echo $contribution['id']; ?>)">
                                        Edit
                                    </button>
                                    <button class="btn btn-danger btn-sm" 
                                            onclick="toggleDeleteForm(<?php echo $contribution['id']; ?>)">
                                        Delete
                                    </button>
                                </div>
                                
                                <!-- Edit Form -->
                                <div id="edit-form-<?php echo $contribution['id']; ?>" class="edit-form">
                                    <form method="POST">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="update_contribution">
                                        <input type="hidden" name="contribution_id" value="<?php echo $contribution['id']; ?>">
                                        
                                        <div class="form-group">
                                            <label>New Quantity</label>
                                            <input type="number" 
                                                   name="quantity" 
                                                   class="form-control" 
                                                   value="<?php echo $contribution['quantity']; ?>" 
                                                   min="0" 
                                                   required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label>Reason for Change</label>
                                            <input type="text" 
                                                   name="reason" 
                                                   class="form-control" 
                                                   placeholder="e.g., Correction, duplicate entry">
                                        </div>
                                        
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                            <button type="button" 
                                                    class="btn btn-secondary btn-sm" 
                                                    onclick="toggleEditForm(<?php echo $contribution['id']; ?>)">
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Delete Form -->
                                <div id="delete-form-<?php echo $contribution['id']; ?>" class="edit-form">
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this contribution?');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_contribution">
                                        <input type="hidden" name="contribution_id" value="<?php echo $contribution['id']; ?>">
                                        
                                        <div class="form-group">
                                            <label>Reason for Deletion</label>
                                            <input type="text" 
                                                   name="reason" 
                                                   class="form-control" 
                                                   placeholder="e.g., Duplicate entry, error">
                                        </div>
                                        
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            <button type="button" 
                                                    class="btn btn-secondary btn-sm" 
                                                    onclick="toggleDeleteForm(<?php echo $contribution['id']; ?>)">
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">← Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Resource Totals Section -->
        <div class="totals-section">
            <h3>Resource Totals & Adjustments</h3>
            <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                Make manual adjustments to resource totals. These will be recorded as system contributions.
            </p>
            
            <div class="totals-grid">
                <?php foreach ($resource_totals as $total): ?>
                    <div class="total-card">
                        <div class="total-header">
                            <span class="total-name"><?php echo htmlspecialchars($total['name']); ?></span>
                            <button class="btn btn-secondary btn-sm" 
                                    onclick="toggleAdjustForm(<?php echo $total['id']; ?>)">
                                Adjust
                            </button>
                        </div>
                        <div class="total-value"><?php echo number_format($total['total_quantity'] ?? 0); ?></div>
                        <div class="total-meta">
                            <?php echo $total['contributor_count'] ?? 0; ?> contributors • 
                            <?php echo htmlspecialchars($total['category']); ?>
                        </div>
                        
                        <!-- Adjustment Form -->
                        <div id="adjust-form-<?php echo $total['id']; ?>" class="adjust-form">
                            <form method="POST">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="adjust_total">
                                <input type="hidden" name="resource_id" value="<?php echo $total['id']; ?>">
                                
                                <div class="form-group">
                                    <label>Adjustment Amount</label>
                                    <input type="number" 
                                           name="adjustment" 
                                           class="form-control" 
                                           placeholder="e.g., -100 or +50" 
                                           required>
                                    <small class="form-help">Use negative numbers to reduce, positive to increase</small>
                                </div>
                                
                                <div class="form-group">
                                    <label>Reason</label>
                                    <input type="text" 
                                           name="reason" 
                                           class="form-control" 
                                           placeholder="e.g., Inventory correction, lost resources" 
                                           required>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary btn-sm">Apply Adjustment</button>
                                    <button type="button" 
                                            class="btn btn-secondary btn-sm" 
                                            onclick="toggleAdjustForm(<?php echo $total['id']; ?>)">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        function toggleEditForm(id) {
            const form = document.getElementById('edit-form-' + id);
            const deleteForm = document.getElementById('delete-form-' + id);
            
            // Close delete form if open
            if (deleteForm) {
                deleteForm.classList.remove('active');
            }
            
            form.classList.toggle('active');
        }
        
        function toggleDeleteForm(id) {
            const form = document.getElementById('delete-form-' + id);
            const editForm = document.getElementById('edit-form-' + id);
            
            // Close edit form if open
            if (editForm) {
                editForm.classList.remove('active');
            }
            
            form.classList.toggle('active');
        }
        
        function toggleAdjustForm(id) {
            const form = document.getElementById('adjust-form-' + id);
            form.classList.toggle('active');
        }
    </script>
</body>
</html>