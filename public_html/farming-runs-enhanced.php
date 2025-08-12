<?php
/**
 * Enhanced Farming Runs - With scheduling, templates, and metrics
 */

require_once 'includes/auth.php';
require_once 'includes/db.php';

// Require login
requireLogin();

$user = getCurrentUser();
$active_runs = getActiveFarmingRuns();
$scheduled_runs = getScheduledRuns();
$templates = getRunTemplates();
$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    switch ($_POST['action']) {
        case 'create_run':
            $type = $_POST['type'] ?? 'mixed';
            $notes = trim($_POST['notes'] ?? '');
            
            try {
                $db = getDB();
                // Use MAX(id) for sequential naming so it works even if run_number column does not exist
                $next_number = $db->query("SELECT IFNULL(MAX(id), 0) + 1 FROM farming_runs")->fetchColumn();
                $name = "Run #{$next_number}";
                
                $run_id = createFarmingRun($name, $type, '', $user['db_id'], $notes ?: null);
                addRunParticipant($run_id, $user['db_id'], 'leader');
                
                logActivity($user['db_id'], 'Created farming run', 
                    "Run: $name, Type: $type", 
                    $_SERVER['REMOTE_ADDR'] ?? null);
                
                header('Location: /farming-run.php?id=' . $run_id);
                exit();
            } catch (Exception $e) {
                error_log('Failed to create farming run: ' . $e->getMessage());
                $message = 'Failed to create farming run. Please try again.';
                $message_type = 'error';
            }
            break;
            
        case 'schedule_run':
            $name = trim($_POST['name']);
            $type = $_POST['type'] ?? 'mixed';
            $scheduled_for = $_POST['scheduled_for'];
            $notes = trim($_POST['notes'] ?? '');
            
            try {
                $run_id = scheduleFarmingRun($name, $type, '', $scheduled_for, $user['db_id'], $notes ?: null);
                
                logActivity($user['db_id'], 'Scheduled farming run', 
                    "Run: $name, Scheduled for: $scheduled_for", 
                    $_SERVER['REMOTE_ADDR'] ?? null);
                
                $message = 'Farming run scheduled successfully';
                $message_type = 'success';
            } catch (Exception $e) {
                error_log('Failed to schedule farming run: ' . $e->getMessage());
                $message = 'Failed to schedule farming run. Please try again.';
                $message_type = 'error';
            }
            break;
            
        case 'create_template':
            $name = trim($_POST['template_name']);
            $description = trim($_POST['description']);
            $type = $_POST['template_type'] ?? 'mixed';
            $location = trim($_POST['default_location'] ?? '');
            $notes = trim($_POST['default_notes'] ?? '');
            
            try {
                createRunTemplate($name, $description, $type, $location, $notes, $user['db_id']);
                
                logActivity($user['db_id'], 'Created run template', 
                    "Template: $name", 
                    $_SERVER['REMOTE_ADDR'] ?? null);
                
                $message = 'Template created successfully';
                $message_type = 'success';
                
                // Refresh templates
                $templates = getRunTemplates();
            } catch (Exception $e) {
                error_log('Failed to create template: ' . $e->getMessage());
                $message = 'Failed to create template. Please try again.';
                $message_type = 'error';
            }
            break;
            
        case 'create_from_template':
            $template_id = intval($_POST['template_id']);
            
            // Get template details
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM run_templates WHERE id = ?");
            $stmt->execute([$template_id]);
            $template = $stmt->fetch();
            
            if ($template) {
                try {
                    // Use MAX(id) for sequential naming so it works even if run_number column does not exist
                    $next_number = $db->query("SELECT IFNULL(MAX(id), 0) + 1 FROM farming_runs")->fetchColumn();
                    $name = "Run #{$next_number} - " . $template['name'];
                    
                    $run_id = createFarmingRun($name, $template['run_type'], $template['default_location'] ?? '', 
                                             $user['db_id'], $template['default_notes']);
                    addRunParticipant($run_id, $user['db_id'], 'leader');
                    
                    logActivity($user['db_id'], 'Created run from template', 
                        "Template: {$template['name']}", 
                        $_SERVER['REMOTE_ADDR'] ?? null);
                    
                    header('Location: /farming-run.php?id=' . $run_id);
                    exit();
                } catch (Exception $e) {
                    error_log('Failed to create run from template: ' . $e->getMessage());
                    $message = 'Failed to create run from template.';
                    $message_type = 'error';
                }
            }
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farming Runs - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <style>
        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .tab-button {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-weight: 500;
            cursor: pointer;
            position: relative;
            transition: color 0.2s;
        }
        
        .tab-button:hover {
            color: var(--text-primary);
        }
        
        .tab-button.active {
            color: var(--primary-color);
        }
        
        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background-color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .runs-grid {
            display: grid;
            gap: 1.5rem;
        }
        
        .run-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            transition: border-color 0.2s;
        }
        
        .run-card:hover {
            border-color: var(--primary-color);
        }
        
        .run-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        
        .run-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }
        
        .run-meta {
            display: flex;
            gap: 1rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .run-type {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            text-transform: uppercase;
        }
        
        .template-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
        }
        
        .template-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .template-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .template-description {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }
        
        .create-form {
            background-color: var(--bg-secondary);
            padding: 2rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }
        
        .scheduled-time {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            color: var(--warning-color);
        }
        
        .metrics-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            background-color: rgba(75, 139, 245, 0.1);
            color: var(--primary-color);
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 500;
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
                <a href="/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h2>Farming Runs</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Dashboard</a>
                <?php if (isAdmin()): ?>
                    <a href="/admin/runs.php" class="btn btn-danger">Manage Runs</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="tabs">
            <button class="tab-button active" onclick="switchTab('active')">Active Runs</button>
            <button class="tab-button" onclick="switchTab('scheduled')">Scheduled Runs</button>
            <button class="tab-button" onclick="switchTab('templates')">Templates</button>
            <button class="tab-button" onclick="switchTab('create')">Create New</button>
            <?php if (isAdmin()): ?>
            <button class="tab-button" onclick="switchTab('multi')">Multi-Run Distribute</button>
            <?php endif; ?>
        </div>

        <!-- Active Runs Tab -->
        <div id="active-tab" class="tab-content active">
            <div class="runs-grid">
                <?php if (empty($active_runs)): ?>
                    <div class="empty-state">
                        <p>No active farming runs. Start one from the Create New tab!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($active_runs as $run): ?>
                        <div class="run-card">
                            <div class="run-header">
                                <div>
                                    <div class="run-title"><?php echo htmlspecialchars($run['name']); ?></div>
                                    <div class="run-meta">
                                        <span>Started <?php echo date('M d, Y at H:i', strtotime($run['started_at'])); ?></span>
                                        <span>by <?php echo htmlspecialchars($run['creator_name']); ?></span>
                                        <span><?php echo $run['participant_count']; ?> participants</span>
                                    </div>
                                </div>
                                <span class="run-type <?php echo htmlspecialchars($run['run_type']); ?>">
                                    <?php echo htmlspecialchars($run['run_type']); ?>
                                </span>
                            </div>
                            
                            <?php if ($run['notes']): ?>
                                <p class="notes"><?php echo htmlspecialchars($run['notes']); ?></p>
                            <?php endif; ?>
                            
                            <div style="margin-top: 1rem;">
                                <a href="/farming-run.php?id=<?php echo $run['id']; ?>" class="btn btn-primary">
                                    Join/View Run
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Scheduled Runs Tab -->
        <div id="scheduled-tab" class="tab-content">
            <div class="runs-grid">
                <?php if (empty($scheduled_runs)): ?>
                    <div class="empty-state">
                        <p>No scheduled runs. Schedule one from the Create New tab!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($scheduled_runs as $run): ?>
                        <div class="run-card">
                            <div class="run-header">
                                <div>
                                    <div class="run-title"><?php echo htmlspecialchars($run['name']); ?></div>
                                    <div class="run-meta">
                                        <span class="scheduled-time">
                                            🕒 <?php echo date('M d, Y at H:i', strtotime($run['scheduled_for'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <span class="run-type <?php echo htmlspecialchars($run['run_type']); ?>">
                                    <?php echo htmlspecialchars($run['run_type']); ?>
                                </span>
                            </div>
                            
                            <?php if ($run['notes']): ?>
                                <p class="notes"><?php echo htmlspecialchars($run['notes']); ?></p>
                            <?php endif; ?>
                            
                            <div style="margin-top: 1rem; color: var(--text-secondary); font-size: 0.875rem;">
                                Scheduled by <?php echo htmlspecialchars($run['creator_name']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Templates Tab -->
        <div id="templates-tab" class="tab-content">
            <div class="runs-grid">
                <?php if (empty($templates)): ?>
                    <div class="empty-state">
                        <p>No templates created yet. Create one from the Create New tab!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($templates as $template): ?>
                        <div class="template-card">
                            <div class="template-header">
                                <span class="template-name"><?php echo htmlspecialchars($template['name']); ?></span>
                                <span class="run-type <?php echo htmlspecialchars($template['run_type']); ?>">
                                    <?php echo htmlspecialchars($template['run_type']); ?>
                                </span>
                            </div>
                            
                            <?php if ($template['description']): ?>
                                <p class="template-description"><?php echo htmlspecialchars($template['description']); ?></p>
                            <?php endif; ?>
                            
                            <form method="POST" style="margin-top: 1rem;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="create_from_template">
                                <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                <button type="submit" class="btn btn-primary">Start Run from Template</button>
                            </form>
                            
                            <div style="margin-top: 0.5rem; color: var(--text-secondary); font-size: 0.75rem;">
                                Created by <?php echo htmlspecialchars($template['creator_name']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Create New Tab -->
        <div id="create-tab" class="tab-content">
            <div class="runs-grid">
                <!-- Quick Start Run -->
                <div class="create-form">
                    <h3>Quick Start Run</h3>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="create_run">
                        
                        <div class="form-group">
                            <label for="type">Run Type</label>
                            <select name="type" id="type" class="form-control">
                                <option value="mixed">Mixed Resources</option>
                                <option value="spice">Spice Farming</option>
                                <option value="mining">Mining Run</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Notes (Optional)</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Start Run Now</button>
                    </form>
                </div>

                <!-- Schedule Run -->
                <div class="create-form">
                    <h3>Schedule Run</h3>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="schedule_run">
                        
                        <div class="form-group">
                            <label for="schedule_name">Run Name</label>
                            <input type="text" name="name" id="schedule_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="scheduled_for">Scheduled For</label>
                            <input type="datetime-local" name="scheduled_for" id="scheduled_for" 
                                   class="form-control" required 
                                   min="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="schedule_type">Run Type</label>
                            <select name="type" id="schedule_type" class="form-control">
                                <option value="mixed">Mixed Resources</option>
                                <option value="spice">Spice Farming</option>
                                <option value="mining">Mining Run</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="schedule_notes">Notes (Optional)</label>
                            <textarea name="notes" id="schedule_notes" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Schedule Run</button>
                    </form>
                </div>

                <!-- Create Template -->
                <?php if (isAdmin()): ?>
                <div class="create-form">
                    <h3>Create Template</h3>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="create_template">
                        
                        <div class="form-group">
                            <label for="template_name">Template Name</label>
                            <input type="text" name="template_name" id="template_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="template_type">Default Run Type</label>
                            <select name="template_type" id="template_type" class="form-control">
                                <option value="mixed">Mixed Resources</option>
                                <option value="spice">Spice Farming</option>
                                <option value="mining">Mining Run</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="default_notes">Default Notes</label>
                            <textarea name="default_notes" id="default_notes" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Create Template</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isAdmin()): ?>
        <!-- Multi-Run Distribute Tab -->
        <div id="multi-tab" class="tab-content">
            <div class="runs-grid">
                <div class="create-form">
                    <h3>Refine & Distribute Across Multiple Runs</h3>
                    <form onsubmit="return false;" class="form-inline" style="flex-wrap:wrap; gap:0.75rem;">
                        <div class="form-group" style="min-width:280px;">
                            <label>Select Runs</label>
                            <select id="multi-run-select" class="form-control" multiple size="6" style="min-width:280px;">
                                <?php 
                                $allActive = getActiveFarmingRuns();
                                foreach ($allActive as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-help">Hold Ctrl/Cmd to select multiple</small>
                        </div>
                        <div class="form-group">
                            <label>Algorithm</label>
                            <select id="multi-algo" class="form-control">
                                <option value="weighted_across_runs">Weighted across runs</option>
                                <option value="equal_per_run">Equal per run</option>
                            </select>
                        </div>
                        <label style="display:inline-flex; align-items:center; gap:0.5rem;">
                            <input type="checkbox" id="multi-discounted"> Discounted refinery
                        </label>
                        <button class="btn btn-primary" onclick="previewMulti()">Preview</button>
                    </form>
                </div>

                <div id="multi-preview" class="card" style="display:none;">
                    <h4>Preview</h4>
                    <div class="table-responsive">
                        <table class="data-table" id="multi-preview-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Input (Spice)</th>
                                    <th>Share</th>
                                    <th>Output (Melange)</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                            <tfoot>
                                <tr>
                                    <th>Total</th>
                                    <th id="multi-total-input">0</th>
                                    <th></th>
                                    <th id="multi-total-output">0</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="alert alert-info" id="multi-note" style="margin-top:0.5rem;"></div>

                    <form method="POST" style="margin-top:1rem;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="persist_distribution">
                        <input type="hidden" name="algo" id="multi-persist-algo" value="weighted_across_runs">
                        <input type="hidden" name="discounted" id="multi-persist-discounted" value="0">
                        <label style="display:inline-flex; align-items:center; gap:0.5rem;">
                            <input type="checkbox" name="override" value="1"> Override existing distribution
                        </label>
                        <span style="margin-left:1rem; color:var(--text-secondary); font-size:0.9rem;">Persist to which run?</span>
                        <select name="persist_run_id" class="form-control" style="min-width:200px;">
                            <?php foreach ($allActive as $r): ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-success">Persist Distribution</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }

        // Multi-run preview
        function previewMulti() {
            const select = document.getElementById('multi-run-select');
            const runIds = Array.from(select.selectedOptions).map(o => o.value).join(',');
            const algo = document.getElementById('multi-algo').value;
            const discounted = document.getElementById('multi-discounted').checked ? 1 : 0;
            if (!runIds) { alert('Select at least one run.'); return; }
            fetch(`/multi-run-refine-preview.php?run_ids=${encodeURIComponent(runIds)}&algo=${encodeURIComponent(algo)}&discounted=${discounted}`, { credentials: 'same-origin' })
              .then(r => r.json())
              .then(data => {
                if (data.error) { alert(data.error); return; }
                const tbody = document.querySelector('#multi-preview-table tbody');
                tbody.innerHTML = '';
                (data.per_user || []).forEach(row => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `<td>${row.username}</td>
                                    <td class="quantity">${Number(row.input).toLocaleString()}</td>
                                    <td>${(row.share * 100).toFixed(1)}%</td>
                                    <td class="quantity">${Number(row.output).toLocaleString()}</td>`;
                    tbody.appendChild(tr);
                });
                document.getElementById('multi-total-input').textContent = Number(data.total_input || 0).toLocaleString();
                document.getElementById('multi-total-output').textContent = Number(data.total_output || 0).toLocaleString();
                document.getElementById('multi-note').textContent = data.note || '';
                document.getElementById('multi-preview').style.display = 'block';
                // set hidden inputs for persist
                document.getElementById('multi-persist-algo').value = algo;
                document.getElementById('multi-persist-discounted').value = discounted ? '1' : '0';
              })
              .catch(() => alert('Failed to preview'));
        }
    </script>
</body>
</html>