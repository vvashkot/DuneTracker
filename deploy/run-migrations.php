<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Simple token check
$expected = getenv('MIGRATIONS_TOKEN') ?: (defined('MIGRATIONS_TOKEN') ? MIGRATIONS_TOKEN : null);
$provided = $_GET['token'] ?? '';
if (!$expected || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

header('Content-Type: text/plain');

$db = getDB();

function getCurrentMigrationVersion(PDO $db): int {
    try {
        return (int)$db->query("SELECT IFNULL(MAX(version),0) FROM migrations")->fetchColumn();
    } catch (Throwable $e) {
        $db->exec("CREATE TABLE IF NOT EXISTS migrations (version INT PRIMARY KEY, description VARCHAR(255), applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        return 0;
    }
}

$current = getCurrentMigrationVersion($db);
$candidateDirs = [
    dirname(__DIR__, 2) . '/database/migrations',
    dirname(__DIR__) . '/database/migrations',
];
$files = [];
foreach ($candidateDirs as $dir) {
    if (is_dir($dir)) {
        $files = glob($dir . '/*.sql');
        if (!empty($files)) break;
    }
}
natsort($files);

$applied = 0;
foreach ($files as $file) {
    $base = basename($file);
    if (!preg_match('/^(\d+)/', $base, $m)) continue;
    $ver = (int)$m[1];
    if ($ver <= $current) continue;
    $sql = file_get_contents($file);
    if ($sql === false) continue;
    $db->beginTransaction();
    try {
        $db->exec($sql);
        $db->commit();
        echo "Applied: $base\n";
        $applied++;
        $current = $ver;
    } catch (Throwable $e) {
        $db->rollBack();
        http_response_code(500);
        echo 'Failed at ' . $base . ': ' . $e->getMessage();
        exit;
    }
}

echo $applied > 0 ? ("Done. Applied $applied file(s).\n") : "No new migrations.\n";

