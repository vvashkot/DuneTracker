<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/webhooks.php';

requireLogin();
$user = getCurrentUser();

$message = '';
$message_type = '';

function saveUploadedImage(): ?string {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) return null;
    $file = $_FILES['image'];
    $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
    if (!isset($allowed[$file['type']])) return null;
    if ($file['size'] > 5*1024*1024) return null; // 5MB
    $ext = $allowed[$file['type']];
    $dir = __DIR__ . '/uploads/feedback/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path = $dir . $name;
    if (!move_uploaded_file($file['tmp_name'], $path)) return null;
    // Ensure the returned URL is absolute so Discord can fetch it
    $pathRel = '/uploads/feedback/' . $name;
    if (defined('APP_URL') && APP_URL) {
        $base = rtrim(APP_URL, '/');
        return $base . $pathRel;
    }
    return $pathRel;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type'])) {
    verifyPOST();
    $type = $_POST['type'] === 'bug' ? 'bug' : 'feature';
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $imgUrl = saveUploadedImage();
    if ($title === '' || $desc === '') {
        $message = 'Please provide a title and description.';
        $message_type = 'error';
    } else {
        try {
            if ($type === 'bug') {
                notifyBugReport($user, $title, $desc, $imgUrl);
            } else {
                notifyFeatureRequest($user, $title, $desc, $imgUrl);
            }
            $message = 'Thanks! Your ' . ($type==='bug'?'bug report':'feature request') . ' was submitted.';
            $message_type = 'success';
        } catch (Throwable $e) {
            error_log('Feedback submit failed: ' . $e->getMessage());
            $message = 'Failed to submit feedback. Please ask an admin to check logs.';
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
  <title>Submit Feedback</title>
  <link rel="stylesheet" href="/css/style-v2.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <h1 class="nav-title">Feedback</h1>
      <div class="nav-user">
        <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" class="user-avatar" alt="Avatar">
        <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
        <a href="/index.php" class="btn btn-secondary">Dashboard</a>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <h2>Submit a Feature or Bug</h2>
    </div>
    <?php if ($message): ?>
      <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <div class="card">
      <form method="POST" enctype="multipart/form-data" class="form">
        <?php echo csrfField(); ?>
        <div class="form-group">
          <label>Type</label>
          <select name="type" class="form-control">
            <option value="feature">Feature</option>
            <option value="bug">Bug</option>
          </select>
        </div>
        <div class="form-group">
          <label>Title</label>
          <input type="text" name="title" class="form-control" required>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" rows="4" class="form-control" required></textarea>
        </div>
        <div class="form-group">
          <label>Screenshot (optional)</label>
          <input type="file" name="image" accept="image/*" class="form-control">
          <small class="form-help">Max 5MB</small>
        </div>
        <button type="submit" class="btn btn-primary">Submit</button>
      </form>
    </div>
  </div>
</body>
</html>


