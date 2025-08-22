<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireLogin();
$user = getCurrentUser();
$db = getDB();

$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d 23:59:59', strtotime('sunday this week'));

// Read goals
$goals = $db->prepare("SELECT * FROM landsraad_group_goals WHERE week_start=? AND week_end>=? ORDER BY house_name ASC");
$goals->execute([$week_start, $week_start]);
$goals = $goals->fetchAll();

// Compute totals per house (using category 'House: X' convention)
$totals = [];
foreach ($goals as $g) { $totals[$g['house_name']] = 0; }
$stmt = $db->prepare("SELECT category, SUM(points) as pts FROM landsraad_points WHERE occurred_at BETWEEN ? AND ? AND category LIKE 'House:%' GROUP BY category");
$stmt->execute([$week_start, $week_end]);
foreach ($stmt->fetchAll() as $row) {
    if (preg_match('/House:\s*(.+)/', (string)$row['category'], $m)) {
        $h = trim($m[1]);
        $totals[$h] = ($totals[$h] ?? 0) + (int)$row['pts'];
    }
}

// Admin add goal
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='add_goal') {
    verifyPOST();
    if (isAdmin()) {
        try {
            $house = trim($_POST['house'] ?? '');
            $target = (int)($_POST['target_points'] ?? 0);
            if ($house !== '' && $target > 0) {
                $stmt = $db->prepare("INSERT INTO landsraad_group_goals (house_name, week_start, week_end, target_points, created_by) VALUES (?,?,?,?,?)");
                $stmt->execute([$house, $week_start, substr($week_end,0,10), $target, $user['db_id']]);
                header('Location: /landsraad-progress.php');
                exit();
            }
        } catch (Throwable $e) {
            $message = 'Failed to add goal';
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Landsraad Progress</title>
  <link rel="stylesheet" href="/css/style-v2.css">
  <style>
    .progress-container{background:#2b2f36;border-radius:6px;padding:0.5rem 0.75rem;margin:0.5rem 0}
    .progress-bar{height:16px;border-radius:4px;background:#3b82f6}
    .progress-track{background:#1f2430;border-radius:4px;overflow:hidden}
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <h1 class="nav-title"><?php echo htmlspecialchars(APP_NAME); ?></h1>
      <div class="nav-user">
        <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" class="user-avatar" alt="Avatar">
        <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
        <a href="/index.php" class="btn btn-secondary">Dashboard</a>
      </div>
    </div>
  </nav>
  <div class="container">
    <div class="page-header">
      <h2>Landsraad Progress (This Week)</h2>
    </div>
    <?php if ($message): ?><div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <div class="card">
      <?php foreach ($goals as $g):
        $house = $g['house_name'];
        $target = (int)$g['target_points'];
        $current = (int)($totals[$house] ?? 0);
        $pct = $target > 0 ? min(100, round($current*100/$target)) : 0;
        $barColor = $pct >= 100 ? '#16a34a' : ($pct >= 60 ? '#f59e0b' : '#ef4444');
      ?>
        <div class="progress-container">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.25rem;">
            <div style="font-weight:600;">
              <?php echo htmlspecialchars($house); ?>
            </div>
            <div style="color:var(--text-secondary);">
              <?php echo number_format($current) ?> / <?php echo number_format($target) ?> (<?php echo $pct ?>%)
            </div>
          </div>
          <div class="progress-track"><div class="progress-bar" style="width:<?php echo $pct; ?>%; background:<?php echo $barColor; ?>;"></div></div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($goals)): ?>
        <p class="empty-state">No goals set for this week yet.</p>
      <?php endif; ?>
    </div>

    <?php if (isAdmin()): ?>
    <div class="card" style="margin-top:1rem;">
      <h3>Set Weekly Goal</h3>
      <form method="POST" class="form-inline">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_goal">
        <input type="text" name="house" class="form-control" placeholder="House name" required>
        <input type="number" name="target_points" class="form-control" placeholder="Target points" required>
        <button class="btn btn-primary" type="submit">Add Goal</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</body>
</html>


