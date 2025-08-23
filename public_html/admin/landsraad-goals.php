<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdmin();

$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';

function clampInt($v, $min = 0) { $n = (int)$v; return $n < $min ? $min : $n; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    try {
        switch ($_POST['action']) {
            case 'create_goal':
                $item = trim($_POST['item_name'] ?? '');
                $ppu = clampInt($_POST['points_per_unit'] ?? 0, 1);
                $target = clampInt($_POST['target_points'] ?? 0, 1);
                $icon = trim($_POST['icon_url'] ?? '');
                if ($item === '' || $ppu <= 0 || $target <= 0) throw new Exception('Missing fields');
                $required = (int)ceil($target / $ppu);
                $stmt = $db->prepare("INSERT INTO landsraad_item_goals (item_name, points_per_unit, target_points, required_qty, icon_url, active, created_by) VALUES (?,?,?,?,?,1,?)");
                $stmt->execute([$item, $ppu, $target, $required, $icon ?: null, $user['db_id']]);
                $message = 'Goal created'; $message_type = 'success';
                break;
            case 'add_member':
                $goal = (int)($_POST['goal_id'] ?? 0);
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($goal && $uid) {
                    $stmt = $db->prepare("INSERT IGNORE INTO landsraad_goal_members (goal_id, user_id) VALUES (?,?)");
                    $stmt->execute([$goal, $uid]);
                    $message = 'Member added'; $message_type = 'success';
                }
                break;
            case 'remove_member':
                $goal = (int)($_POST['goal_id'] ?? 0);
                $uid = (int)($_POST['user_id'] ?? 0);
                $stmt = $db->prepare("DELETE FROM landsraad_goal_members WHERE goal_id=? AND user_id=?");
                $stmt->execute([$goal, $uid]);
                $message = 'Member removed'; $message_type = 'success';
                break;
            case 'add_stock':
                $goal = (int)($_POST['goal_id'] ?? 0);
                $uid = (int)($_POST['user_id'] ?? 0);
                $qty = clampInt($_POST['qty'] ?? 0, 1);
                $note = trim($_POST['note'] ?? '');
                if ($goal && $uid && $qty > 0) {
                    $stmt = $db->prepare("INSERT INTO landsraad_goal_stock_logs (goal_id, user_id, qty, note) VALUES (?,?,?,?)");
                    $stmt->execute([$goal, $uid, $qty, $note ?: null]);
                    $message = 'Stock logged'; $message_type = 'success';
                }
                break;
            case 'archive_goal':
                $goal = (int)($_POST['goal_id'] ?? 0);
                $stmt = $db->prepare("UPDATE landsraad_item_goals SET active=0 WHERE id=?");
                $stmt->execute([$goal]);
                $message = 'Goal archived'; $message_type = 'success';
                break;
            case 'reset_goal':
                $goal = (int)($_POST['goal_id'] ?? 0);
                // Duplicate goal and keep members, archive old one
                $g = $db->prepare("SELECT * FROM landsraad_item_goals WHERE id=?");
                $g->execute([$goal]);
                $row = $g->fetch();
                if ($row) {
                    $db->beginTransaction();
                    $ins = $db->prepare("INSERT INTO landsraad_item_goals (item_name, points_per_unit, target_points, required_qty, icon_url, active, created_by) VALUES (?,?,?,?,?,1,?)");
                    $ins->execute([$row['item_name'], $row['points_per_unit'], $row['target_points'], $row['required_qty'], $row['icon_url'], $user['db_id']]);
                    $newId = (int)$db->lastInsertId();
                    $ms = $db->prepare("SELECT user_id FROM landsraad_goal_members WHERE goal_id=?");
                    $ms->execute([$goal]);
                    $addm = $db->prepare("INSERT IGNORE INTO landsraad_goal_members (goal_id, user_id) VALUES (?,?)");
                    foreach ($ms->fetchAll() as $m) { $addm->execute([$newId, (int)$m['user_id']]); }
                    $db->prepare("UPDATE landsraad_item_goals SET active=0 WHERE id=?")->execute([$goal]);
                    $db->commit();
                    $message = 'Goal reset'; $message_type = 'success';
                }
                break;
        }
    } catch (Throwable $e) {
        $message = 'Operation failed';
        $message_type = 'error';
    }
}

