<?php
/**
 * Admin Panel - Resource Management
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Handle resource operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    $db = getDB();
    
    switch ($_POST['action']) {
        case 'add_resource':
            $name = trim($_POST['name']);
            $category = $_POST['category'];
            
            try {
                $stmt = $db->prepare("INSERT INTO resources (name, category) VALUES (?, ?)");
                $stmt->execute([$name, $category]);
                
                logActivity($user['db_id'], 'Added resource', 
                    "Resource: $name, Category: $category", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = 'Resource added successfully';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Failed to add resource: ' . (strpos($e->getMessage(), 'Duplicate') !== false ? 'Resource already exists' : 'Database error');
                $message_type = 'error';
            }
            break;
            
        case 'update_resource':
            $id = intval($_POST['resource_id']);
            $name = trim($_POST['name']);
            $category = $_POST['category'];
            
            try {
                $stmt = $db->prepare("UPDATE resources SET name = ?, category = ? WHERE id = ?");
                $stmt->execute([$name, $category, $id]);
                
                logActivity($user['db_id'], 'Updated resource', 
                    "Resource ID: $id, Name: $name", 
                    $_SERVER['REMOTE_ADDR']);
                
                $message = 'Resource updated successfully';
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = 'Failed to update resource';
                $message_type = 'error';
            }
            break;
            
        case 'delete_resource':
            $id = intval($_POST['resource_id']);
            
            // Check if resource has any contributions
            $stmt = $db->prepare("SELECT COUNT(*) FROM contributions WHERE resource_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $message = 'Cannot delete resource with existing contributions';
                $message_type = 'error';
            } else {
                try {
                    $stmt = $db->prepare("DELETE FROM resources WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    logActivity($user['db_id'], 'Deleted resource', 
                        "Resource ID: $id", 
                        $_SERVER['REMOTE_ADDR']);
                    
                    $message = 'Resource deleted successfully';
                    $message_type = 'success';
                } catch (PDOException $e) {
                    $message = 'Failed to delete resource';
                    $message_type = 'error';
                }
            }
            break;
    }
}

// Get all resources with stats
$db = getDB();
$resources = $db->query("
    SELECT 
        r.*,
        COUNT(DISTINCT c.id) as contribution_count,
        COALESCE(SUM(c.quantity), 0) as total_quantity
    FROM resources r
    LEFT JOIN contributions c ON r.id = c.resource_id
    GROUP BY r.id
    ORDER BY r.category, r.name
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource Management - Admin Panel</title>
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
        
        .resource-grid {
            display: grid;
            gap: 2rem;
        }
        
        .add-resource-form {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }
        
        .resource-table {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .resource-table table {
            width: 100%;
        }
        
        .resource-table th {
            background-color: var(--bg-tertiary);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .resource-table td {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .resource-table tr:hover td {
            background-color: var(--bg-tertiary);
        }
        
        .category-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .category-badge.raw {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--success-color);
        }
        
        .category-badge.refined {
            background-color: rgba(75, 139, 245, 0.2);
            color: var(--primary-color);
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }
        
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            border: 1px solid var(--border-color);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .close-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .form-row {
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
            <h2>Resource Management</h2>
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
                <li><a href="/admin/resources.php" class="active">📦 Resources</a></li>
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

        <div class="add-resource-form">
            <h3>Add New Resource</h3>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_resource">
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Resource Name</label>
                        <input type="text" name="name" id="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select name="category" id="category" class="form-control" required>
                            <option value="Raw Materials">Raw Materials</option>
                            <option value="Refined">Refined</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Resource</button>
                </div>
            </form>
        </div>

        <div class="resource-table">
            <table>
                <thead>
                    <tr>
                        <th>Resource Name</th>
                        <th>Category</th>
                        <th>Total Contributions</th>
                        <th>Total Quantity</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resources as $resource): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($resource['name']); ?></td>
                            <td>
                                <span class="category-badge <?php echo $resource['category'] === 'Raw Materials' ? 'raw' : 'refined'; ?>">
                                    <?php echo htmlspecialchars($resource['category']); ?>
                                </span>
                            </td>
                            <td><?php echo number_format($resource['contribution_count']); ?></td>
                            <td><?php echo number_format($resource['total_quantity']); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button onclick="editResource(<?php echo htmlspecialchars(json_encode($resource)); ?>)" 
                                            class="btn btn-secondary btn-sm">Edit</button>
                                    <?php if ($resource['contribution_count'] == 0): ?>
                                        <form method="POST" style="display: inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_resource">
                                            <input type="hidden" name="resource_id" value="<?php echo $resource['id']; ?>">
                                            <button type="submit" 
                                                    class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Are you sure you want to delete this resource?')">
                                                Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Resource</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_resource">
                <input type="hidden" name="resource_id" id="edit_resource_id">
                
                <div class="form-group">
                    <label for="edit_name">Resource Name</label>
                    <input type="text" name="name" id="edit_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_category">Category</label>
                    <select name="category" id="edit_category" class="form-control" required>
                        <option value="Raw Materials">Raw Materials</option>
                        <option value="Refined">Refined</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function editResource(resource) {
            document.getElementById('edit_resource_id').value = resource.id;
            document.getElementById('edit_name').value = resource.name;
            document.getElementById('edit_category').value = resource.category;
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>