<?php
/**
 * Withdraw Resources - Log resource withdrawals from guild bank
 */

require_once 'includes/auth.php';
require_once 'includes/db.php';

// Require login
requireLogin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Get available resources
$db = getDB();
$available_resources = $db->query("
    SELECT 
        r.*,
        r.current_stock - r.total_withdrawn as available_stock
    FROM resources r
    WHERE r.current_stock - r.total_withdrawn > 0
    ORDER BY r.category, r.name
")->fetchAll();

// Get withdrawal purposes from settings
$stmt = $db->prepare("SELECT setting_value FROM guild_settings WHERE setting_key = 'withdrawal_purposes'");
$stmt->execute();
$purposes_json = $stmt->fetchColumn();
$withdrawal_purposes = json_decode($purposes_json ?: '[]', true);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyPOST();
    
    $resource_id = intval($_POST['resource_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validate inputs
    $errors = [];
    
    if (!$resource_id) {
        $errors[] = 'Please select a resource.';
    }
    
    if ($quantity <= 0) {
        $errors[] = 'Please enter a valid quantity greater than 0.';
    }
    
    if (empty($purpose)) {
        $errors[] = 'Please specify the purpose of withdrawal.';
    }
    
    if (empty($errors)) {
        // Check available quantity
        $stmt = $db->prepare("
            SELECT name, current_stock - total_withdrawn as available 
            FROM resources 
            WHERE id = ?
        ");
        $stmt->execute([$resource_id]);
        $resource = $stmt->fetch();
        
        if (!$resource) {
            $errors[] = 'Invalid resource selected.';
        } elseif ($quantity > $resource['available']) {
            $errors[] = "Only " . number_format($resource['available'], 2) . " " . $resource['name'] . " available.";
        }
    }
    
    if (empty($errors)) {
        try {
            // Record withdrawal
            $stmt = $db->prepare("
                INSERT INTO withdrawals (user_id, resource_id, quantity, purpose, notes, approval_status, approved_at)
                VALUES (?, ?, ?, ?, ?, 'approved', NOW())
            ");
            $stmt->execute([$user['db_id'], $resource_id, $quantity, $purpose, $notes]);
            
            // Log activity
            logActivity($user['db_id'], 'Withdrew resources', 
                "Resource: {$resource['name']}, Quantity: $quantity, Purpose: $purpose", 
                $_SERVER['REMOTE_ADDR']);
            
            $message = "Successfully withdrew " . number_format($quantity, 2) . " " . $resource['name'];
            $message_type = 'success';
            
            // Refresh available resources
            $available_resources = $db->query("
                SELECT 
                    r.*,
                    r.current_stock - r.total_withdrawn as available_stock
                FROM resources r
                WHERE r.current_stock - r.total_withdrawn > 0
                ORDER BY r.category, r.name
            ")->fetchAll();
        } catch (PDOException $e) {
            $message = "Failed to record withdrawal: " . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = implode(' ', $errors);
        $message_type = 'error';
    }
}

// Get user's recent withdrawals
$stmt = $db->prepare("
    SELECT 
        w.*,
        r.name as resource_name,
        r.category as resource_category
    FROM withdrawals w
    JOIN resources r ON w.resource_id = r.id
    WHERE w.user_id = ?
    ORDER BY w.created_at DESC
    LIMIT 10
");
$stmt->execute([$user['db_id']]);
$recent_withdrawals = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdraw Resources - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <style>
        .withdraw-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .form-container {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .available-resources {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 2rem;
            border: 1px solid var(--border-color);
            max-height: 600px;
            overflow-y: auto;
        }
        
        .resource-group {
            margin-bottom: 2rem;
        }
        
        .resource-group h4 {
            color: var(--text-secondary);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
        }
        
        .resource-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .resource-item:hover {
            background-color: var(--bg-input);
            border: 1px solid var(--primary-color);
            padding: calc(0.75rem - 1px);
        }
        
        .resource-item.selected {
            background-color: var(--primary-color);
            color: var(--bg-dark);
        }
        
        .resource-name {
            font-weight: 500;
        }
        
        .resource-quantity {
            font-weight: 700;
        }
        
        .recent-withdrawals {
            margin-top: 2rem;
        }
        
        .withdrawal-item {
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            gap: 1rem;
            align-items: center;
            padding: 1rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            margin-bottom: 0.5rem;
        }
        
        .withdrawal-date {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .withdrawal-details {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .withdrawal-resource {
            font-weight: 600;
        }
        
        .withdrawal-purpose {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .withdrawal-quantity {
            font-weight: 700;
            color: var(--danger-color);
        }
        
        .quantity-preview {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .preview-available {
            color: var(--success-color);
        }
        
        .preview-remaining {
            color: var(--warning-color);
        }
        
        @media (max-width: 768px) {
            .withdraw-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <h1 class="nav-title"><?php echo htmlspecialchars(APP_NAME); ?></h1>
            <div class="nav-user">
                <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" alt="Avatar" class="user-avatar">
                <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
                <a href="/settings.php" class="btn btn-secondary">Settings</a>
                <a href="/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h2>Withdraw Resources</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="withdraw-container">
            <div>
                <div class="form-container">
                    <h3>Record Withdrawal</h3>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="resource_id" id="resource_id" value="">
                        
                        <div class="form-group">
                            <label>Selected Resource</label>
                            <div id="selected-resource" style="padding: 1rem; background-color: var(--bg-tertiary); border-radius: var(--radius-sm); color: var(--text-secondary);">
                                Click a resource from the list to select
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="quantity">Quantity</label>
                            <input type="number" 
                                   name="quantity" 
                                   id="quantity" 
                                   class="form-control" 
                                   step="0.01" 
                                   min="0.01"
                                   required
                                   disabled>
                            <div id="quantity-preview" class="quantity-preview" style="display: none;">
                                <span class="preview-available">Available: <span id="available-amount">0</span></span>
                                <span>→</span>
                                <span class="preview-remaining">Remaining: <span id="remaining-amount">0</span></span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="purpose">Purpose</label>
                            <select name="purpose" id="purpose" class="form-control" required disabled>
                                <option value="">Select purpose...</option>
                                <?php foreach ($withdrawal_purposes as $purpose): ?>
                                    <option value="<?php echo htmlspecialchars($purpose); ?>">
                                        <?php echo htmlspecialchars($purpose); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Notes (Optional)</label>
                            <textarea name="notes" 
                                      id="notes" 
                                      class="form-control" 
                                      rows="3" 
                                      placeholder="Additional details about this withdrawal..."
                                      disabled></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" id="submit-btn" disabled>
                            Record Withdrawal
                        </button>
                    </form>
                </div>
                
                <div class="recent-withdrawals">
                    <h3>Your Recent Withdrawals</h3>
                    <?php if (empty($recent_withdrawals)): ?>
                        <p style="color: var(--text-secondary);">No withdrawals recorded yet.</p>
                    <?php else: ?>
                        <?php foreach ($recent_withdrawals as $withdrawal): ?>
                            <div class="withdrawal-item">
                                <div class="withdrawal-date">
                                    <?php echo date('M d', strtotime($withdrawal['created_at'])); ?>
                                </div>
                                <div class="withdrawal-details">
                                    <div class="withdrawal-resource">
                                        <?php echo htmlspecialchars($withdrawal['resource_name']); ?>
                                    </div>
                                    <div class="withdrawal-purpose">
                                        <?php echo htmlspecialchars($withdrawal['purpose']); ?>
                                        <?php if ($withdrawal['notes']): ?>
                                            - <?php echo htmlspecialchars($withdrawal['notes']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="withdrawal-quantity">
                                    -<?php echo number_format($withdrawal['quantity'], 2); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="available-resources">
                <h3>Available Resources</h3>
                <?php 
                $current_category = null;
                foreach ($available_resources as $resource): 
                    if ($resource['category'] !== $current_category):
                        if ($current_category !== null) echo '</div>';
                        $current_category = $resource['category'];
                ?>
                    <div class="resource-group">
                        <h4><?php echo htmlspecialchars($current_category ?? 'Uncategorized'); ?></h4>
                <?php endif; ?>
                    
                    <div class="resource-item" 
                         data-resource-id="<?php echo $resource['id']; ?>"
                         data-resource-name="<?php echo htmlspecialchars($resource['name']); ?>"
                         data-available="<?php echo $resource['available_stock']; ?>"
                         onclick="selectResource(this)">
                        <span class="resource-name"><?php echo htmlspecialchars($resource['name']); ?></span>
                        <span class="resource-quantity"><?php echo number_format($resource['available_stock'], 2); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($current_category !== null) echo '</div>'; ?>
            </div>
        </div>
    </div>

    <script>
        let selectedResource = null;
        
        function selectResource(element) {
            // Remove previous selection
            document.querySelectorAll('.resource-item').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Add selection to clicked item
            element.classList.add('selected');
            
            // Update form
            const resourceId = element.dataset.resourceId;
            const resourceName = element.dataset.resourceName;
            const available = parseFloat(element.dataset.available);
            
            selectedResource = {
                id: resourceId,
                name: resourceName,
                available: available
            };
            
            document.getElementById('resource_id').value = resourceId;
            document.getElementById('selected-resource').innerHTML = `
                <strong>${resourceName}</strong><br>
                <small>Available: ${available.toFixed(2)}</small>
            `;
            
            // Enable form fields
            document.getElementById('quantity').disabled = false;
            document.getElementById('quantity').max = available;
            document.getElementById('purpose').disabled = false;
            document.getElementById('notes').disabled = false;
            document.getElementById('submit-btn').disabled = false;
            
            // Update preview
            document.getElementById('available-amount').textContent = available.toFixed(2);
            updateQuantityPreview();
        }
        
        // Update quantity preview
        document.getElementById('quantity').addEventListener('input', updateQuantityPreview);
        
        function updateQuantityPreview() {
            if (!selectedResource) return;
            
            const quantity = parseFloat(document.getElementById('quantity').value) || 0;
            const remaining = selectedResource.available - quantity;
            
            document.getElementById('remaining-amount').textContent = remaining.toFixed(2);
            document.getElementById('quantity-preview').style.display = quantity > 0 ? 'flex' : 'none';
            
            // Validate quantity
            if (quantity > selectedResource.available) {
                document.getElementById('quantity').setCustomValidity('Quantity exceeds available amount');
            } else {
                document.getElementById('quantity').setCustomValidity('');
            }
        }
    </script>
</body>
</html>