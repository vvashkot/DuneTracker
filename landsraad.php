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

// New: handle structured turn-in with calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'calc_turnin') {
    verifyPOST();
    try {
        $house = trim($_POST['house'] ?? '');
        $item_mode = $_POST['item_mode'] ?? 'existing'; // existing|new
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : null;
        $new_item_name = trim($_POST['new_item_name'] ?? '');
        $points_per_unit = intval($_POST['points_per_unit'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $bonus_pct = floatval($_POST['bonus_pct'] ?? 0.0); // e.g., 60 for 60%
        $occurred_at = $_POST['occurred_at2'] ?: date('Y-m-d H:i:s');
        $notes = trim($_POST['notes2'] ?? '');

        if ($quantity <= 0) { throw new Exception('Quantity required'); }

        // Resolve or create item
        if ($item_mode === 'new') {
            if ($new_item_name === '' || $points_per_unit <= 0) { throw new Exception('Item name and points required'); }
            $stmt = $db->prepare("INSERT INTO landsraad_items (name, points_per_unit, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$new_item_name, $points_per_unit, $user['db_id']]);
            $item_id = (int)$db->lastInsertId();
        } else {
            // existing
            if ($item_id) {
                $row = $db->prepare("SELECT points_per_unit FROM landsraad_items WHERE id=? AND active=1");
                $row->execute([$item_id]);
                $points_per_unit_db = $row->fetchColumn();
                if ($points_per_unit_db) { $points_per_unit = (int)$points_per_unit_db; }
            }
        }

        if ($points_per_unit <= 0) { throw new Exception('Invalid points per unit'); }

        // Compute total points: qty * ppu * (1 + bonus%)
        $total_points = (int)floor($quantity * $points_per_unit * (1 + ($bonus_pct/100.0)));

        // Category encodes house + item for quick context
        $category = $house ? ("House: " . $house) : null;
        $note_full = trim(($notes ? ($notes . ' | ') : '') . 'Item: ' . ($new_item_name ?: ('#' . $item_id)) . ', Qty: ' . $quantity . ', PPU: ' . $points_per_unit . ', Bonus%: ' . $bonus_pct);

        $stmt = $db->prepare("INSERT INTO landsraad_points (user_id, points, category, occurred_at, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user['db_id'], $total_points, $category, $occurred_at, $note_full]);

        $message = 'Turn-in added: ' . number_format($total_points) . ' points';
        $message_type = 'success';
    } catch (Throwable $e) {
        $message = 'Failed to add turn-in: ' . $e->getMessage();
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
      <h2>My Landsraad</h2>
      <div class="header-actions">
        <a href="/index.php" class="btn btn-secondary">Back to Dashboard</a>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem;">
      <h3>Add Landsraad Turn-in</h3>
      <form method="POST" class="form-inline">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="calc_turnin">
        <input type="text" name="house" class="form-control" placeholder="House (e.g., House Alexin)">
        <select name="item_mode" class="form-control" id="item_mode" onchange="toggleItemMode(this.value)">
          <option value="existing">Existing Item</option>
          <option value="new">Add New Item</option>
        </select>
        <select name="item_id" class="form-control" id="item_existing">
          <option value="">Select item…</option>
          <?php
            $items = $db->query("SELECT id, name, points_per_unit FROM landsraad_items WHERE active=1 ORDER BY name ASC")->fetchAll();
            foreach ($items as $it) {
              echo '<option value="' . (int)$it['id'] . '">' . htmlspecialchars($it['name']) . ' (' . (int)$it['points_per_unit'] . ' pts/ea)</option>';
            }
          ?>
        </select>
        <input type="text" name="new_item_name" class="form-control" id="item_new_name" placeholder="New item name" style="display:none;">
        <input type="number" name="points_per_unit" class="form-control" id="item_ppu" placeholder="Points per unit" style="display:none;">
        <input type="number" name="quantity" class="form-control" placeholder="Quantity" required>
        <input type="number" step="0.01" name="bonus_pct" class="form-control" placeholder="Bonus % (e.g., 60)">
        <input type="datetime-local" name="occurred_at2" class="form-control">
        <input type="text" name="notes2" class="form-control" placeholder="Notes (optional)">
        <button type="submit" class="btn btn-primary">Add</button>
      </form>
      <script>
        function toggleItemMode(val){
          var showNew = val === 'new';
          document.getElementById('item_existing').style.display = showNew ? 'none' : '';
          document.getElementById('item_new_name').style.display = showNew ? '' : 'none';
          document.getElementById('item_ppu').style.display = showNew ? '' : 'none';
        }
      </script>
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


