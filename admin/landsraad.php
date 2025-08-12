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
        if ($_POST['action'] === 'add_points') {
            $uid = intval($_POST['user_id']);
            $points = intval($_POST['points']);
            $category = trim($_POST['category'] ?? '');
            $occurred_at = $_POST['occurred_at'] ?: date('Y-m-d H:i:s');
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $db->prepare("INSERT INTO landsraad_points (user_id, points, category, occurred_at, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$uid, $points, $category ?: null, $occurred_at, $notes ?: null]);
            $message = 'Points added';
            $message_type = 'success';
        }
    } catch (Exception $e) {
        $message = 'Operation failed';
        $message_type = 'error';
    }
}

$members = getAllGuildMembers();
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-30 days'));

$leaders = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, SUM(lp.points) as pts FROM landsraad_points lp JOIN users u ON lp.user_id=u.id WHERE lp.occurred_at >= ? GROUP BY lp.user_id ORDER BY pts DESC LIMIT 20");
$leaders->execute([$since]);
$leaders = $leaders->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Landsraad Points - Admin</title>
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
            <h2>Landsraad Points</h2>
            <div class="header-actions">
                <a href="/admin/" class="btn btn-secondary">Back to Admin</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="card" style="margin-bottom:1.5rem; padding:1rem;">
            <h3>Add Points</h3>
            <form method="POST" class="form-inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_points">
                <select name="user_id" class="form-control" required>
                    <option value="">Select user...</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['in_game_name'] ?? $m['username']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="points" class="form-control" placeholder="Points" required>
                <input type="text" name="category" class="form-control" placeholder="Category (optional)">
                <input type="datetime-local" name="occurred_at" class="form-control">
                <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
                <button type="submit" class="btn btn-primary">Add</button>
            </form>
        </div>

        <div class="card" style="padding:1rem;">
            <h3>Leaders since <?php echo htmlspecialchars($since); ?></h3>
            <div class="table-responsive">
                <table class="data-table"><thead><tr><th>User</th><th>Points</th></tr></thead><tbody>
                    <?php foreach ($leaders as $l): ?>
                        <tr><td><?php echo htmlspecialchars($l['username']); ?></td><td class="quantity"><?php echo number_format($l['pts']); ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
    </div>
</body>
</html>


