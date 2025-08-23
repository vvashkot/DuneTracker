<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireLogin();
$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';

function clampInt($v, $min = 0) { $n = (int)$v; return $n < $min ? $min : $n; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    try {
        if ($_POST['action'] === 'log_stock') {
            $goal_id = (int)($_POST['goal_id'] ?? 0);
            $qty = clampInt($_POST['qty'] ?? 0, 1);
            $note = trim($_POST['note'] ?? '');
            if ($goal_id && $qty > 0) {
                $stmt = $db->prepare("INSERT INTO landsraad_goal_stock_logs (goal_id, user_id, qty, note) VALUES (?,?,?,?)");
                $stmt->execute([$goal_id, $user['db_id'], $qty, $note ?: null]);
                $message = 'Stock logged';
                $message_type = 'success';
            }
        }
    } catch (Throwable $e) {
        $message = 'Operation failed';
        $message_type = 'error';
    }
}

// Load active goals where the user is a member, plus progress
$goals = $db->prepare("SELECT g.* FROM landsraad_item_goals g JOIN landsraad_goal_members m ON g.id=m.goal_id WHERE g.active=1 AND m.user_id=? ORDER BY g.created_at DESC");
$goals->execute([$user['db_id']]);
$goals = $goals->fetchAll();

$progress = [];
if (!empty($goals)) {
    $ids = array_map(fn($g)=> (int)$g['id'], $goals);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT goal_id, COALESCE(SUM(qty),0) as qty FROM landsraad_goal_stock_logs WHERE goal_id IN ($place) GROUP BY goal_id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $r) { $progress[(int)$r['goal_id']] = (int)$r['qty']; }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Landsraad Goals</title>
  <link rel="stylesheet" href="/css/style-v2.css">
  <style>.bar{height:10px;background:#1f2430;border-radius:4px;overflow:hidden}.fill{height:100%;background:#16a34a}</style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <h1 class="nav-title"><?php echo htmlspecialchars(APP_NAME); ?></h1>
      <div class="nav-user">
        <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" alt="Avatar" class="user-avatar">
        <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
        <a href="/index.php" class="btn btn-secondary">Dashboard</a>
        <a href="/logout.php" class="btn btn-secondary">Logout</a>
      </div>
    </div>
  </nav>
  <div class="container">
    <div class="page-header">
      <h2>My Landsraad Goals</h2>
    </div>

    <?php if ($message): ?><div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

    <div class="card" style="padding:1rem;">
      <div class="table-responsive">
        <table class="data-table"><thead><tr><th>Item</th><th>PPU</th><th>Target</th><th>Required Qty</th><th>Collected</th><th>%</th><th>Log</th></tr></thead><tbody>
          <?php foreach ($goals as $g): $col = $progress[$g['id']] ?? 0; $pct = $g['required_qty']>0 ? min(100, round($col*100/$g['required_qty'])) : 0; ?>
            <tr>
              <td><?php if (!empty($g['icon_url'])): ?><img src="<?php echo htmlspecialchars($g['icon_url']); ?>" alt="" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><?php endif; ?><?php echo htmlspecialchars($g['item_name']); ?></td>
              <td class="quantity"><?php echo (int)$g['points_per_unit']; ?></td>
              <td class="quantity"><?php echo number_format($g['target_points']); ?></td>
              <td class="quantity"><?php echo number_format($g['required_qty']); ?></td>
              <td class="quantity"><?php echo number_format($col); ?></td>
              <td style="min-width:160px;">
                <div class="bar"><div class="fill" style="width:<?php echo $pct; ?>%"></div></div>
                <div style="font-size:0.8rem;color:var(--text-secondary);margin-top:4px;"><?php echo $pct; ?>%</div>
              </td>
              <td>
                <form method="POST" class="form-inline">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="log_stock">
                  <input type="hidden" name="goal_id" value="<?php echo $g['id']; ?>">
                  <input type="number" name="qty" class="form-control" min="1" placeholder="Qty" required>
                  <input type="text" name="note" class="form-control" placeholder="Note (optional)">
                  <button class="btn btn-primary btn-sm" type="submit">Add</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($goals)): ?><tr><td colspan="7" class="empty-state">No assigned goals yet.</td></tr><?php endif; ?>
        </tbody></table>
      </div>
    </div>
  </div>
</body>
</html>


