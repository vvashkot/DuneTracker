<?php
/**
 * Pending Approval - Page shown to users awaiting admin approval
 */

require_once 'includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}

$user = getCurrentUser();

// Check if already approved (in case they navigated here directly)
if (isset($user['db_id']) && isUserApproved($user['db_id'])) {
    header('Location: /index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approval - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="/css/style-v2.css">
    <style>
        .approval-container {
            max-width: 600px;
            margin: 100px auto;
            text-align: center;
        }
        
        .approval-card {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 3rem;
            border: 1px solid var(--border-color);
        }
        
        .approval-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        .approval-title {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }
        
        .approval-message {
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        
        .user-info {
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .user-details {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }
        
        .user-avatar-large {
            width: 60px;
            height: 60px;
            border-radius: 50%;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            background-color: rgba(255, 193, 7, 0.2);
            color: var(--warning-color);
            border-radius: var(--radius-sm);
            font-weight: 600;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <h1 class="nav-title"><?php echo htmlspecialchars(APP_NAME); ?></h1>
            <div class="nav-user">
                <a href="/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </nav>

    <div class="approval-container">
        <div class="approval-card">
            <div class="approval-icon">⏳</div>
            <h1 class="approval-title">Approval Pending</h1>
            
            <div class="approval-message">
                <p>Welcome to <?php echo htmlspecialchars(GUILD_NAME); ?>!</p>
                <p>Your account is awaiting approval from a guild administrator. This helps us maintain the security and integrity of our guild resources.</p>
            </div>
            
            <div class="user-info">
                <div class="user-details">
                    <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" 
                         alt="Your Avatar" 
                         class="user-avatar-large">
                    <div>
                        <h3><?php echo htmlspecialchars($user['username']); ?></h3>
                        <div style="color: var(--text-secondary); font-size: 0.875rem;">
                            Discord ID: <?php echo htmlspecialchars($user['discord_id']); ?>
                        </div>
                    </div>
                </div>
                <div class="status-badge">Pending Approval</div>
            </div>
            
            <div class="approval-message">
                <p><strong>What happens next?</strong></p>
                <p>An administrator will review your account and approve access if you're a guild member. This usually happens within 24 hours.</p>
                <p>Once approved, you'll have full access to submit resources, join farming runs, and participate in guild activities.</p>
            </div>
            
            <div style="margin-top: 2rem;">
                <a href="/logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </div>
</body>
</html>