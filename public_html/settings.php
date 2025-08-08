<?php
/**
 * User Settings Page
 */

require_once 'includes/auth.php';
require_once 'includes/db.php';

// Require login
requireLogin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Get additional user data from database
$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user['db_id']]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Merge the data
if ($user_data) {
    $user = array_merge($user, $user_data);
}

// Handle settings updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyPOST();
    
    $db = getDB();
    
    if (isset($_POST['action']) && $_POST['action'] === 'update_tax_settings') {
        $tax_opt_in = isset($_POST['tax_opt_in']) ? 1 : 0;
        $personal_tax_rate = floatval($_POST['personal_tax_rate']) / 100; // Convert percentage to decimal
        
        // Get the guild minimum tax rate
        $stmt = $db->prepare("SELECT setting_value FROM guild_settings WHERE setting_key = 'guild_tax_rate'");
        $stmt->execute();
        $guild_tax_rate = floatval($stmt->fetchColumn());
        
        // Ensure personal rate is not less than guild rate
        if ($personal_tax_rate < $guild_tax_rate) {
            $personal_tax_rate = $guild_tax_rate;
        }
        
        // Validate tax rate
        if ($personal_tax_rate < 0 || $personal_tax_rate > 1) {
            $message = "Tax rate must be between 0% and 100%";
            $message_type = 'error';
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE users 
                    SET tax_opt_in = ?, personal_tax_rate = ?
                    WHERE id = ?
                ");
                $stmt->execute([$tax_opt_in, $personal_tax_rate, $user['db_id']]);
                
                $message = "Tax settings updated successfully";
                $message_type = 'success';
                
                // Refresh user data
                $user = getCurrentUser();
            } catch (PDOException $e) {
                $message = "Failed to update settings";
                $message_type = 'error';
            }
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'update_ign') {
        $in_game_name = trim($_POST['in_game_name'] ?? '');
        try {
            $stmt = $db->prepare("UPDATE users SET in_game_name = ? WHERE id = ?");
            $stmt->execute([$in_game_name ?: null, $user['db_id']]);
            $message = "In-game name updated";
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = "Failed to update in-game name";
            $message_type = 'error';
        }
    }
}

// Get current settings (db already initialized above)

// Get user's current tax settings
$stmt = $db->prepare("SELECT tax_opt_in, personal_tax_rate FROM users WHERE id = ?");
$stmt->execute([$user['db_id']]);
$tax_settings = $stmt->fetch();

// Initialize defaults if columns don't exist
if ($tax_settings === false) {
    $tax_settings = [
        'tax_opt_in' => 1,
        'personal_tax_rate' => 0.10
    ];
}

// Get guild tax settings
$stmt = $db->query("SELECT setting_key, setting_value FROM guild_settings WHERE setting_key IN ('guild_tax_rate', 'guild_tax_enabled')");
$guild_settings = [];
while ($row = $stmt->fetch()) {
    $guild_settings[$row['setting_key']] = $row['setting_value'];
}

$guild_tax_enabled = ($guild_settings['guild_tax_enabled'] ?? 'true') === 'true';
$guild_tax_rate = floatval($guild_settings['guild_tax_rate'] ?? 0.10) * 100; // Convert to percentage
$personal_tax_rate = floatval($tax_settings['personal_tax_rate'] ?? 0.10) * 100; // Convert to percentage
$tax_opt_in = isset($tax_settings['tax_opt_in']) ? intval($tax_settings['tax_opt_in']) : 1;

// Get user's contribution statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_contributions,
        COALESCE(SUM(quantity), 0) as total_contributed,
        COALESCE(SUM(tax_amount), 0) as total_tax_paid
    FROM contributions
    WHERE user_id = ?
