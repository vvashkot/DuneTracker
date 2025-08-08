<?php
// Redirect to enhanced farming runs page
header('Location: /farming-runs-enhanced.php');
exit();

$user = getCurrentUser();
$active_runs = getActiveFarmingRuns();
$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    
    if ($_POST['action'] === 'create_run') {
        $type = $_POST['type'] ?? 'mixed';
        $notes = trim($_POST['notes'] ?? '');
        
        try {
            // Auto-generate name based on run number
            $db = getDB();
            $next_number = $db->query("SELECT IFNULL(MAX(run_number), 0) + 1 FROM farming_runs")->fetchColumn();
            $name = "Run #{$next_number}";
            
            $run_id = createFarmingRun($name, $type, '', $user['db_id'], $notes ?: null);
            // Add creator as leader
            addRunParticipant($run_id, $user['db_id'], 'leader');
            
            // Log the farming run creation
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
        .runs-grid {
            display: grid;
            gap: 2rem;
        }
        
        .run-card {
            background-color: var(--bg-light);
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
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        .run-type {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background-color: var(--bg-medium);
            border-radius: var(--radius);
            font-size: 0.875rem;
            text-transform: uppercase;
        }
        
        .run-type.spice {
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }
        
        .run-type.mining {
            color: var(--secondary-color);
            border: 1px solid var(--secondary-color);
        }
        
        .participants-preview {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .participant-avatars {
            display: flex;
            margin-right: 0.5rem;
        }
        
        .participant-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 2px solid var(--bg-light);
            margin-left: -8px;
        }
        
        .participant-avatar:first-child {
            margin-left: 0;
        }
        
        .create-form {
            background-color: var(--bg-light);
            padding: 2rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
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
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="create-form">
            <h3>Start New Farming Run</h3>
            <form method="POST" action="/farming-runs.php">
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
                    <textarea name="notes" 
                              id="notes" 
                              class="form-control" 
                              rows="2"
                              placeholder="Any special instructions or goals for this run..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">Start Farming Run</button>
            </form>
        </div>

        <div class="runs-grid">
            <h3>Active Farming Runs</h3>
            
            <?php if (empty($active_runs)): ?>
                <div class="empty-state">
                    No active farming runs. Start one above!
                </div>
            <?php else: ?>
                <?php foreach ($active_runs as $run): ?>
                    <div class="run-card">
                        <div class="run-header">
                            <div>
                                <div class="run-title"><?php echo htmlspecialchars($run['name']); ?></div>
                                <div class="run-meta">
                                    <span>Started <?php echo date('M d, Y at H:i', strtotime($run['started_at'])); ?></span>
                                </div>
                            </div>
                            <span class="run-type <?php echo htmlspecialchars($run['run_type']); ?>">
                                <?php echo htmlspecialchars($run['run_type']); ?>
                            </span>
                        </div>
                        
                        <?php if ($run['notes']): ?>
                            <p class="notes"><?php echo htmlspecialchars($run['notes']); ?></p>
                        <?php endif; ?>
                        
                        <div class="participants-preview">
                            <div class="participant-avatars">
                                <img src="<?php echo htmlspecialchars(getAvatarUrl([
                                    'avatar' => $run['creator_avatar'],
                                    'discord_id' => $run['creator_discord_id'] ?? $run['created_by']
                                ])); ?>" 
                                     alt="<?php echo htmlspecialchars($run['creator_name']); ?>" 
                                     class="participant-avatar"
                                     title="Leader: <?php echo htmlspecialchars($run['creator_name']); ?>">
                            </div>
                            <span class="text-muted">
                                <?php echo $run['participant_count']; ?> participant<?php echo $run['participant_count'] !== 1 ? 's' : ''; ?>
                            </span>
                            <a href="/farming-run.php?id=<?php echo $run['id']; ?>" class="btn btn-primary btn-sm" style="margin-left: auto;">
                                Join/View Run
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>