<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

requireLogin();
$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';

// Helpers (Hub weeks run Tue → Mon)
function getWeekStart(): string {
  $dow = (int)date('N'); // 1=Mon, 2=Tue, ... 7=Sun
  $dt = new DateTime();
  if ($dow === 1) { // Monday belongs to previous Tue-start week
    $dt->modify('last tuesday');
  } else {
    $dt->modify('tuesday this week');
  }
  return $dt->format('Y-m-d');
}
function getWeekEnd(): string {
  $start = new DateTime(getWeekStart());
  $start->modify('+6 days 23:59:59'); // through end of Monday
  return $start->format('Y-m-d H:i:s');
}

// Find Filters resource id if present
$filter_resource_id = null;
$stmt = $db->prepare("SELECT id FROM resources WHERE LOWER(name) LIKE 'filter%' ORDER BY id LIMIT 1");
$stmt->execute();
$filter_resource_id = $stmt->fetchColumn() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    try {
        switch ($_POST['action']) {
            case 'register_self':
                $week_start = getWeekStart();
                $stmt = $db->prepare("INSERT IGNORE INTO hub_registrations (user_id, week_start) VALUES (?, ?)");
                $stmt->execute([$user['db_id'], $week_start]);
                $message = 'Registered for this week';
                $message_type = 'success';
                break;
            case 'log_chore_self':
                $chore = $_POST['chore'] === 'move_in' ? 'move_in' : 'move_out';
                $occurred_at = $_POST['occurred_at'] ?: date('Y-m-d H:i:s');
                $notes = trim($_POST['notes'] ?? '');
                $stmt = $db->prepare("INSERT INTO hub_chore_logs (user_id, chore, occurred_at, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user['db_id'], $chore, $occurred_at, $notes ?: null]);
                $message = 'Chore logged';
                $message_type = 'success';
                break;
            case 'add_filter_quick':
                if ($filter_resource_id) {
                    $qty = max(1, intval($_POST['quantity'] ?? 1));
                    addContribution($user['db_id'], $filter_resource_id, $qty, 'Hub filter');
                    $message = 'Filter contribution recorded';
                    $message_type = 'success';
                }
                break;
            case 'save_roster_self':
                $week_start = getWeekStart();
                $role = trim($_POST['role'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                $stmt = $db->prepare("INSERT INTO circuit_roster (user_id, week_start, role, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role=VALUES(role), notes=VALUES(notes)");
                $stmt->execute([$user['db_id'], $week_start, $role ?: null, $notes ?: null]);
                $message = 'Circuit roster updated';
                $message_type = 'success';
                break;

            case 'assign_circuit':
                if (!isAdmin()) { throw new Exception('Forbidden'); }
                $week_start = getWeekStart();
                $circuit = (int)($_POST['circuit_number'] ?? 0);
                $assign_user_id = (int)($_POST['user_id'] ?? 0);
                if ($circuit < 1 || $circuit > 8 || $assign_user_id <= 0) { throw new Exception('Invalid input'); }
                // Free the slot for this week
                $stmt = $db->prepare("DELETE FROM circuit_roster WHERE week_start = ? AND circuit_number = ?");
                $stmt->execute([$week_start, $circuit]);
                // Upsert user row and set circuit number
                $stmt = $db->prepare("INSERT INTO circuit_roster (user_id, week_start, circuit_number) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE circuit_number = VALUES(circuit_number)");
                $stmt->execute([$assign_user_id, $week_start, $circuit]);
                $message = 'Circuit assigned';
                $message_type = 'success';
                break;

            case 'clear_circuit':
                if (!isAdmin()) { throw new Exception('Forbidden'); }
                $week_start = getWeekStart();
                $circuit = (int)($_POST['circuit_number'] ?? 0);
                if ($circuit >= 1 && $circuit <= 8) {
                    $stmt = $db->prepare("DELETE FROM circuit_roster WHERE week_start = ? AND circuit_number = ?");
                    $stmt->execute([$week_start, $circuit]);
                    $message = 'Circuit cleared';
                    $message_type = 'success';
                }
                break;
        }
    } catch (Throwable $e) {
        $message = 'Operation failed';
        $message_type = 'error';
    }
}

// Settings and stats
$filters_required = (int)($db->query("SELECT setting_value FROM guild_settings WHERE setting_key='filters_required_per_week'")->fetchColumn() ?: 0);
$week_start = getWeekStart();
$week_end = getWeekEnd();

$filters_collected = 0;
if ($filter_resource_id) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM contributions WHERE user_id=? AND resource_id=? AND DATE(date_collected) BETWEEN ? AND ?");
    $stmt->execute([$user['db_id'], $filter_resource_id, $week_start, substr($week_end,0,10)]);
    $filters_collected = (int)$stmt->fetchColumn();
}

$my_chores = $db->prepare("SELECT * FROM hub_chore_logs WHERE user_id=? ORDER BY occurred_at DESC LIMIT 50");
$my_chores->execute([$user['db_id']]);
$my_chores = $my_chores->fetchAll();

// Load this week's roster (all users)
$roster_stmt = $db->prepare("SELECT c.*, COALESCE(u.in_game_name, u.username) as username FROM circuit_roster c JOIN users u ON c.user_id=u.id WHERE week_start=? ORDER BY username");
$roster_stmt->execute([$week_start]);
$roster_all = $roster_stmt->fetchAll();
$circuit_map = [];
foreach ($roster_all as $r) {
  if (!empty($r['circuit_number'])) {
    $circuit_map[(int)$r['circuit_number']] = $r;
  }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Hub</title>
  <link rel="stylesheet" href="/css/style-v2.css">
  <style>.form-inline .form-control{width:auto;min-width:200px;max-width:280px;}</style>
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
      <h2>My Hub</h2>
      <div class="header-actions">
        <a href="/index.php" class="btn btn-secondary">Back to Dashboard</a>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem;">
      <h3>Weekly Registration</h3>
      <p>Current week starts <?php echo date('M d', strtotime($week_start)); ?>.</p>
      <form method="POST" class="form-inline">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="register_self">
        <button class="btn btn-primary" type="submit">Register Me</button>
      </form>
    </div>

    <div class="card" style="margin-bottom:1.5rem;">
      <h3>Filters Progress</h3>
      <p>Collected this week: <strong><?php echo number_format($filters_collected); ?></strong><?php if ($filters_required>0): ?> / <?php echo number_format($filters_required); ?> required<?php endif; ?></p>
      <div class="form-inline">
        <?php if ($filter_resource_id): ?>
        <form method="POST">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="add_filter_quick">
          <input type="number" class="form-control" name="quantity" min="1" value="1">
          <button class="btn btn-primary" type="submit">Add Filter</button>
          <a href="/submit.php" class="btn btn-secondary">Open Submit</a>
        </form>
        <?php else: ?>
          <p style="color:var(--text-secondary);">No 'Filter' resource found. Add it in Resources.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h3>My Move In/Out Logs</h3>
      <form method="POST" class="form-inline">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="log_chore_self">
        <select name="chore" class="form-control"><option value="move_out">Move Out</option><option value="move_in">Move In</option></select>
        <input type="datetime-local" name="occurred_at" class="form-control">
        <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
        <button type="submit" class="btn btn-primary">Log</button>
      </form>
      <div class="table-responsive" style="margin-top:1rem;">
        <table class="data-table"><thead><tr><th>When</th><th>Type</th><th>Notes</th></tr></thead><tbody>
          <?php foreach ($my_chores as $c): ?>
            <tr>
              <td><?php echo date('M d, Y H:i', strtotime($c['occurred_at'])); ?></td>
              <td><?php echo $c['chore']==='move_in'?'Move In':'Move Out'; ?></td>
              <td><?php echo htmlspecialchars($c['notes'] ?? '-'); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>

    <div class="card" style="margin-top:1.5rem;">
      <h3>Circuit Roster (This Week)</h3>
      <p style="color:var(--text-secondary); font-size:0.9rem;">Circuits are numbered 1–8. Only admins can assign circuits to users.</p>
      <div class="table-responsive" style="margin-bottom:1rem;">
        <table class="data-table"><thead><tr><th>Circuit</th><th>Assigned User</th><th>Notes</th><?php if (isAdmin()): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
          <?php for ($i=1; $i<=8; $i++): $row = $circuit_map[$i] ?? null; ?>
            <tr>
              <td>#<?php echo $i; ?></td>
              <td><?php echo $row ? htmlspecialchars($row['username']) : '<span class="empty-state">Unassigned</span>'; ?></td>
              <td><?php echo $row ? htmlspecialchars($row['notes'] ?? '-') : '-'; ?></td>
              <?php if (isAdmin()): ?>
              <td>
                <form method="POST" class="form-inline" style="gap:0.5rem;">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="assign_circuit">
                  <input type="hidden" name="circuit_number" value="<?php echo $i; ?>">
                  <select name="user_id" class="form-control" style="min-width:200px;">
                    <option value="">Select user…</option>
                    <?php foreach (getAllGuildMembers() as $m): ?>
                      <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['in_game_name'] ?: $m['username']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-primary btn-sm" type="submit">Assign</button>
                </form>
                <?php if ($row): ?>
                  <form method="POST" style="display:inline-block; margin-top:0.25rem;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="clear_circuit">
                    <input type="hidden" name="circuit_number" value="<?php echo $i; ?>">
                    <button class="btn btn-secondary btn-sm" type="submit">Clear</button>
                  </form>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endfor; ?>
        </tbody></table>
      </div>

      <!-- Users can still save their own role/notes (no circuit assignment) -->
      <form method="POST" class="form-inline" style="margin-bottom:0.5rem;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_roster_self">
        <input type="text" name="role" class="form-control" placeholder="Your role (optional)">
        <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
        <button type="submit" class="btn btn-primary">Save My Entry</button>
      </form>
    </div>
  </div>
</body>
</html>


