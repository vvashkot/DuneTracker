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

// Leaderboard (this week)
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d 23:59:59', strtotime('sunday this week'));
$leaders_week_stmt = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, SUM(lp.points) as pts FROM landsraad_points lp JOIN users u ON lp.user_id=u.id WHERE lp.occurred_at BETWEEN ? AND ? GROUP BY lp.user_id ORDER BY pts DESC LIMIT 20");
$leaders_week_stmt->execute([$week_start, $week_end]);
$leaders_week = $leaders_week_stmt->fetchAll();

// Leaderboard (since date)
$leaders_since_stmt = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, SUM(lp.points) as pts FROM landsraad_points lp JOIN users u ON lp.user_id=u.id WHERE lp.occurred_at >= ? GROUP BY lp.user_id ORDER BY pts DESC LIMIT 20");
$leaders_since_stmt->execute([$since]);
$leaders_since = $leaders_since_stmt->fetchAll();

// Weekly totals (last 12 weeks)
$weekly_stmt = $db->query("SELECT YEARWEEK(occurred_at, 1) as yw, DATE_FORMAT(STR_TO_DATE(CONCAT(YEARWEEK(occurred_at, 1),' Monday'), '%X%V %W'), '%Y-%m-%d') as week_start, SUM(points) as total_pts FROM landsraad_points GROUP BY YEARWEEK(occurred_at, 1) ORDER BY yw DESC LIMIT 12");
$weekly_totals = $weekly_stmt->fetchAll();

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

    <div class="card" style="margin-bottom:1.5rem;">
      <h3>My Total Since <?php echo htmlspecialchars($since); ?>: <?php echo number_format($my_points); ?></h3>
    </div>

    <div class="card" style="margin-bottom:1.5rem;">
      <h3>Landsraad Leaderboard (This Week)</h3>
      <div class="table-responsive">
        <table class="data-table"><thead><tr><th>Rank</th><th>User</th><th>Points</th></tr></thead><tbody>
          <?php foreach ($leaders_week as $i => $row): ?>
            <tr>
              <td><?php echo $i+1; ?></td>
              <td><?php echo htmlspecialchars($row['username']); ?></td>
              <td class="quantity"><?php echo number_format($row['pts']); ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($leaders_week)): ?><tr><td colspan="3" class="empty-state">No points logged this week.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
    </div>

    <div class="card" style="margin-bottom:1.5rem;">
      <h3>Landsraad Leaderboard (Since <?php echo htmlspecialchars($since); ?>)</h3>
      <form method="GET" class="form-inline" style="margin-bottom:0.75rem;">
        <label for="since" class="form-label" style="margin:0 0.5rem 0 0;">Since</label>
        <input type="date" id="since" name="since" class="form-control" value="<?php echo htmlspecialchars($since); ?>">
        <button class="btn btn-secondary" type="submit">Update</button>
      </form>
      <div class="table-responsive">
        <table class="data-table"><thead><tr><th>Rank</th><th>User</th><th>Points</th></tr></thead><tbody>
          <?php foreach ($leaders_since as $i => $row): ?>
            <tr>
              <td><?php echo $i+1; ?></td>
              <td><?php echo htmlspecialchars($row['username']); ?></td>
              <td class="quantity"><?php echo number_format($row['pts']); ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($leaders_since)): ?><tr><td colspan="3" class="empty-state">No points in this period.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
    </div>

    <div class="card">
      <h3>Weekly Totals (Last 12 Weeks)</h3>
      <div class="table-responsive">
        <table class="data-table"><thead><tr><th>Week Starting</th><th>Total Points</th></tr></thead><tbody>
          <?php foreach ($weekly_totals as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['week_start'] ?: $row['yw']); ?></td>
              <td class="quantity"><?php echo number_format($row['total_pts']); ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($weekly_totals)): ?><tr><td colspan="2" class="empty-state">No weekly data.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
    </div>
  </div>
</body>
</html>


