<?php
/**
 * Submit Resources - Form for logging resource contributions
 */

require_once 'includes/auth.php';
require_once 'includes/db.php';

// Require login
requireLogin();

$user = getCurrentUser();
$resources = getAllResources();
$active_runs = getActiveFarmingRuns();
$all_guild_members = getAllGuildMembers();
$message = '';
$message_type = '';

// Get tax information
$db = getDB();
$tax_settings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM guild_settings WHERE setting_key IN ('guild_tax_enabled', 'guild_tax_rate')");
while ($row = $stmt->fetch()) {
    $tax_settings[$row['setting_key']] = $row['setting_value'];
}

$tax_enabled = ($tax_settings['guild_tax_enabled'] ?? 'true') === 'true';
$guild_tax_rate = floatval($tax_settings['guild_tax_rate'] ?? 0.10) * 100;

// Get user's tax preferences
$stmt = $db->prepare("SELECT personal_tax_rate, tax_opt_in FROM users WHERE id = ?");
$stmt->execute([$user['db_id']]);
$user_tax = $stmt->fetch();

$user_tax_opt_in = $user_tax['tax_opt_in'] ?? 1;
$user_tax_rate = floatval($user_tax['personal_tax_rate'] ?? 0.10) * 100;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyPOST();
    
    $resource_id = $_POST['resource_id'] ?? '';
    $quantity = $_POST['quantity'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $contributors = $_POST['contributors'] ?? [];
    $run_id = $_POST['run_id'] ?? '';
    $submission_type = $_POST['submission_type'] ?? 'personal';
    
    // Validate inputs
    $errors = [];
    
    if (!$resource_id || !is_numeric($resource_id)) {
        $errors[] = 'Please select a valid resource.';
    }
    
    if (!$quantity || !is_numeric($quantity) || $quantity <= 0) {
        $errors[] = 'Please enter a valid quantity greater than 0.';
    }
    
    if (strlen($notes) > 500) {
        $errors[] = 'Notes must be 500 characters or less.';
    }
    
    if ($submission_type === 'group' && empty($contributors)) {
        $errors[] = 'Please select at least one contributor for group submission.';
    }
    
    if ($submission_type === 'run' && (!$run_id || !is_numeric($run_id))) {
        $errors[] = 'Please select a valid farming run.';
    }
    
    if (empty($errors)) {
        try {
            $db = getDB();
            $db->beginTransaction();
            
            // Get resource name for logging
            $stmt = $db->prepare("SELECT name FROM resources WHERE id = ?");
            $stmt->execute([$resource_id]);
            $resource_name = $stmt->fetchColumn();
            
            if ($submission_type === 'run') {
                // Add to farming run
                addRunCollection($run_id, $resource_id, $quantity, $user['db_id'], $notes ?: null);
                
                logActivity($user['db_id'], 'Added run collection', 
                    "Run ID: $run_id, Resource: $resource_name, Quantity: $quantity", 
                    $_SERVER['REMOTE_ADDR'] ?? null);
                
                $message = 'Resource contribution added to farming run successfully!';
            } elseif ($submission_type === 'group') {
                // Split among contributors
                $contributor_count = count($contributors);
                $split_quantity = floor($quantity / $contributor_count);
                $remainder = $quantity % $contributor_count;
                
                foreach ($contributors as $index => $contributor_id) {
                    // Give remainder to first contributor
                    $contrib_quantity = $split_quantity + ($index === 0 ? $remainder : 0);
                    
                    addContribution($contributor_id, $resource_id, $contrib_quantity, 
                        $notes ? "$notes (Group contribution of $quantity split among $contributor_count members)" : 
                        "Group contribution of $quantity split among $contributor_count members");
                }
                
                logActivity($user['db_id'], 'Added group contribution', 
                    "Resource: $resource_name, Total: $quantity, Contributors: $contributor_count", 
                    $_SERVER['REMOTE_ADDR'] ?? null);
                
                $message = "Resource contribution of $quantity split among $contributor_count contributors!";
            } else {
                // Personal contribution
                addContribution($user['db_id'], $resource_id, $quantity, $notes ?: null);
                
                logActivity($user['db_id'], 'Added contribution', 
                    "Resource: $resource_name, Quantity: $quantity", 
                    $_SERVER['REMOTE_ADDR'] ?? null);
                
                $message = 'Resource contribution recorded successfully!';
            }
            
            $db->commit();
            $message_type = 'success';
            
            // Clear form values on success
            $_POST = [];
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Contribution submission error: ' . $e->getMessage());
            $message = 'An error occurred. Please try again.';
            $message_type = 'error';
        }
    } else {
        $message = implode(' ', $errors);
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Resources - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <style>
        .submission-type-selector {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .type-option {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-weight: 500;
            cursor: pointer;
            position: relative;
            transition: color 0.2s;
        }
        
        .type-option:hover {
            color: var(--text-primary);
        }
        
        .type-option.active {
            color: var(--primary-color);
        }
        
        .type-option.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background-color: var(--primary-color);
        }
        
        .submission-section {
            display: none;
        }
        
        .submission-section.active {
            display: block;
        }
        
        .contributors-select {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.5rem;
        }
        
        .contributor-option {
            padding: 0.5rem;
            margin-bottom: 0.25rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .contributor-option:hover {
            background-color: var(--bg-tertiary);
        }
        
        .contributor-option input {
            margin-right: 0.5rem;
        }
        
        .run-option {
            display: block;
            padding: 1rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .run-option:hover {
            background-color: var(--bg-input);
        }
        
        .run-option input[type="radio"] {
            display: none;
        }
        
        .run-option.selected {
            background-color: var(--bg-input);
            border: 2px solid var(--primary-color);
        }
        
        .run-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .run-selector {
            width: 20px;
            height: 20px;
            border: 2px solid var(--border-color);
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .run-option.selected .run-selector {
            border-color: var(--primary-color);
        }
        
        .run-option.selected .run-selector::after {
            content: '';
            width: 10px;
            height: 10px;
            background-color: var(--primary-color);
            border-radius: 50%;
        }
        
        .run-content {
            flex: 1;
        }
        
        .run-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .run-details {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .resource-selector {
            position: relative;
        }
        
        .resource-search {
            width: 100%;
            padding: 0.75rem;
            background-color: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            color: var(--text-primary);
            font-size: 1rem;
            cursor: pointer;
        }
        
        .resource-search:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .resource-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            max-height: 300px;
            overflow-y: auto;
            z-index: 100;
            display: none;
            margin-top: 0.25rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }
        
        .resource-dropdown.active {
            display: block;
        }
        
        .resource-group {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background-color: var(--bg-tertiary);
            position: sticky;
            top: 0;
        }
        
        .resource-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .resource-item:hover {
            background-color: var(--bg-tertiary);
        }
        
        .resource-item.selected {
            background-color: var(--bg-input);
            color: var(--primary-color);
        }
        
        .resource-quick-select {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }
        
        .quick-select-btn {
            padding: 0.5rem 1rem;
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quick-select-btn:hover {
            background-color: var(--bg-input);
            border-color: var(--primary-color);
        }
        
        .quick-select-btn.active {
            background-color: var(--primary-color);
            color: var(--bg-dark);
            border-color: var(--primary-color);
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
                <div class="feedback-links">
                    <a href="/feedback.php?type=feature" class="btn btn-secondary btn-sm">Submit Feature</a>
                    <a href="/feedback.php?type=bug" class="btn btn-secondary btn-sm">Submit Bug</a>
                </div>
                <a href="/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h2>Submit Resource Contribution</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <form method="POST" action="/submit.php" class="contribution-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="submission_type" id="submission_type" value="group">
                
                <div class="submission-type-selector">
                    <button type="button" class="type-option active" onclick="setSubmissionType('group')">
                        Group Contribution
                    </button>
                    <button type="button" class="type-option" onclick="setSubmissionType('personal')">
                        Personal Contribution
                    </button>
                    <?php if (!empty($active_runs)): ?>
                        <button type="button" class="type-option" onclick="setSubmissionType('run')">
                            Farming Run
                        </button>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="resource_id">Resource Type</label>
                    
                    <!-- Quick select buttons for common resources -->
                    <div class="resource-quick-select">
                        <?php
                        // Get most common resources (you can customize this list)
                        $common_resources = ['Titanium', 'Spice', 'Melange', 'Stravidium'];
                        foreach ($resources as $resource):
                            if (in_array($resource['name'], $common_resources)):
                        ?>
                            <button type="button" 
                                    class="quick-select-btn" 
                                    data-resource-id="<?php echo $resource['id']; ?>"
                                    data-resource-name="<?php echo htmlspecialchars($resource['name']); ?>">
                                <?php echo htmlspecialchars($resource['name']); ?>
                            </button>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                    
                    <!-- Searchable dropdown -->
                    <div class="resource-selector">
                        <input type="hidden" name="resource_id" id="resource_id" required>
                        <input type="text" 
                               id="resource-search" 
                               class="resource-search" 
                               placeholder="Click to select or type to search resources..."
                               readonly>
                        
                        <div class="resource-dropdown" id="resource-dropdown">
                            <?php 
                            $current_category = '';
                            foreach ($resources as $resource): 
                                if ($resource['category'] !== $current_category):
                                    $current_category = $resource['category'];
                            ?>
                                <div class="resource-group"><?php echo htmlspecialchars($current_category ?? 'Uncategorized'); ?></div>
                            <?php endif; ?>
                                <div class="resource-item" 
                                     data-resource-id="<?php echo $resource['id']; ?>"
                                     data-resource-name="<?php echo htmlspecialchars($resource['name']); ?>">
                                    <span><?php echo htmlspecialchars($resource['name']); ?></span>
                                    <?php if ($resource['description']): ?>
                                        <small style="color: var(--text-secondary);">
                                            <?php echo htmlspecialchars(substr($resource['description'], 0, 30)); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="quantity">Quantity</label>
                    <input type="number" 
                           name="quantity" 
                           id="quantity" 
                           class="form-control" 
                           min="1" 
                           value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>" 
                           required>
                    <small class="form-help">Enter the amount of resources you're contributing</small>
                </div>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea name="notes" 
                              id="notes" 
                              class="form-control" 
                              rows="3" 
                              maxlength="500"
                              placeholder="Any additional information about this contribution..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    <small class="form-help">Maximum 500 characters</small>
                </div>

                <!-- Group Contribution Section -->
                <div id="group-section" class="submission-section active">
                    <div class="form-group">
                        <label>Select Contributors</label>
                        <div class="contributors-select">
                            <?php foreach ($all_guild_members as $member): ?>
                                <label class="contributor-option">
                                    <input type="checkbox" 
                                           name="contributors[]" 
                                           value="<?php echo $member['id']; ?>"
                                           <?php echo $member['id'] == $user['db_id'] ? 'checked' : ''; ?>>
                                    <?php echo htmlspecialchars($member['in_game_name'] ?? $member['username']); ?>
                                    <?php if ($member['is_manual']): ?>
                                        <span style="color: var(--text-secondary); font-size: 0.875rem;">(Manual)</span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="form-help">The total quantity will be split evenly among selected contributors</small>
                    </div>
                </div>

                <!-- Farming Run Section -->
                <div id="run-section" class="submission-section">
                    <div class="form-group">
                        <label>Select Farming Run</label>
                        <?php if (empty($active_runs)): ?>
                            <p style="color: var(--text-secondary);">No active farming runs available</p>
                        <?php else: ?>
                            <?php foreach ($active_runs as $run): ?>
                                <label class="run-option" data-run-id="<?php echo $run['id']; ?>">
                                    <input type="radio" name="run_id" value="<?php echo $run['id']; ?>">
                                    <div class="run-info">
                                        <div class="run-selector"></div>
                                        <div class="run-content">
                                            <div class="run-name"><?php echo htmlspecialchars($run['name']); ?></div>
                                            <div class="run-details">
                                                Started <?php echo date('M d, H:i', strtotime($run['started_at'])); ?> • 
                                                <?php echo $run['participant_count']; ?> participants • 
                                                Led by <?php echo htmlspecialchars($run['creator_name']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <small class="form-help">Contribution will be added to the selected farming run</small>
                    </div>
                </div>

                <?php if ($tax_enabled && $user_tax_opt_in): ?>
                <div class="tax-info-box" style="background-color: var(--bg-tertiary); border-radius: var(--radius-sm); padding: 1rem; margin-bottom: 1.5rem;">
                    <h4 style="margin-bottom: 0.5rem; font-size: 1rem;">Guild Tax Information</h4>
                    <p style="color: var(--text-secondary); font-size: 0.875rem; margin: 0;">
                        A <strong><?php echo number_format($user_tax_rate, 1); ?>%</strong> guild tax will be applied to your contribution.
                        <?php if ($user_tax_rate > $guild_tax_rate): ?>
                            <br><small>You're contributing <?php echo number_format($user_tax_rate - $guild_tax_rate, 1); ?>% above the minimum rate. Thank you!</small>
                        <?php endif; ?>
                    </p>
                    <p style="color: var(--text-secondary); font-size: 0.875rem; margin: 0.5rem 0 0 0;">
                        <a href="/settings.php" style="color: var(--primary-color);">Adjust tax settings</a>
                    </p>
                </div>
                <?php elseif ($tax_enabled && !$user_tax_opt_in): ?>
                <div class="tax-info-box" style="background-color: var(--bg-tertiary); border-radius: var(--radius-sm); padding: 1rem; margin-bottom: 1.5rem;">
                    <p style="color: var(--text-secondary); font-size: 0.875rem; margin: 0;">
                        You have opted out of guild tax. <a href="/settings.php" style="color: var(--primary-color);">Change settings</a>
                    </p>
                </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Submit Contribution</button>
                    <a href="/index.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <div class="info-box">
            <h3>Contribution Guidelines</h3>
            <ul>
                <li>Only submit resources you have actually collected and are willing to contribute to the guild bank</li>
                <li>Be accurate with quantities - this helps the guild plan resource usage</li>
                <li>Use the notes field to add any relevant information (e.g., "Found in northern Deep Desert")</li>
                <li>Your contributions are tracked and visible to all guild members</li>
            </ul>
        </div>
    </div>

    <script>
        function setSubmissionType(type) {
            // Update hidden input
            document.getElementById('submission_type').value = type;
            
            // Update active button
            document.querySelectorAll('.type-option').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Hide all sections
            document.querySelectorAll('.submission-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Show relevant section
            if (type === 'group') {
                document.getElementById('group-section').classList.add('active');
            } else if (type === 'run') {
                document.getElementById('run-section').classList.add('active');
            }
            
            // Update form validation
            const contributorCheckboxes = document.querySelectorAll('input[name="contributors[]"]');
            const runRadios = document.querySelectorAll('input[name="run_id"]');
            
            if (type === 'group') {
                contributorCheckboxes.forEach(cb => cb.required = true);
                runRadios.forEach(r => r.required = false);
            } else if (type === 'run') {
                contributorCheckboxes.forEach(cb => cb.required = false);
                runRadios.forEach(r => r.required = true);
            } else {
                contributorCheckboxes.forEach(cb => cb.required = false);
                runRadios.forEach(r => r.required = false);
            }
        }
        
        // Handle custom radio button styling for farming runs
        document.querySelectorAll('.run-option').forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                document.querySelectorAll('.run-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                // Add selected class to clicked option
                this.classList.add('selected');
                // Check the radio button
                this.querySelector('input[type="radio"]').checked = true;
            });
        });
        
        // Resource selector functionality
        const resourceSearch = document.getElementById('resource-search');
        const resourceDropdown = document.getElementById('resource-dropdown');
        const resourceIdInput = document.getElementById('resource_id');
        const resourceItems = document.querySelectorAll('.resource-item');
        const quickSelectBtns = document.querySelectorAll('.quick-select-btn');
        
        // Toggle dropdown
        resourceSearch.addEventListener('click', function() {
            resourceDropdown.classList.toggle('active');
            this.removeAttribute('readonly');
            this.focus();
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.resource-selector')) {
                resourceDropdown.classList.remove('active');
                resourceSearch.setAttribute('readonly', true);
            }
        });
        
        // Filter resources on search
        resourceSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            resourceItems.forEach(item => {
                const resourceName = item.dataset.resourceName.toLowerCase();
                if (resourceName.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Show/hide category headers based on visible items
            document.querySelectorAll('.resource-group').forEach(group => {
                const nextSibling = group.nextElementSibling;
                let hasVisibleItems = false;
                let sibling = nextSibling;
                
                while (sibling && !sibling.classList.contains('resource-group')) {
                    if (sibling.style.display !== 'none') {
                        hasVisibleItems = true;
                        break;
                    }
                    sibling = sibling.nextElementSibling;
                }
                
                group.style.display = hasVisibleItems ? 'block' : 'none';
            });
        });
        
        // Select resource from dropdown
        resourceItems.forEach(item => {
            item.addEventListener('click', function() {
                selectResource(this.dataset.resourceId, this.dataset.resourceName);
            });
        });
        
        // Quick select buttons
        quickSelectBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                selectResource(this.dataset.resourceId, this.dataset.resourceName);
                // Update active state
                quickSelectBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            });
        });
        
        function selectResource(id, name) {
            resourceIdInput.value = id;
            resourceSearch.value = name;
            resourceDropdown.classList.remove('active');
            resourceSearch.setAttribute('readonly', true);
            
            // Update dropdown selection
            resourceItems.forEach(item => {
                if (item.dataset.resourceId === id) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            });
            
            // Update quick select buttons
            quickSelectBtns.forEach(btn => {
                if (btn.dataset.resourceId === id) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }
        
        // Ensure at least one contributor is selected for group submissions
        document.querySelector('form').addEventListener('submit', function(e) {
            const submissionType = document.getElementById('submission_type').value;
            
            if (submissionType === 'group') {
                const checkedContributors = document.querySelectorAll('input[name="contributors[]"]:checked');
                if (checkedContributors.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one contributor for group submission.');
                    return false;
                }
            }
            
            if (submissionType === 'run') {
                const selectedRun = document.querySelector('input[name="run_id"]:checked');
                if (!selectedRun) {
                    e.preventDefault();
                    alert('Please select a farming run.');
                    return false;
                }
            }
        });
    </script>
</body>
</html>