// Load goals with progress
$goals = $db->query("SELECT * FROM landsraad_item_goals ORDER BY active DESC, created_at DESC")->fetchAll();
$progress = [];
if (!empty($goals)) {
    $ids = array_map(fn($g)=> (int)$g['id'], $goals);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT goal_id, COALESCE(SUM(qty),0) as qty FROM landsraad_goal_stock_logs WHERE goal_id IN ($place) GROUP BY goal_id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $r) { $progress[(int)$r['goal_id']] = (int)$r['qty']; }
}
$members = getAllGuildMembers();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Landsraad Goals - Admin</title>
  <link rel="stylesheet" href="/css/style-v2.css">
  <style>.bar{height:10px;background:#1f2430;border-radius:4px;overflow:hidden}.fill{height:100%;background:#16a34a}</style>
  <style>.form-inline .form-control{width:auto;min-width:200px;max-width:280px;}</style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <h1 class="nav-title"><?php echo htmlspecialchars(APP_NAME); ?> - Admin</h1>
      <div class="nav-user">
        <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" class="user-avatar" alt="Avatar">
        <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
        <a href="/admin/" class="btn btn-secondary">Admin Home</a>
        <a href="/logout.php" class="btn btn-secondary">Logout</a>
      </div>
    </div>
  </nav>
  <div class="container">
    <?php if ($message): ?><div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

    <div class="page-header">
      <h2>Landsraad Item Goals</h2>
    </div>

    <div class="card" style="margin-bottom:1rem;">
      <h3>Create Goal</h3>
      <form method="POST" class="form-inline">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="create_goal">
        <input type="text" name="item_name" class="form-control" placeholder="Item name (e.g., Adept Dirk)" required>
        <input type="number" name="points_per_unit" class="form-control" placeholder="Points per unit" required>
        <input type="number" name="target_points" class="form-control" placeholder="Target points (e.g., 70000)" required>
        <input type="url" name="icon_url" class="form-control" placeholder="Icon URL (optional)">
        <button class="btn btn-primary" type="submit">Create</button>
      </form>
    </div>

    <div class="card" style="padding:1rem;">
      <h3>Goals</h3>
      <div class="table-responsive">
        <table class="data-table"><thead><tr><th>Item</th><th>PPU</th><th>Target</th><th>Required Qty</th><th>Collected</th><th>%</th><th>Actions</th></tr></thead><tbody>
          <?php foreach ($goals as $g): $col = $progress[$g['id']] ?? 0; $pct = $g['required_qty']>0 ? min(100, round($col*100/$g['required_qty'])) : 0; ?>
            <tr>
              <td><?php if (!empty($g['icon_url'])): ?><img src="<?php echo htmlspecialchars($g['icon_url']); ?>" alt="" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><?php endif; ?><?php echo htmlspecialchars($g['item_name']); ?><?php if (!$g['active']): ?> <span class="tag" style="color:var(--text-secondary);">(archived)</span><?php endif; ?></td>
              <td class="quantity"><?php echo (int)$g['points_per_unit']; ?></td>
              <td class="quantity"><?php echo number_format($g['target_points']); ?></td>
              <td class="quantity"><?php echo number_format($g['required_qty']); ?></td>
              <td class="quantity"><?php echo number_format($col); ?></td>
              <td style="min-width:160px;">
                <div class="bar"><div class="fill" style="width:<?php echo $pct; ?>%"></div></div>
                <div style="font-size:0.8rem;color:var(--text-secondary);margin-top:4px;"><?php echo $pct; ?>%</div>
              </td>
              <td>
                <form method="POST" class="form-inline" style="gap:0.5rem;">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="goal_id" value="<?php echo $g['id']; ?>">
                  <input type="hidden" name="action" value="<?php echo $g['active']? 'archive_goal':'activate_goal'; ?>">
                  <?php if ($g['active']): ?>
                    <button class="btn btn-secondary btn-sm" formaction="" name="action" value="archive_goal" type="submit">Archive</button>
                    <button class="btn btn-primary btn-sm" formaction="" name="action" value="reset_goal" type="submit" onclick="return confirm('Reset this goal? This archives current logs and starts fresh.')">Reset</button>
                  <?php else: ?>
                    <span class="tag" style="color:var(--text-secondary);">Archived</span>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
            <tr>
              <td colspan="7">
                <div style="display:flex; gap:1rem; align-items:center;">
                  <form method="POST" class="form-inline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_member">
                    <input type="hidden" name="goal_id" value="<?php echo $g['id']; ?>">
                    <select name="user_id" class="form-control">
                      <option value="">Add member…</option>
                      <?php foreach ($members as $m): ?>
                        <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['in_game_name'] ?? $m['username']); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-secondary btn-sm" type="submit">Add</button>
                  </form>
                  <form method="POST" class="form-inline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_stock">
                    <input type="hidden" name="goal_id" value="<?php echo $g['id']; ?>">
                    <select name="user_id" class="form-control">
                      <?php foreach ($members as $m): ?>
                        <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['in_game_name'] ?? $m['username']); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input type="number" name="qty" class="form-control" min="1" placeholder="Qty">
                    <input type="text" name="note" class="form-control" placeholder="Note (optional)">
                    <button class="btn btn-primary btn-sm" type="submit">Log</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($goals)): ?><tr><td colspan="7" class="empty-state">No goals</td></tr><?php endif; ?>
        </tbody></table>
      </div>
    </div>
  </div>
</body>
</html>


