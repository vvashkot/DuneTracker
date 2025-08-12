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
        switch ($_POST['action']) {
            case 'set_filters_required':
                $required = max(0, intval($_POST['filters_required'] ?? 0));
                $stmt = $db->prepare("INSERT INTO guild_settings (setting_key, setting_value, updated_by) VALUES ('filters_required_per_week', ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()");
                $stmt->execute([$required, $user['db_id']]);
                $message = 'Updated weekly filter requirement';
                $message_type = 'success';
                break;
            case 'register_user':
                $uid = intval($_POST['user_id']);
                $week_start = date('Y-m-d', strtotime('monday this week'));
                $stmt = $db->prepare("INSERT IGNORE INTO hub_registrations (user_id, week_start) VALUES (?, ?)");
                $stmt->execute([$uid, $week_start]);
                $message = 'User registered for this week';
                $message_type = 'success';
                break;
            case 'log_chore':
                $uid = intval($_POST['user_id']);
                $chore = $_POST['chore'] === 'move_in' ? 'move_in' : 'move_out';
                $occurred_at = $_POST['occurred_at'] ?: date('Y-m-d H:i:s');
                $notes = trim($_POST['notes'] ?? '');
                $stmt = $db->prepare("INSERT INTO hub_chore_logs (user_id, chore, occurred_at, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$uid, $chore, $occurred_at, $notes ?: null]);
                $message = 'Chore logged';
                $message_type = 'success';
                break;
            case 'add_roster':
                $uid = intval($_POST['user_id']);
                $week_start = $_POST['week_start'] ?: date('Y-m-d', strtotime('monday this week'));
                $role = trim($_POST['role'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                $stmt = $db->prepare("INSERT INTO circuit_roster (user_id, week_start, role, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role=VALUES(role), notes=VALUES(notes)");
                $stmt->execute([$uid, $week_start, $role ?: null, $notes ?: null]);
                $message = 'Roster entry saved';
                $message_type = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = 'Operation failed';
        $message_type = 'error';
    }
}

// Load settings and data
$filters_required = (int)($db->query("SELECT setting_value FROM guild_settings WHERE setting_key='filters_required_per_week'")->fetchColumn() ?: 0);
$members = getAllGuildMembers();
$week_start = date('Y-m-d', strtotime('monday this week'));
$registrations = $db->prepare("SELECT hr.*, COALESCE(u.in_game_name, u.username) as username FROM hub_registrations hr JOIN users u ON hr.user_id=u.id WHERE week_start=? ORDER BY username");
$registrations->execute([$week_start]);
$registrations = $registrations->fetchAll();

$chores = $db->query("SELECT h.*, COALESCE(u.in_game_name, u.username) as username FROM hub_chore_logs h JOIN users u ON h.user_id=u.id ORDER BY occurred_at DESC LIMIT 50")->fetchAll();

$roster = $db->prepare("SELECT c.*, COALESCE(u.in_game_name, u.username) as username FROM circuit_roster c JOIN users u ON c.user_id=u.id WHERE week_start=? ORDER BY username");
$roster->execute([$week_start]);
$roster = $roster->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hub Management - Admin</title>
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
            <h2>Hub Management</h2>
            <div class="header-actions">
                <a href="/admin/" class="btn btn-secondary">Back to Admin</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="card" style="margin-bottom:1.5rem; padding:1rem;">
            <h3>Weekly Filters Requirement</h3>
            <form method="POST" class="form-inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="set_filters_required">
                <label>Required per player (this week):</label>
                <input type="number" name="filters_required" class="form-control" value="<?php echo $filters_required; ?>" min="0" style="width:120px; margin: 0 0.5rem;">
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>

        <div class="card" style="margin-bottom:1.5rem; padding:1rem;">
            <h3>Register Players for This Week</h3>
            <form method="POST" class="form-inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="register_user">
                <select name="user_id" class="form-control" required>
                    <option value="">Select user...</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['in_game_name'] ?? $m['username']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Register</button>
            </form>
            <div style="margin-top:1rem;">
                <strong>Registered (<?php echo count($registrations); ?>):</strong>
                <ul>
                    <?php foreach ($registrations as $r): ?>
                        <li><?php echo htmlspecialchars($r['username']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="card" style="margin-bottom:1.5rem; padding:1rem;">
            <h3>Move In / Move Out Logs</h3>
            <form method="POST" class="form-inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="log_chore">
                <select name="user_id" class="form-control" required>
                    <option value="">Select user...</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['in_game_name'] ?? $m['username']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="chore" class="form-control">
                    <option value="move_out">Move Out</option>
                    <option value="move_in">Move In</option>
                </select>
                <input type="datetime-local" name="occurred_at" class="form-control">
                <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
                <button type="submit" class="btn btn-primary">Log</button>
            </form>
            <div style="margin-top:1rem;" class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>When</th><th>User</th><th>Chore</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($chores as $c): ?>
                            <tr>
                                <td><?php echo date('M d, Y H:i', strtotime($c['occurred_at'])); ?></td>
                                <td><?php echo htmlspecialchars($c['username']); ?></td>
                                <td><?php echo $c['chore'] === 'move_in' ? 'Move In' : 'Move Out'; ?></td>
                                <td><?php echo htmlspecialchars($c['notes'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="padding:1rem;">
            <h3>Circuit Roster (This Week)</h3>
            <form method="POST" class="form-inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_roster">
                <select name="user_id" class="form-control" required>
                    <option value="">Select user...</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['in_game_name'] ?? $m['username']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="week_start" class="form-control" value="<?php echo $week_start; ?>">
                <input type="text" name="role" class="form-control" placeholder="Role (optional)">
                <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
            <div style="margin-top:1rem;">
                <ul>
                    <?php foreach ($roster as $r): ?>
                        <li><?php echo htmlspecialchars($r['username']); ?><?php echo $r['role'] ? ' — ' . htmlspecialchars($r['role']) : ''; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>


