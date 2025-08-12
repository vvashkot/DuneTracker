<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    try {
        if ($_POST['action'] === 'add_event') {
            $uid = intval($_POST['user_id']);
            $type = $_POST['type'] === 'air_kill' ? 'air_kill' : 'ground_kill';
            $weapon = trim($_POST['weapon'] ?? '');
            $target = trim($_POST['target'] ?? '');
            $occurred_at = $_POST['occurred_at'] ?: date('Y-m-d H:i:s');
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $db->prepare("INSERT INTO combat_events (user_id, type, weapon, target, occurred_at, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$uid, $type, $weapon ?: null, $target ?: null, $occurred_at, $notes ?: null]);
            $message = 'Combat event logged';
            $message_type = 'success';
        }
    } catch (Exception $e) {
        $message = 'Operation failed';
        $message_type = 'error';
    }
}

$members = getAllGuildMembers();
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-7 days'));

$events = $db->prepare("SELECT ce.*, COALESCE(u.in_game_name, u.username) as username FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.occurred_at >= ? ORDER BY ce.occurred_at DESC LIMIT 200");
$events->execute([$since]);
$events = $events->fetchAll();

$top_ground = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, COUNT(*) as cnt FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.type='ground_kill' AND ce.occurred_at >= ? GROUP BY ce.user_id ORDER BY cnt DESC LIMIT 10");
$top_ground->execute([$since]);
$top_ground = $top_ground->fetchAll();

$top_air = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, COUNT(*) as cnt FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.type='air_kill' AND ce.occurred_at >= ? GROUP BY ce.user_id ORDER BY cnt DESC LIMIT 10");
$top_air->execute([$since]);
$top_air = $top_air->fetchAll();

$top_weapons = $db->prepare("SELECT weapon, COUNT(*) as cnt FROM combat_events WHERE occurred_at >= ? AND weapon IS NOT NULL GROUP BY weapon ORDER BY cnt DESC LIMIT 10");
$top_weapons->execute([$since]);
$top_weapons = $top_weapons->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Combat Stats - Admin</title>
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
            <h2>Combat Statistics</h2>
            <div class="header-actions">
                <a href="/admin/" class="btn btn-secondary">Back to Admin</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="card" style="margin-bottom:1.5rem; padding:1rem;">
            <h3>Log Combat Event</h3>
            <form method="POST" class="form-inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_event">
                <select name="user_id" class="form-control" required>
                    <option value="">Select user...</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['in_game_name'] ?? $m['username']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="type" class="form-control">
                    <option value="ground_kill">Ground Kill</option>
                    <option value="air_kill">Air Kill</option>
                </select>
                <input type="text" name="weapon" class="form-control" placeholder="Weapon (optional)">
                <input type="text" name="target" class="form-control" placeholder="Target (optional)">
                <input type="datetime-local" name="occurred_at" class="form-control">
                <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
                <button type="submit" class="btn btn-primary">Log</button>
            </form>
        </div>

        <div class="card" style="margin-bottom:1.5rem; padding:1rem;">
            <h3>Leaders since <?php echo htmlspecialchars($since); ?></h3>
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:1rem;">
                <div>
                    <h4>Top Ground</h4>
                    <ul>
                        <?php foreach ($top_ground as $t): ?>
                            <li><?php echo htmlspecialchars($t['username']); ?> — <?php echo $t['cnt']; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <h4>Top Air</h4>
                    <ul>
                        <?php foreach ($top_air as $t): ?>
                            <li><?php echo htmlspecialchars($t['username']); ?> — <?php echo $t['cnt']; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <h4>Top Weapons</h4>
                    <ul>
                        <?php foreach ($top_weapons as $w): ?>
                            <li><?php echo htmlspecialchars($w['weapon']); ?> — <?php echo $w['cnt']; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card" style="padding:1rem;">
            <h3>Recent Events</h3>
            <div class="table-responsive">
                <table class="data-table"><thead><tr><th>When</th><th>User</th><th>Type</th><th>Weapon</th><th>Target</th><th>Notes</th></tr></thead><tbody>
                    <?php foreach ($events as $e): ?>
                        <tr>
                            <td><?php echo date('M d, Y H:i', strtotime($e['occurred_at'])); ?></td>
                            <td><?php echo htmlspecialchars($e['username']); ?></td>
                            <td><?php echo $e['type'] === 'air_kill' ? 'Air' : 'Ground'; ?></td>
                            <td><?php echo htmlspecialchars($e['weapon'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($e['target'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($e['notes'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
    </div>
</body>
</html>


