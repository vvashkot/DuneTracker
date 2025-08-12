<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireLogin();
$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';
$parsed = null;

function callOpenAIExtract(string $text): array {
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) throw new Exception('Missing OPENAI_API_KEY');
    $url = 'https://api.openai.com/v1/chat/completions';
    $system = 'You extract structured data for a guild resource tracker. '
            . 'Return ONLY compact JSON with keys: action, resource_name, quantity, notes, target. '
            . "action must be one of: 'contribution' (default). target can be 'personal' or 'group' or 'run'. If 'run', include run_hint in notes. "
            . 'Infer integers for quantity. Resource names should be short canonical names found in the text.';
    $userMsg = 'Text: ' . $text . "\nRespond with JSON only.";
    $payload = [
        'model' => 'gpt-4o-mini',
        'temperature' => 0,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userMsg]
        ]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    if ($resp === false) throw new Exception('AI request failed');
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) throw new Exception('AI request error');
    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    $jsonStart = strpos($content, '{');
    if ($jsonStart !== false) $content = substr($content, $jsonStart);
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) throw new Exception('AI parse error');
    return $parsed;
}

function findResourceIdByName(string $name) {
    $db = getDB();
    $nameLike = '%' . $name . '%';
    $stmt = $db->prepare("SELECT id FROM resources WHERE name LIKE ? ORDER BY CASE WHEN name = ? THEN 0 ELSE 1 END, CHAR_LENGTH(name) ASC LIMIT 1");
    $stmt->execute([$nameLike, $name]);
    return $stmt->fetchColumn() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    if ($_POST['action'] === 'analyze') {
        $freeform = trim($_POST['freeform'] ?? '');
        if ($freeform === '') {
            $message = 'Please enter some text.';
            $message_type = 'error';
        } else {
            try {
                $parsed = callOpenAIExtract($freeform);
            } catch (Throwable $e) {
                $message = 'AI failed to analyze input.';
                $message_type = 'error';
            }
        }
    } elseif ($_POST['action'] === 'save') {
        $resource_name = trim($_POST['resource_name'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $target = $_POST['target'] ?? 'personal';
        if ($resource_name === '' || $quantity <= 0) {
            $message = 'Missing resource or quantity.';
            $message_type = 'error';
        } else {
            $rid = findResourceIdByName($resource_name);
            if (!$rid) {
                $message = 'Resource not found: ' . htmlspecialchars($resource_name);
                $message_type = 'error';
            } else {
                try {
                    if ($target === 'personal' || $target === '') {
                        addContribution($user['db_id'], (int)$rid, $quantity, $notes ?: null);
                    } else {
                        addContribution($user['db_id'], (int)$rid, $quantity, $notes ?: null);
                    }
                    $message = 'Submission saved.';
                    $message_type = 'success';
                } catch (Throwable $e) {
                    $message = 'Failed to save submission.';
                    $message_type = 'error';
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Submit with AI</title>
  <link rel="stylesheet" href="/css/style-v2.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <h1 class="nav-title">Submit with AI</h1>
      <div class="nav-user">
        <img src="<?php echo htmlspecialchars(getAvatarUrl($user)); ?>" alt="Avatar" class="user-avatar">
        <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
        <a href="/logout.php" class="btn btn-secondary">Logout</a>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <a href="/index.php" class="btn btn-secondary">Dashboard</a>
      <a href="/submit.php" class="btn btn-secondary">Submit</a>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card">
      <h3>Quick Add</h3>
      <p style="color:var(--text-secondary); font-size:0.9rem;">Describe what you want to log, e.g. “Added 10,000 Spice to the guild” or “Logged 5 filters to hub”.</p>
      <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="analyze">
        <textarea name="freeform" rows="4" class="form-control" placeholder="Type your note..."><?php echo htmlspecialchars($_POST['freeform'] ?? ''); ?></textarea>
        <button class="btn btn-primary" type="submit" style="margin-top:0.75rem;">Analyze</button>
      </form>
    </div>

    <?php if ($parsed): 
      $resource_name = trim((string)($parsed['resource_name'] ?? ''));
      $quantity = (int)($parsed['quantity'] ?? 0);
      $notes = trim((string)($parsed['notes'] ?? ''));
      $target = (string)($parsed['target'] ?? 'personal');
    ?>
      <div class="card" style="margin-top:1rem;">
        <h3>Review & Confirm</h3>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
          <div>
            <label>Resource</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars($resource_name); ?>" disabled>
          </div>
          <div>
            <label>Quantity</label>
            <input type="number" class="form-control" value="<?php echo htmlspecialchars((string)$quantity); ?>" disabled>
          </div>
          <div>
            <label>Target</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars($target ?: 'personal'); ?>" disabled>
          </div>
          <div>
            <label>Notes</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars($notes); ?>" disabled>
          </div>
        </div>
        <form method="POST" style="margin-top:1rem;">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="resource_name" value="<?php echo htmlspecialchars($resource_name); ?>">
          <input type="hidden" name="quantity" value="<?php echo (int)$quantity; ?>">
          <input type="hidden" name="target" value="<?php echo htmlspecialchars($target ?: 'personal'); ?>">
          <input type="hidden" name="notes" value="<?php echo htmlspecialchars($notes); ?>">
          <button class="btn btn-success" type="submit">Confirm & Save</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>


