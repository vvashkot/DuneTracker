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
        if (isset($_POST['is_dazen']) && $_POST['is_dazen'] === '1') { $target = $target ? ($target . ' | DAZEN') : 'DAZEN'; }
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

// Leaderboards (weekly and since date)
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d 23:59:59', strtotime('sunday this week'));

$top_ground_week = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, COUNT(*) as cnt FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.type='ground_kill' AND ce.occurred_at BETWEEN ? AND ? GROUP BY ce.user_id ORDER BY cnt DESC LIMIT 10");
$top_ground_week->execute([$week_start, $week_end]);
$top_ground_week = $top_ground_week->fetchAll();

$top_air_week = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, COUNT(*) as cnt FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.type='air_kill' AND ce.occurred_at BETWEEN ? AND ? GROUP BY ce.user_id ORDER BY cnt DESC LIMIT 10");
$top_air_week->execute([$week_start, $week_end]);
$top_air_week = $top_air_week->fetchAll();

// Since date leaderboards reuse $since
$top_ground_since = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, COUNT(*) as cnt FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.type='ground_kill' AND ce.occurred_at >= ? GROUP BY ce.user_id ORDER BY cnt DESC LIMIT 10");
$top_ground_since->execute([$since]);
$top_ground_since = $top_ground_since->fetchAll();

$top_air_since = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, COUNT(*) as cnt FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.type='air_kill' AND ce.occurred_at >= ? GROUP BY ce.user_id ORDER BY cnt DESC LIMIT 10");
$top_air_since->execute([$since]);
$top_air_since = $top_air_since->fetchAll();

// Weekly totals (last 12 weeks) per type
$weekly_totals = $db->query("SELECT YEARWEEK(occurred_at,1) as yw, DATE_FORMAT(STR_TO_DATE(CONCAT(YEARWEEK(occurred_at,1),' Monday'), '%X%V %W'), '%Y-%m-%d') as week_start, type, COUNT(*) as cnt FROM combat_events GROUP BY YEARWEEK(occurred_at,1), type ORDER BY yw DESC LIMIT 24")->fetchAll();

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
        <div class="feedback-links">
          <a href="/feedback.php?type=feature" class="btn btn-secondary btn-sm">Submit Feature</a>
          <a href="/feedback.php?type=bug" class="btn btn-secondary btn-sm">Submit Bug</a>
        </div>
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
        <label style="display:inline-flex; align-items:center; gap:6px; color:var(--text-secondary);"><input type="checkbox" name="is_dazen" value="1"> Dazen kill</label>
        <input type="datetime-local" name="occurred_at" class="form-control">
        <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
        <button type="submit" class="btn btn-primary">Log</button>
      </form>
    </div>

    <div class="card">
      <h3>My Stats since <?php echo htmlspecialchars($since); ?></h3>
      <p>Ground kills: <strong><?php echo number_format($my_ground); ?></strong></p>
      <p>Air kills: <strong><?php echo number_format($my_air); ?></strong></p>
      <?php 
        $my_dazen = $db->prepare("SELECT COUNT(*) FROM combat_events WHERE user_id=? AND (target LIKE '%DAZEN%') AND occurred_at >= ?");
        $my_dazen->execute([$user['db_id'], $since]);
        $my_dazen = (int)$my_dazen->fetchColumn();
      ?>
      <p>Dazen kills: <strong><?php echo number_format($my_dazen); ?></strong></p>
    </div>

    <div class="card" style="margin-top:1.5rem;">
      <h3>Weekly Leaderboards</h3>
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
        <div>
          <h4>Top Ground (This Week)</h4>
          <ul>
            <?php foreach ($top_ground_week as $row): ?><li><?php echo htmlspecialchars($row['username']); ?> — <?php echo $row['cnt']; ?></li><?php endforeach; ?>
            <?php if (empty($top_ground_week)): ?><li class="empty-state">No data.</li><?php endif; ?>
          </ul>
        </div>
        <div>
          <h4>Top Air (This Week)</h4>
          <ul>
            <?php foreach ($top_air_week as $row): ?><li><?php echo htmlspecialchars($row['username']); ?> — <?php echo $row['cnt']; ?></li><?php endforeach; ?>
            <?php if (empty($top_air_week)): ?><li class="empty-state">No data.</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>

    <div class="card" style="margin-top:1.5rem;">
      <h3>Dazen Leaderboard (This Week)</h3>
      <ul>
        <?php 
          $dazen_week = $db->prepare("SELECT COALESCE(u.in_game_name, u.username) as username, COUNT(*) as cnt FROM combat_events ce JOIN users u ON ce.user_id=u.id WHERE ce.target LIKE '%DAZEN%' AND ce.occurred_at BETWEEN ? AND ? GROUP BY ce.user_id ORDER BY cnt DESC LIMIT 10");
          $dazen_week->execute([$week_start, $week_end]);
          $drows = $dazen_week->fetchAll();
          foreach ($drows as $row): ?>
            <li><?php echo htmlspecialchars($row['username']); ?> — <?php echo (int)$row['cnt']; ?></li>
        <?php endforeach; ?>
        <?php if (empty($drows)): ?><li class="empty-state">No data.</li><?php endif; ?>
      </ul>
    </div>

    <div class="card" style="margin-top:1.5rem;">
      <h3>Leaderboards Since <?php echo htmlspecialchars($since); ?></h3>
      <form method="GET" class="form-inline" style="margin-bottom:0.75rem;">
        <label for="since" class="form-label" style="margin:0 0.5rem 0 0;">Since</label>
        <input type="date" id="since" name="since" class="form-control" value="<?php echo htmlspecialchars($since); ?>">
        <button class="btn btn-secondary" type="submit">Update</button>
      </form>
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
        <div>
          <h4>Top Ground</h4>
          <ul>
            <?php foreach ($top_ground_since as $row): ?><li><?php echo htmlspecialchars($row['username']); ?> — <?php echo $row['cnt']; ?></li><?php endforeach; ?>
            <?php if (empty($top_ground_since)): ?><li class="empty-state">No data.</li><?php endif; ?>
          </ul>
        </div>
        <div>
          <h4>Top Air</h4>
          <ul>
            <?php foreach ($top_air_since as $row): ?><li><?php echo htmlspecialchars($row['username']); ?> — <?php echo $row['cnt']; ?></li><?php endforeach; ?>
            <?php if (empty($top_air_since)): ?><li class="empty-state">No data.</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>

    <div class="card" style="margin-top:1.5rem;">
      <h3>Weekly Totals (Last 12 Weeks)</h3>
      <div class="table-responsive">
        <table class="data-table"><thead><tr><th>Week Starting</th><th>Ground</th><th>Air</th></tr></thead><tbody>
          <?php 
          // reshape weekly totals per week
          $byWeek = [];
          foreach ($weekly_totals as $row) {
            $wk = $row['week_start'] ?: $row['yw'];
            if (!isset($byWeek[$wk])) $byWeek[$wk] = ['ground_kill'=>0,'air_kill'=>0];
            $byWeek[$wk][$row['type']] = (int)$row['cnt'];
          }
          foreach ($byWeek as $wk => $counts): ?>
            <tr>
              <td><?php echo htmlspecialchars($wk); ?></td>
              <td class="quantity"><?php echo number_format($counts['ground_kill']); ?></td>
              <td class="quantity"><?php echo number_format($counts['air_kill']); ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($byWeek)): ?><tr><td colspan="3" class="empty-state">No weekly data.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
    </div>
  </div>
</body>
</html>


