<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

requireLogin();
$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_event') {
    verifyPOST();
    try {
        $type = $_POST['type'] === 'air_kill' ? 'air_kill' : 'ground_kill';
        $weapon = trim($_POST['weapon'] ?? '');
        $target = trim($_POST['target'] ?? '');
        $occurred_at = $_POST['occurred_at'] ?: date('Y-m-d H:i:s');
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $db->prepare("INSERT INTO combat_events (user_id, type, weapon, target, occurred_at, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user['db_id'], $type, $weapon ?: null, $target ?: null, $occurred_at, $notes ?: null]);
        $message = 'Combat event logged';
        $message_type = 'success';
    } catch (Throwable $e) {
        $message = 'Failed to log event';
        $message_type = 'error';
    }
}

$since = $_GET['since'] ?? date('Y-m-d', strtotime('-7 days'));

$my_ground = $db->prepare("SELECT COUNT(*) FROM combat_events WHERE user_id=? AND type='ground_kill' AND occurred_at >= ?");
$my_ground->execute([$user['db_id'], $since]);
$my_ground = (int)$my_ground->fetchColumn();

$my_air = $db->prepare("SELECT COUNT(*) FROM combat_events WHERE user_id=? AND type='air_kill' AND occurred_at >= ?");
$my_air->execute([$user['db_id'], $since]);
$my_air = (int)$my_air->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Combat</title>
  <link rel="stylesheet" href="/css/style-v2.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <h1 class="nav-title"><?php echo htmlspecialchars(APP_NAME); ?></h1>
      <div class="nav-user">
        <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" class="user-avatar" alt="Avatar">
        <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
        <a href="/logout.php" class="btn btn-secondary">Logout</a>
      </div>
    </div>
  </nav>
  <div class="container">
    <div class="page-header">
      <h2>My Combat</h2>
      <div class="header-actions">
        <a href="/index.php" class="btn btn-secondary">Back to Dashboard</a>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem;">
      <h3>Log Combat Event</h3>
      <form method="POST" class="form-inline">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_event">
        <select name="type" class="form-control"><option value="ground_kill">Ground Kill</option><option value="air_kill">Air Kill</option></select>
        <input type="text" name="weapon" class="form-control" placeholder="Weapon (optional)">
        <input type="text" name="target" class="form-control" placeholder="Target (optional)">
        <input type="datetime-local" name="occurred_at" class="form-control">
        <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
        <button type="submit" class="btn btn-primary">Log</button>
      </form>
    </div>

    <div class="card">
      <h3>My Stats since <?php echo htmlspecialchars($since); ?></h3>
      <p>Ground kills: <strong><?php echo number_format($my_ground); ?></strong></p>
      <p>Air kills: <strong><?php echo number_format($my_air); ?></strong></p>
    </div>
  </div>
</body>
</html>


