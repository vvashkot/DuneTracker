<?php
/**
 * Farming Run Details - View and manage a specific farming run
 */

require_once 'includes/auth.php';
require_once 'includes/db.php';

// Require login
requireLogin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Get run ID
$run_id = $_GET['id'] ?? 0;
if (!$run_id) {
    header('Location: /farming-runs.php');
    exit();
}

// Get run details
$run = getFarmingRun($run_id);
if (!$run) {
    header('Location: /farming-runs.php');
    exit();
}

// Get participants and collections
$participants = getRunParticipants($run_id);
$collections = getRunCollections($run_id);
$refinable_resources = getRefinableResources();
$all_resources = getAllResources();
$all_guild_members = getAllGuildMembers();
$participant_contributions = getParticipantContributions($run_id);
$crafting_recipes = getCraftingRecipes();

// Get participant IDs for easy checking
$participant_ids = array_column($participants, 'user_id');

// Check if user is participant
$is_participant = false;
$is_leader = false;
$is_admin = isAdmin();
foreach ($participants as $participant) {
    if ($participant['user_id'] == $user['db_id']) {
        $is_participant = true;
        $is_leader = $participant['role'] === 'leader';
        break;
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    switch ($_POST['action']) {
        case 'join_run':
            if (!$is_participant && $run['status'] === 'active') {
                addRunParticipant($run_id, $user['db_id']);
                header('Location: /farming-run.php?id=' . $run_id);
                exit();
            }
            break;
            
        case 'add_collection':
            if ($is_participant && $run['status'] === 'active') {
                $resource_id = $_POST['resource_id'] ?? 0;
                $quantity = $_POST['quantity'] ?? 0;
                $notes = $_POST['notes'] ?? '';
                
                if ($resource_id && $quantity > 0) {
                    addRunCollection($run_id, $resource_id, $quantity, $user['db_id'], $notes ?: null);
                    $message = 'Collection added successfully!';
                    $message_type = 'success';
                    
                    // Refresh data
                    $collections = getRunCollections($run_id);
                }
            }
            break;
            
        case 'add_refined':
            if ($is_participant && $run['status'] === 'active') {
                $input_resource_id = $_POST['input_resource_id'] ?? 0;
                $input_quantity = $_POST['input_quantity'] ?? 0;
                $output_resource_id = $_POST['output_resource_id'] ?? 0;
                $output_quantity = $_POST['output_quantity'] ?? 0;
                
                if ($input_resource_id && $input_quantity > 0 && $output_resource_id && $output_quantity > 0) {
                    addRefinedOutput($run_id, $input_resource_id, $output_resource_id, 
                                   $input_quantity, $output_quantity, $user['db_id']);
                    $message = 'Refined output recorded!';
                    $message_type = 'success';
                }
            }
            break;
            
        case 'complete_run':
            if ($is_leader && $run['status'] === 'active') {
                completeFarmingRun($run_id);
                header('Location: /farming-run.php?id=' . $run_id);
                exit();
            }
            break;
            
        case 'add_participant':
            if (($is_leader || $is_admin) && $run['status'] === 'active') {
                $user_id = $_POST['user_id'] ?? 0;
                if ($user_id && !in_array($user_id, $participant_ids)) {
                    addRunParticipant($run_id, $user_id);
                    header('Location: /farming-run.php?id=' . $run_id);
                    exit();
                }
            }
            break;

        case 'mark_left':
            if (($is_leader || $is_admin) && $run['status'] === 'active') {
                $user_id = (int)($_POST['user_id'] ?? 0);
                if ($user_id) {
                    markRunParticipantLeft($run_id, $user_id);
                    header('Location: /farming-run.php?id=' . $run_id);
                    exit();
                }
            }
            break;

        case 'rejoin':
            if (($is_leader || $is_admin || $user['db_id'] == ($_POST['user_id'] ?? 0)) && $run['status'] === 'active') {
                $user_id = (int)($_POST['user_id'] ?? 0);
                if ($user_id) {
                    rejoinRunParticipant($run_id, $user_id);
                    header('Location: /farming-run.php?id=' . $run_id);
                    exit();
                }
            }
            break;
            
        case 'remove_participant':
            if (($is_leader || $is_admin) && $run['status'] === 'active') {
                $user_id = $_POST['user_id'] ?? 0;
                // Leaders can't remove themselves or other leaders, but admins can remove anyone
                if ($user_id) {
                    if ($is_admin || ($is_leader && $user_id != $run['created_by'])) {
                        removeRunParticipant($run_id, $user_id);
                        header('Location: /farming-run.php?id=' . $run_id);
                        exit();
                    }
                }
            }
            break;
            
        case 'add_crafted':
            if ($is_participant && $run['status'] === 'active') {
                $recipe_id = $_POST['recipe_id'] ?? 0;
                $quantity = $_POST['quantity'] ?? 0;
                
                if ($recipe_id && $quantity > 0) {
                    addCraftedItem($run_id, $recipe_id, $quantity, $user['db_id']);
                    $message = 'Crafted items recorded!';
                    $message_type = 'success';
                    
                    // Refresh data
                    $collections = getRunCollections($run_id);
                }
            }
            break;

        case 'persist_distribution':
            if ($is_leader || $is_admin) {
                $algo = $_POST['algo'] ?? 'weighted_across_runs';
                $discounted = isset($_POST['discounted']) && $_POST['discounted'] === '1';
                $override = isset($_POST['override']) && $_POST['override'] === '1';

                try {
                    // Compute distribution for this run
                    $result = computeSpiceToMelangeDistribution([$run_id], $algo, $discounted);
                    $total_input = (int)($result['total_input'] ?? 0);
                    $total_output = (int)($result['total_output'] ?? 0);
                    if ($total_output <= 0) {
                        $message = 'Nothing to distribute (no spice collected or below threshold).';
                        $message_type = 'error';
                        break;
                    }

                    $db = getDB();
                    // Find Melange resource id
                    $melange_id = $db->query("SELECT id FROM resources WHERE LOWER(name) LIKE 'melange%' ORDER BY id LIMIT 1")->fetchColumn();
                    if (!$melange_id) {
                        $message = 'Melange resource not found. Add it to resources first.';
                        $message_type = 'error';
                        break;
                    }
                    // Guard: has distribution already been recorded for this run/resource?
                    $stmt = $db->prepare("SELECT COUNT(*) FROM run_distributions WHERE run_id = ? AND resource_id = ?");
                    $stmt->execute([$run_id, $melange_id]);
                    $existing = (int)$stmt->fetchColumn();
                    if ($existing > 0 && !$override) {
                        $message = 'This run already has Melange distributions. Check the override box to replace/add more.';
                        $message_type = 'error';
                        break;
                    }

                    // Choose a spice input id for bookkeeping
                    $spice_id = $db->query("SELECT id FROM resources WHERE LOWER(name) LIKE '%spice%' AND LOWER(name) NOT LIKE '%coffee%' ORDER BY id LIMIT 1")->fetchColumn();
                    if (!$spice_id) { $spice_id = $melange_id; }

                    // Record refined output summary
                    addRefinedOutput($run_id, (int)$spice_id, (int)$melange_id, $total_input, $total_output, $user['db_id']);

                    // Persist per-user distributions
                    $note = 'Auto-distribution (' . ($algo === 'equal_per_run' ? 'equal-per-run' : 'weighted') . ($discounted ? ', discounted' : '') . ')';
                    foreach ($result['per_user'] as $uid => $row) {
                        $qty = (int)($row['output'] ?? 0);
                        if ($qty > 0) {
                            distributeRunResources($run_id, (int)$melange_id, (int)$uid, $qty, $user['db_id'], $note);
                        }
                    }

                    logActivity($user['db_id'], 'Persisted run distribution', 'Run ID: ' . $run_id . ', Melange: ' . $total_output, $_SERVER['REMOTE_ADDR'] ?? null);
                    $message = 'Distribution saved successfully (' . number_format($total_output) . ' Melange).';
                    $message_type = 'success';
                } catch (Throwable $e) {
                    error_log('Persist distribution failed: ' . $e->getMessage());
                    $message = 'Failed to persist distribution.';
                    $message_type = 'error';
                }
            }
            break;
    }
}

// Get refined outputs
$refined_outputs = [];
if ($run_id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            ro.*,
            r1.name as input_name,
            r2.name as output_name,
            u.username as refined_by_name
        FROM run_refined_outputs ro
        JOIN resources r1 ON ro.input_resource_id = r1.id
        JOIN resources r2 ON ro.output_resource_id = r2.id
        LEFT JOIN users u ON ro.refined_by = u.id
        WHERE ro.run_id = ?
        ORDER BY ro.refined_at DESC
    ");
    $stmt->execute([$run_id]);
    $refined_outputs = $stmt->fetchAll();
}

// Calculate totals
$collection_totals = [];
$refined_totals = [];

foreach ($collections as $collection) {
    $key = $collection['resource_id'];
    if (!isset($collection_totals[$key])) {
        $collection_totals[$key] = [
            'name' => $collection['resource_name'],
            'category' => $collection['resource_category'],
            'quantity' => 0,
            'can_be_refined' => $collection['can_be_refined']
        ];
    }
    $collection_totals[$key]['quantity'] += $collection['quantity'];
}

foreach ($refined_outputs as $output) {
    $key = $output['output_resource_id'];
    if (!isset($refined_totals[$key])) {
        $refined_totals[$key] = [
            'name' => $output['output_name'],
            'quantity' => 0
        ];
    }
    $refined_totals[$key]['quantity'] += $output['output_quantity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($run['name']); ?> - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <style>
        .run-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .run-status.active {
            background-color: rgba(126, 211, 33, 0.2);
            color: var(--success-color);
        }
        
        .run-status.completed {
            background-color: rgba(153, 170, 181, 0.2);
            color: var(--text-muted);
        }
        
        .run-details-grid {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .participants-panel {
            background-color: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            height: fit-content;
        }
        
        .participant-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .participant-item:last-child {
            border-bottom: none;
        }
        
        .role-badge {
            font-size: 0.75rem;
            padding: 0.125rem 0.5rem;
            background-color: var(--primary-color);
            color: var(--bg-dark);
            border-radius: var(--radius);
        }
        
        .totals-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .total-card {
            background-color: var(--bg-medium);
            padding: 1rem;
            border-radius: var(--radius);
        }
        
        .total-card h4 {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        .collection-form, .refine-form {
            background-color: var(--bg-light);
            padding: 1.5rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }
        
        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .tab {
            padding: 0.75rem 1rem;
            color: var(--text-muted);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
        }
        
        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .form-inline {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }
        
        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.875rem;
        }
        
        @media (max-width: 768px) {
            .run-details-grid {
                grid-template-columns: 1fr;
            }
            
            .totals-section {
                grid-template-columns: 1fr;
            }
            
            .form-inline {
                flex-direction: column;
            }
            
            .form-inline .form-control {
                width: 100%;
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
            <div>
                <h2><?php echo htmlspecialchars($run['name']); ?></h2>
                <div class="run-meta" style="margin-top: 0.5rem;">
                    <span class="run-status <?php echo $run['status']; ?>"><?php echo $run['status']; ?></span>
                    <span>Started <?php echo date('M d, H:i', strtotime($run['started_at'])); ?></span>
                    <?php if ($run['ended_at']): ?>
                        <span>Ended <?php echo date('M d, H:i', strtotime($run['ended_at'])); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="header-actions">
                <a href="/farming-runs.php" class="btn btn-secondary">Back to Runs</a>
                <?php if ($is_leader && $run['status'] === 'active'): ?>
                    <form method="POST" style="display: inline;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="complete_run">
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Complete this farming run?')">
                            Complete Run
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="run-details-grid">
            <div class="main-content">
                <?php if (!$is_participant && $run['status'] === 'active'): ?>
                    <div class="alert alert-info">
                        You're not part of this farming run yet.
                        <form method="POST" style="display: inline;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="join_run">
                            <button type="submit" class="btn btn-primary">Join Run</button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (($is_participant || $is_admin) && $run['status'] === 'active'): ?>
                    <div class="tabs">
                        <?php if ($is_participant): ?>
                            <a href="#" class="tab active" data-tab="collect">Log Collection</a>
                            <a href="#" class="tab" data-tab="craft">Craft Items</a>
                        <?php endif; ?>
                        <a href="#" class="tab<?php echo !$is_participant ? ' active' : ''; ?>" data-tab="summary">Summary</a>
                        <a href="#" class="tab" data-tab="contributions">Contributions</a>
                        <?php if ($is_leader || $is_admin): ?>
                            <a href="#" class="tab" data-tab="manage">Manage</a>
                            <a href="#" class="tab" data-tab="refine">Refine & Distribute</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($is_participant): ?>
                    <div id="collect" class="tab-content active">
                        <div class="collection-form">
                            <h3>Log Resource Collection</h3>
                            <form method="POST">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="add_collection">
                                
                                <div class="form-group">
                                    <label for="resource_id">Resource Collected</label>
                                    <select name="resource_id" id="resource_id" class="form-control" required>
                                        <option value="">Select resource...</option>
                                        <?php 
                                        $current_category = '';
                                        foreach ($all_resources as $resource): 
                                            if ($resource['category'] !== $current_category):
                                                if ($current_category !== ''):
                                                    echo '</optgroup>';
                                                endif;
                                                $current_category = $resource['category'];
                                                echo '<optgroup label="' . htmlspecialchars($current_category ?? 'Uncategorized') . '">';
                                            endif;
                                        ?>
                                            <option value="<?php echo $resource['id']; ?>">
                                                <?php echo htmlspecialchars($resource['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($current_category !== ''): ?>
                                            </optgroup>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="quantity">Quantity</label>
                                    <input type="number" name="quantity" id="quantity" class="form-control" min="1" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="notes">Notes (Optional)</label>
                                    <input type="text" name="notes" id="notes" class="form-control" 
                                           placeholder="e.g., Found at coordinates X,Y">
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Add Collection</button>
                            </form>
                        </div>
                    </div>

                    <div id="craft" class="tab-content">
                        <div class="collection-form">
                            <h3>Craft Items</h3>
                            <form method="POST">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="add_crafted">
                                
                                <div class="form-group">
                                    <label for="recipe_id">Select Recipe</label>
                                    <select name="recipe_id" id="recipe_id" class="form-control" required>
                                        <option value="">Choose a recipe...</option>
                                        <?php foreach ($crafting_recipes as $recipe): ?>
                                            <option value="<?php echo $recipe['id']; ?>">
                                                <?php echo htmlspecialchars($recipe['output_name']); ?>
                                                (<?php echo $recipe['output_quantity']; ?>x)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="recipe-details" style="display: none;">
                                    <div class="recipe-info">
                                        <h4>Recipe Requirements:</h4>
                                        <ul id="ingredients-list"></ul>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="quantity">Number of Batches</label>
                                    <input type="number" name="quantity" id="quantity" class="form-control" min="1" value="1" required>
                                    <small class="form-help">How many times to craft this recipe</small>
                                </div>
                                
                                <div id="craft-summary" style="display: none; margin-top: 1rem;">
                                    <div class="alert alert-info">
                                        <strong>Crafting Summary:</strong>
                                        <div id="summary-text"></div>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Record Crafting</button>
                            </form>
                        </div>
                        
                        <div style="margin-top: 2rem;">
                            <h4>Available Recipes</h4>
                            <div class="recipe-cards">
                                <?php foreach ($crafting_recipes as $recipe): ?>
                                    <div class="recipe-card" style="background: var(--bg-tertiary); padding: 1rem; border-radius: var(--radius); margin-bottom: 1rem;">
                                        <h5><?php echo htmlspecialchars($recipe['output_name']); ?> (<?php echo $recipe['output_quantity']; ?>x)</h5>
                                        <div style="font-size: 0.875rem; color: var(--text-secondary);">
                                            <strong>Requires:</strong>
                                            <ul style="margin: 0.5rem 0; padding-left: 1.5rem;">
                                                <?php foreach ($recipe['ingredients'] as $ingredient): ?>
                                                    <li><?php echo number_format($ingredient['quantity']); ?>x <?php echo htmlspecialchars($ingredient['resource_name']); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div id="summary" class="tab-content<?php echo !$is_participant && $is_admin ? ' active' : ''; ?>">
                        <div class="totals-section">
                            <div class="total-card">
                                <h4>Raw Collections</h4>
                                <?php foreach ($collection_totals as $total): ?>
                                    <div class="resource-item">
                                        <span><?php echo htmlspecialchars($total['name']); ?></span>
                                        <span class="resource-stock stock-positive">
                                            <?php echo number_format($total['quantity']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="total-card">
                                <h4>Refined Outputs</h4>
                                <?php foreach ($refined_totals as $total): ?>
                                    <div class="resource-item">
                                        <span><?php echo htmlspecialchars($total['name']); ?></span>
                                        <span class="resource-stock stock-positive">
                                            <?php echo number_format($total['quantity']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div id="contributions" class="tab-content">
                        <h3>Individual Contributions</h3>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Participant</th>
                                        <th>Collections</th>
                                        <th>Refinements</th>
                                        <th>Total Items</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($participant_contributions as $contrib): ?>
                                        <tr>
                                            <td>
                                                <div class="contributor">
                                                    <img src="<?php echo htmlspecialchars(getAvatarUrl([
                                                        'avatar' => $contrib['avatar'],
                                                        'discord_id' => $contrib['user_id']
                                                    ])); ?>" alt="Avatar" class="small-avatar">
                                                    <span><?php echo htmlspecialchars($contrib['username']); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo $contrib['collection_count']; ?></td>
                                            <td><?php echo $contrib['refinement_count']; ?></td>
                                            <td class="quantity"><?php echo number_format($contrib['total_collected']); ?></td>
                                            <td class="notes" style="font-size: 0.85rem;">
                                                <?php echo htmlspecialchars($contrib['collections_detail'] ?? 'No collections yet'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($is_leader || $is_admin): ?>
                    <div id="manage" class="tab-content">
                        <h3>Manage Participants</h3>
                        <?php if ($is_admin && !$is_leader): ?>
                            <div class="alert alert-info" style="margin-bottom: 1rem;">
                                <strong>Admin Mode:</strong> You have admin privileges to manage this run.
                            </div>
                        <?php endif; ?>
                        
                        <div class="collection-form" style="margin-bottom: 2rem;">
                            <h4>Add Guild Member to Run</h4>
                            <form method="POST" class="form-inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="add_participant">
                                
                                <select name="user_id" class="form-control" required style="flex: 1;">
                                    <option value="">Select guild member...</option>
                                    <?php foreach ($all_guild_members as $member): ?>
                                        <?php if (!in_array($member['id'], $participant_ids)): ?>
                                            <option value="<?php echo $member['id']; ?>">
                                                <?php echo htmlspecialchars($member['username']); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                
                                <button type="submit" class="btn btn-primary">Add to Run</button>
                            </form>
                        </div>
                        
                        <div class="participants-list">
                            <h4>Current Participants</h4>
                            <?php foreach ($participants as $participant): ?>
                                <div class="participant-item" style="padding: 1rem; background: var(--bg-medium); margin-bottom: 0.5rem; border-radius: var(--radius);">
                                    <img src="<?php echo htmlspecialchars(getAvatarUrl([
                                        'avatar' => $participant['avatar'],
                                        'discord_id' => $participant['discord_id']
                                    ])); ?>" alt="Avatar" class="small-avatar">
                                    <span style="flex: 1;"><?php echo htmlspecialchars($participant['username']); ?></span>
                                    <?php if ($participant['role'] === 'leader'): ?>
                                        <span class="role-badge">Leader</span>
                                    <?php endif; ?>
                                    <?php if ($is_admin || ($is_leader && $participant['user_id'] != $run['created_by'])): ?>
                                        <form method="POST" style="display: inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="remove_participant">
                                            <input type="hidden" name="user_id" value="<?php echo $participant['user_id']; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" 
                                                    onclick="return confirm('Remove <?php echo htmlspecialchars($participant['username']); ?> from this run?')">
                                                Remove
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div id="refine" class="tab-content">
                        <h3>Refine & Distribute</h3>
                        <div class="collection-form">
                            <form id="refine-form" onsubmit="return false;">
                                <div class="form-group">
                                    <label>Algorithm</label>
                                    <select id="algo" class="form-control">
                                        <option value="weighted_across_runs">Weighted across runs</option>
                                        <option value="equal_per_run">Equal per run</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" id="discounted"> Discounted refinery (7,500 → 200)
                                    </label>
                                </div>
                                <div class="form-group">
                                    <button type="button" class="btn btn-primary" onclick="previewSpiceRefine()">Preview Spice → Melange</button>
                                </div>
                            </form>
                        </div>
                        <div id="refine-preview" style="display:none; margin-top:1rem;">
                            <h4>Preview</h4>
                            <div class="table-responsive">
                                <table class="data-table" id="preview-table">
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
                                            <th id="total-input">0</th>
                                            <th></th>
                                            <th id="total-output">0</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div class="alert alert-info" id="refine-notes" style="margin-top:0.5rem;"></div>
                            <?php if ($is_leader || $is_admin): ?>
                            <form method="POST" style="margin-top:1rem;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="persist_distribution">
                                <input type="hidden" name="algo" id="persist-algo" value="weighted_across_runs">
                                <input type="hidden" name="discounted" id="persist-discounted" value="0">
                                <label style="display:inline-flex; align-items:center; gap:0.5rem;">
                                    <input type="checkbox" name="override" value="1"> Override existing distribution
                                </label>
                                <button type="submit" class="btn btn-success">Persist Distribution</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <h3>Activity Log</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Player</th>
                                <th>Action</th>
                                <th>Resource</th>
                                <th>Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($collections as $collection): ?>
                                <tr>
                                    <td><?php echo date('H:i', strtotime($collection['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($collection['collector_name'] ?? 'Group'); ?></td>
                                    <td>Collected</td>
                                    <td><?php echo htmlspecialchars($collection['resource_name']); ?></td>
                                    <td class="quantity">+<?php echo number_format($collection['quantity']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php foreach ($refined_outputs as $output): ?>
                                <tr>
                                    <td><?php echo date('H:i', strtotime($output['refined_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($output['refined_by_name'] ?? 'Group'); ?></td>
                                    <td>Refined</td>
                                    <td>
                                        <?php echo htmlspecialchars($output['input_name']); ?> → 
                                        <?php echo htmlspecialchars($output['output_name']); ?>
                                    </td>
                                    <td class="quantity">
                                        <?php echo number_format($output['input_quantity']); ?> → 
                                        <?php echo number_format($output['output_quantity']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="participants-panel">
                <h3>Participants (<?php echo count($participants); ?>)</h3>
                <?php foreach ($participants as $participant): ?>
                    <div class="participant-item">
                        <img src="<?php echo htmlspecialchars(getAvatarUrl([
                            'avatar' => $participant['avatar'],
                            'discord_id' => $participant['discord_id']
                        ])); ?>" alt="Avatar" class="small-avatar">
                        <span><?php echo htmlspecialchars($participant['username']); ?></span>
                        <span style="margin-left:auto; font-size:0.8rem; color:var(--text-secondary);">
                          <?php echo $participant['left_at'] ? ('Left: ' . date('H:i', strtotime($participant['left_at']))) : 'Active'; ?>
                        </span>
                        <?php if ($is_leader || $is_admin): ?>
                          <?php if (!$participant['left_at']): ?>
                            <form method="POST" style="display:inline; margin-left:0.5rem;">
                              <?php echo csrfField(); ?>
                              <input type="hidden" name="action" value="mark_left">
                              <input type="hidden" name="user_id" value="<?php echo (int)$participant['user_id']; ?>">
                              <button class="btn btn-secondary btn-sm" title="Mark Left">Mark Left</button>
                            </form>
                          <?php else: ?>
                            <form method="POST" style="display:inline; margin-left:0.5rem;">
                              <?php echo csrfField(); ?>
                              <input type="hidden" name="action" value="rejoin">
                              <input type="hidden" name="user_id" value="<?php echo (int)$participant['user_id']; ?>">
                              <button class="btn btn-secondary btn-sm" title="Rejoin">Rejoin</button>
                            </form>
                          <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($participant['role'] === 'leader'): ?>
                            <span class="role-badge">Leader</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
    // Tab switching
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const targetTab = tab.dataset.tab;
            
            // Update active tab
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            
            // Show correct content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(targetTab).classList.add('active');
        });
    });

    // Recipe data for JavaScript
    const recipes = <?php echo json_encode($crafting_recipes); ?>;
    
    // Handle recipe selection
    document.getElementById('recipe_id')?.addEventListener('change', function() {
        const recipeId = this.value;
        const recipeDetails = document.getElementById('recipe-details');
        const ingredientsList = document.getElementById('ingredients-list');
        
        if (recipeId && recipes) {
            const recipe = recipes.find(r => r.id == recipeId);
            if (recipe) {
                // Show recipe details
                ingredientsList.innerHTML = recipe.ingredients.map(ing => 
                    `<li>${ing.quantity.toLocaleString()}x ${ing.resource_name}</li>`
                ).join('');
                recipeDetails.style.display = 'block';
                
                updateCraftingSummary();
            }
        } else {
            recipeDetails.style.display = 'none';
        }
    });
    
    // Update crafting summary when quantity changes
    document.getElementById('quantity')?.addEventListener('input', updateCraftingSummary);
    
    function updateCraftingSummary() {
        const recipeId = document.getElementById('recipe_id').value;
        const quantity = document.getElementById('quantity').value || 1;
        const summaryDiv = document.getElementById('craft-summary');
        const summaryText = document.getElementById('summary-text');
        
        if (recipeId && recipes) {
            const recipe = recipes.find(r => r.id == recipeId);
            if (recipe) {
                const totalOutput = recipe.output_quantity * quantity;
                const ingredientsList = recipe.ingredients.map(ing => 
                    `${(ing.quantity * quantity).toLocaleString()}x ${ing.resource_name}`
                ).join(' + ');
                
                summaryText.innerHTML = `
                    <strong>Consumes:</strong> ${ingredientsList}<br>
                    <strong>Produces:</strong> ${totalOutput.toLocaleString()}x ${recipe.output_name}
                `;
                summaryDiv.style.display = 'block';
            }
        } else {
            summaryDiv.style.display = 'none';
        }
    }

    // Preview Spice → Melange using backend computation
    function previewSpiceRefine() {
        const algo = document.getElementById('algo').value;
        const discounted = document.getElementById('discounted').checked ? 1 : 0;
        fetch(`/run-refine-preview.php?run_id=<?php echo (int)$run_id; ?>&algo=${encodeURIComponent(algo)}&discounted=${discounted}`, {
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            // set persistence hidden inputs from UI
            const pa = document.getElementById('persist-algo');
            const pd = document.getElementById('persist-discounted');
            if (pa) pa.value = algo;
            if (pd) pd.value = discounted ? '1' : '0';
            const tbody = document.querySelector('#preview-table tbody');
            tbody.innerHTML = '';
            (data.per_user || []).forEach(row => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${row.username}</td>
                    <td class="quantity">${Number(row.input).toLocaleString()}</td>
                    <td>${(row.share * 100).toFixed(1)}%</td>
                    <td class="quantity">${Number(row.output).toLocaleString()}</td>
                `;
                tbody.appendChild(tr);
            });
            document.getElementById('total-input').textContent = Number(data.total_input || 0).toLocaleString();
            document.getElementById('total-output').textContent = Number(data.total_output || 0).toLocaleString();
            document.getElementById('refine-notes').textContent = data.note || '';
            document.getElementById('refine-preview').style.display = 'block';
        })
        .catch(() => {
            alert('Failed to compute preview');
        });
    }
    </script>
</body>
</html>