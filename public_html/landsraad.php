<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

requireLogin();
$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_points') {
    verifyPOST();
    try {
        $points = intval($_POST['points']);
        $category = trim($_POST['category'] ?? '');
        $occurred_at = $_POST['occurred_at'] ?: date('Y-m-d H:i:s');
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $db->prepare("INSERT INTO landsraad_points (user_id, points, category, occurred_at, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user['db_id'], $points, $category ?: null, $occurred_at, $notes ?: null]);
        $message = 'Points added';
        $message_type = 'success';
    } catch (Throwable $e) {
        $message = 'Failed to add points';
        $message_type = 'error';
    }
}

$since = $_GET['since'] ?? date('Y-m-d', strtotime('-30 days'));
$my_total = $db->prepare("SELECT COALESCE(SUM(points),0) FROM landsraad_points WHERE user_id=? AND occurred_at>=?");
$my_total->execute([$user['db_id'], $since]);
$my_points = (int)$my_total->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Landsraad</title>
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
      <h2>My Landsraad</h2>
      <div class="header-actions">
        <a href="/index.php" class="btn btn-secondary">Back to Dashboard</a>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem;">
      <h3>Add Points</h3>
      <form method="POST" class="form-inline">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_points">
        <input type="number" name="points" class="form-control" placeholder="Points" required>
        <input type="text" name="category" class="form-control" placeholder="Category (optional)">
        <input type="datetime-local" name="occurred_at" class="form-control">
        <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
        <button type="submit" class="btn btn-primary">Add</button>
      </form>
    </div>

    <div class="card">
      <h3>My Total Since <?php echo htmlspecialchars($since); ?>: <?php echo number_format($my_points); ?></h3>
    </div>
  </div>
</body>
</html>


