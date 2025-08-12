<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';
$applied = [];

function getCurrentMigrationVersion(PDO $db): int {
    try {
        $v = $db->query("SELECT IFNULL(MAX(version),0) FROM migrations")->fetchColumn();
        return (int)$v;
    } catch (Throwable $e) {
        // Create migrations table if missing
        $db->exec("CREATE TABLE IF NOT EXISTS migrations (version INT PRIMARY KEY, description VARCHAR(255), applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        return 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run') {
    verifyPOST();
    $current = getCurrentMigrationVersion($db);
    // Support both monorepo root and public_html-only deploys
    $candidateDirs = [
        dirname(__DIR__, 2) . '/database/migrations', // repo root
        dirname(__DIR__) . '/database/migrations',     // inside public_html
    ];
    $files = [];
    foreach ($candidateDirs as $dir) {
        if (is_dir($dir)) {
            $files = glob($dir . '/*.sql');
            if (!empty($files)) break;
        }
    }
    natsort($files);
    try {
        foreach ($files as $file) {
            $base = basename($file);
            if (!preg_match('/^(\d+)/', $base, $m)) continue;
            $ver = (int)$m[1];
            if ($ver <= $current) continue;
            $sql = file_get_contents($file);
            if ($sql === false) continue;
            try {
                // Some DDL causes implicit commits in MySQL; avoid explicit transactions
                $db->exec($sql);
                $applied[] = $base;
                $current = $ver;
            } catch (Throwable $e) {
                $message = 'Migration failed at ' . $base . ': ' . $e->getMessage();
                $message_type = 'error';
                break;
            }
        }
        if (empty($message)) {
            $message = empty($applied) ? 'No new migrations to apply' : 'Applied ' . count($applied) . ' migration(s)';
            $message_type = 'success';
        }
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'error';
    }
}

$current_version = getCurrentMigrationVersion($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrations - Admin</title>
    <link rel="stylesheet" href="/css/style-v2.css">
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
            <h2>Database Migrations</h2>
            <div class="header-actions">
                <a href="/admin/" class="btn btn-secondary">Back to Admin</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="card" style="padding:1rem;">
            <p>Current migration version: <strong><?php echo $current_version; ?></strong></p>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="run">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Run pending SQL migrations?');">Run Migrations</button>
            </form>
            <?php if (!empty($applied)): ?>
                <div style="margin-top:1rem;">
                    <strong>Applied files:</strong>
                    <ul>
                        <?php foreach ($applied as $f): ?><li><?php echo htmlspecialchars($f); ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>


