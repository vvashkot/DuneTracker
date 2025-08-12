<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireAdmin();
$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    try {
        if ($_POST['action'] === 'update_resource') {
            $resource_id = (int)($_POST['resource_id'] ?? 0);
            $current_stock = isset($_POST['current_stock']) ? (float)$_POST['current_stock'] : null;
            if ($resource_id > 0 && $current_stock !== null) {
                $stmt = $db->prepare("UPDATE resources SET current_stock = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$current_stock, $resource_id]);
                $message = 'Inventory updated.';
                $message_type = 'success';
            }
        }
    } catch (Throwable $e) {
        error_log('Inventory update failed: ' . $e->getMessage());
        $message = 'Failed to update inventory.';
        $message_type = 'error';
    }
}

$resources = $db->query("SELECT id, name, category, current_stock, total_withdrawn, (current_stock - total_withdrawn) AS available FROM resources ORDER BY category, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Inventory - Admin</title>
  <link rel="stylesheet" href="/css/style-v2.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <h1 class="nav-title">Admin • Inventory</h1>
      <div class="nav-user">
        <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" class="user-avatar" alt="Avatar">
        <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
        <a href="/admin/" class="btn btn-secondary">Admin</a>
        <a href="/index.php" class="btn btn-secondary">Dashboard</a>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <h2>Manage Guild Inventory</h2>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Category</th>
            <th>Resource</th>
            <th>Current Stock</th>
            <th>Total Withdrawn</th>
            <th>Available</th>
            <th>Update</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($resources as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['category'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars($r['name']); ?></td>
              <td>
                <form method="POST" class="form-inline" style="gap:0.5rem;">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="update_resource">
                  <input type="hidden" name="resource_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="number" step="0.01" name="current_stock" class="form-control" value="<?php echo htmlspecialchars((string)$r['current_stock']); ?>">
                  <button class="btn btn-primary btn-sm" type="submit">Save</button>
                </form>
              </td>
              <td class="quantity"><?php echo number_format((float)$r['total_withdrawn']); ?></td>
              <td class="quantity"><?php echo number_format((float)$r['available']); ?></td>
              <td>
                <small class="text-muted">Edit stock then Save</small>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>