");
$stmt->execute([$user['db_id']]);
$contribution_stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <style>
        .settings-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .settings-section {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .settings-section h3 {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tax-info {
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .tax-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-box {
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .tax-input-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .tax-input {
            width: 100px;
        }
        
        .opt-in-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .info-text {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }
        
        .warning-text {
            color: var(--warning-color);
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        
        .guild-tax-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
            margin-left: 1rem;
        }
        
        .guild-tax-status.enabled {
            background-color: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
        }
        
        .guild-tax-status.disabled {
            background-color: rgba(220, 53, 69, 0.2);
            color: var(--danger-color);
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
            <h2>Settings</h2>
            <div class="header-actions">
                <a href="/index.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="settings-container">
            <!-- Guild Tax Settings -->
            <div class="settings-section">
                <h3>
                    Guild Tax Settings
                    <span class="guild-tax-status <?php echo $guild_tax_enabled ? 'enabled' : 'disabled'; ?>">
                        Guild Tax: <?php echo $guild_tax_enabled ? 'ENABLED' : 'DISABLED'; ?>
                    </span>
                </h3>
                
                <?php if ($guild_tax_enabled): ?>
                    <div class="tax-info">
                        <p>The guild has a minimum tax rate of <strong><?php echo number_format($guild_tax_rate, 2); ?>%</strong> on all resource contributions.</p>
                        <p>You can choose to contribute more than the minimum if you wish to support the guild further.</p>
                    </div>
                    
                    <!-- Debug info -->
                    <?php if (isset($_GET['debug'])): ?>
                    <div style="background: #333; padding: 10px; margin: 10px 0; border-radius: 5px; font-family: monospace; font-size: 12px;">
                        <div>Tax opt-in: <?php var_dump($tax_opt_in); ?></div>
                        <div>Personal tax rate: <?php var_dump($personal_tax_rate); ?></div>
                        <div>Guild tax rate: <?php var_dump($guild_tax_rate); ?></div>
                        <div>User data: <?php var_dump($user); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="tax-stats">
                        <div class="stat-box">
                            <div class="stat-value"><?php echo number_format($contribution_stats['total_contributions']); ?></div>
                            <div class="stat-label">Total Contributions</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo number_format($contribution_stats['total_contributed'], 2); ?></div>
                            <div class="stat-label">Resources Contributed</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo number_format($contribution_stats['total_tax_paid'], 2); ?></div>
                            <div class="stat-label">Tax Paid to Guild</div>
                        </div>
                    </div>
                    
                    <form method="POST" style="margin-top: 1.5rem;">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
                        <input type="hidden" name="action" value="update_tax_settings">
                        
                        <div style="margin-bottom: 1rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" 
                                       name="tax_opt_in" 
                                       value="1" 
                                       <?php echo $tax_opt_in ? 'checked' : ''; ?>>
                                <span>Participate in Guild Tax System</span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label for="personal_tax_rate">My Tax Rate</label>
                            <div class="tax-input-group">
                                <input type="number" 
                                       name="personal_tax_rate" 
                                       id="personal_tax_rate" 
                                       class="form-control tax-input" 
                                       value="<?php echo number_format($personal_tax_rate, 2); ?>" 
                                       min="<?php echo number_format($guild_tax_rate, 2); ?>" 
                                       max="100" 
                                       step="0.01"
                                       <?php echo !$tax_opt_in ? 'disabled' : ''; ?>
                                       required>
                                <span>%</span>
                            </div>
                            <div class="info-text">
                                Minimum: <?php echo number_format($guild_tax_rate, 2); ?>%
                                <?php if ($personal_tax_rate > $guild_tax_rate): ?>
                                    • You're contributing an extra <?php echo number_format($personal_tax_rate - $guild_tax_rate, 2); ?>% to support the guild!
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Save Tax Settings</button>
                    </form>
                <?php else: ?>
                    <div class="tax-info">
                        <p>Guild tax is currently disabled by administrators.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Account Information -->
            <div class="settings-section">
                <h3>Account Information</h3>
                
                <div class="form-group">
                    <label>Discord Username</label>
                    <div class="form-control" style="background-color: var(--bg-tertiary);">
                        <?php echo htmlspecialchars($user['username']); ?>
                    </div>
                </div>
                
                <form method="POST" style="margin-top: 1rem;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="update_ign">
                    <div class="form-group">
                        <label for="in_game_name">In-Game Name</label>
                        <input type="text" 
                               name="in_game_name" 
                               id="in_game_name" 
                               class="form-control" 
                               placeholder="Enter your in-game name"
                               value="<?php echo htmlspecialchars($user['in_game_name'] ?? ''); ?>">
                        <small class="form-help">This helps admins map Discord to in-game identity.</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Save In-Game Name</button>
                </form>
                
                <?php if ($user['discord_id']): ?>
                    <div class="form-group">
                        <label>Discord ID</label>
                        <div class="form-control" style="background-color: var(--bg-tertiary);">
                            <?php echo htmlspecialchars($user['discord_id']); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Role</label>
                    <div class="form-control" style="background-color: var(--bg-tertiary);">
                        <?php echo ucfirst(htmlspecialchars($user['role'])); ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Member Since</label>
                    <div class="form-control" style="background-color: var(--bg-tertiary);">
                        <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Enable/disable tax rate input based on opt-in checkbox
        document.querySelector('input[name="tax_opt_in"]').addEventListener('change', function(e) {
            const taxRateInput = document.getElementById('personal_tax_rate');
            taxRateInput.disabled = !e.target.checked;
            if (!e.target.checked) {
                taxRateInput.value = <?php echo number_format($guild_tax_rate, 2); ?>;
            }
        });
    </script>
</body>
</html>