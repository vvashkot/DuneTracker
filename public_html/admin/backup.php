<?php
/**
 * Admin Panel - Database Backup
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';

// Require admin access
requireAdmin();

$user = getCurrentUser();
$message = '';
$message_type = '';

// Handle backup download
if (isset($_GET['download']) && $_GET['download'] === 'sql') {
    verifyCSRFToken($_GET['token'] ?? '');
    
    try {
        $db = getDB();
        
        // Get all tables
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        // Start output buffering
        ob_start();
        
        // Header
        echo "-- Dune Awakening Guild Tracker Database Backup\n";
        echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "-- By: " . $user['username'] . "\n\n";
        
        echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        echo "SET time_zone = \"+00:00\";\n\n";
        
        // Dump each table
        foreach ($tables as $table) {
            echo "\n-- Table structure for table `$table`\n";
            echo "DROP TABLE IF EXISTS `$table`;\n";
            
            // Get create table statement
            $create = $db->query("SHOW CREATE TABLE `$table`")->fetch();
            echo $create['Create Table'] . ";\n\n";
            
            // Get table data
            $data = $db->query("SELECT * FROM `$table`")->fetchAll();
            if (!empty($data)) {
                echo "-- Dumping data for table `$table`\n";
                
                foreach ($data as $row) {
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = $db->quote($value);
                        }
                    }
                    echo "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
                }
                echo "\n";
            }
        }
        
        $sql_dump = ob_get_clean();
        
        // Log the backup action
        logActivity($user['db_id'], 'Downloaded database backup', null, $_SERVER['REMOTE_ADDR']);
        
        // Send as download
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="guild_tracker_backup_' . date('Y-m-d_His') . '.sql"');
        header('Content-Length: ' . strlen($sql_dump));
        echo $sql_dump;
        exit();
        
    } catch (Exception $e) {
        ob_end_clean();
        $message = 'Failed to generate backup: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    verifyCSRFToken($_GET['token'] ?? '');
    
    try {
        $db = getDB();
        
        // Create a zip file with all CSV exports
        $zip = new ZipArchive();
        $zipname = tempnam(sys_get_temp_dir(), 'backup_') . '.zip';
        
        if ($zip->open($zipname, ZipArchive::CREATE) !== TRUE) {
            throw new Exception('Cannot create zip file');
        }
        
        // Export users
        $users = $db->query("SELECT * FROM users")->fetchAll();
        $csv = "id,discord_id,username,avatar,role,created_at,updated_at,last_login\n";
        foreach ($users as $row) {
            $csv .= implode(',', array_map(function($v) { 
                return '"' . str_replace('"', '""', $v ?? '') . '"'; 
            }, $row)) . "\n";
        }
        $zip->addFromString('users.csv', $csv);
        
        // Export resources
        $resources = $db->query("SELECT * FROM resources")->fetchAll();
        $csv = "id,name,category\n";
        foreach ($resources as $row) {
            $csv .= implode(',', array_map(function($v) { 
                return '"' . str_replace('"', '""', $v ?? '') . '"'; 
            }, $row)) . "\n";
        }
        $zip->addFromString('resources.csv', $csv);
        
        // Export contributions with usernames and resource names
        $contributions = $db->query("
            SELECT 
                c.id,
                u.username,
                r.name as resource_name,
                c.quantity,
                c.date_collected,
                c.notes
            FROM contributions c
            JOIN users u ON c.user_id = u.id
            JOIN resources r ON c.resource_id = r.id
            ORDER BY c.date_collected DESC
        ")->fetchAll();
        $csv = "id,username,resource_name,quantity,date_collected,notes\n";
        foreach ($contributions as $row) {
            $csv .= implode(',', array_map(function($v) { 
                return '"' . str_replace('"', '""', $v ?? '') . '"'; 
            }, $row)) . "\n";
        }
        $zip->addFromString('contributions.csv', $csv);
        
        // Export farming runs
        $runs = $db->query("
            SELECT 
                fr.id,
                fr.name,
                fr.run_type,
                fr.started_at,
                fr.ended_at,
                fr.status,
                u.username as created_by,
                fr.notes
            FROM farming_runs fr
            JOIN users u ON fr.created_by = u.id
            ORDER BY fr.started_at DESC
        ")->fetchAll();
        $csv = "id,name,run_type,started_at,ended_at,status,created_by,notes\n";
        foreach ($runs as $row) {
            $csv .= implode(',', array_map(function($v) { 
                return '"' . str_replace('"', '""', $v ?? '') . '"'; 
            }, $row)) . "\n";
        }
        $zip->addFromString('farming_runs.csv', $csv);
        
        $zip->close();
        
        // Log the export action
        logActivity($user['db_id'], 'Exported data as CSV', null, $_SERVER['REMOTE_ADDR']);
        
        // Send zip file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="guild_tracker_export_' . date('Y-m-d_His') . '.zip"');
        header('Content-Length: ' . filesize($zipname));
        readfile($zipname);
        unlink($zipname);
        exit();
        
    } catch (Exception $e) {
        $message = 'Failed to export data: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Get database statistics
$db = getDB();
$stats = [
    'users' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'resources' => $db->query("SELECT COUNT(*) FROM resources")->fetchColumn(),
    'contributions' => $db->query("SELECT COUNT(*) FROM contributions")->fetchColumn(),
    'farming_runs' => $db->query("SELECT COUNT(*) FROM farming_runs")->fetchColumn(),
    'activity_logs' => $db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn(),
];

// Calculate database size
$size_query = $db->query("
    SELECT 
        SUM(data_length + index_length) as size
    FROM information_schema.TABLES 
    WHERE table_schema = DATABASE()
")->fetch();
$db_size = $size_query['size'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backup - Admin Panel</title>
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
        
        .backup-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        .backup-card {
            background-color: var(--bg-secondary);
            border-radius: var(--radius);
            padding: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .backup-card h3 {
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .backup-description {
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        
        .stats-list {
            list-style: none;
            margin-bottom: 1.5rem;
        }
        
        .stats-list li {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .stats-list li:last-child {
            border-bottom: none;
        }
        
        .stat-label {
            color: var(--text-secondary);
        }
        
        .stat-value {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .db-size {
            background-color: var(--bg-tertiary);
            padding: 1rem;
            border-radius: var(--radius-sm);
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .db-size-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .db-size-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        @media (max-width: 768px) {
            .backup-grid {
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
            <h2>Database Backup & Export</h2>
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
                <li><a href="/admin/resources.php">📦 Resources</a></li>
                <li><a href="/admin/runs.php">🚜 Farming Runs</a></li>
                <li><a href="/admin/logs.php">📋 Activity Logs</a></li>
                <li><a href="/admin/webhooks.php">🔔 Webhooks</a></li>
                <li><a href="/admin/backup.php" class="active">💾 Backup</a></li>
            </ul>
        </nav>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="backup-grid">
            <div class="backup-card">
                <h3>💾 SQL Database Backup</h3>
                <p class="backup-description">
                    Download a complete SQL dump of the database. This includes all tables, data, and structure.
                    Use this for creating backups or migrating to another server.
                </p>
                
                <div class="db-size">
                    <div class="db-size-value"><?php echo number_format($db_size / 1024 / 1024, 2); ?> MB</div>
                    <div class="db-size-label">Database Size</div>
                </div>
                
                <a href="?download=sql&token=<?php echo urlencode(getCSRFToken()); ?>" 
                   class="btn btn-primary"
                   onclick="return confirm('Download SQL backup? This may take a moment for large databases.')">
                    Download SQL Backup
                </a>
            </div>
            
            <div class="backup-card">
                <h3>📊 CSV Data Export</h3>
                <p class="backup-description">
                    Export all data as CSV files in a zip archive. Useful for data analysis, 
                    reporting, or importing into spreadsheet applications.
                </p>
                
                <ul class="stats-list">
                    <li>
                        <span class="stat-label">Users</span>
                        <span class="stat-value"><?php echo number_format($stats['users']); ?></span>
                    </li>
                    <li>
                        <span class="stat-label">Resources</span>
                        <span class="stat-value"><?php echo number_format($stats['resources']); ?></span>
                    </li>
                    <li>
                        <span class="stat-label">Contributions</span>
                        <span class="stat-value"><?php echo number_format($stats['contributions']); ?></span>
                    </li>
                    <li>
                        <span class="stat-label">Farming Runs</span>
                        <span class="stat-value"><?php echo number_format($stats['farming_runs']); ?></span>
                    </li>
                    <li>
                        <span class="stat-label">Activity Logs</span>
                        <span class="stat-value"><?php echo number_format($stats['activity_logs']); ?></span>
                    </li>
                </ul>
                
                <a href="?export=csv&token=<?php echo urlencode(getCSRFToken()); ?>" 
                   class="btn btn-primary">
                    Export as CSV
                </a>
            </div>
        </div>
        
        <div class="backup-card" style="margin-top: 2rem;">
            <h3>⚠️ Important Notes</h3>
            <ul style="color: var(--text-secondary); font-size: 0.875rem; margin-left: 1.5rem;">
                <li>Always test backups by restoring them to a test environment</li>
                <li>Store backups securely and encrypt sensitive data</li>
                <li>Regular backups are recommended (daily or weekly)</li>
                <li>SQL backups can be restored using MySQL command line or phpMyAdmin</li>
                <li>CSV exports do not include relationships between tables</li>
            </ul>
        </div>
    </div>
</body>
</html>