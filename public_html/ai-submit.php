<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireLogin();
$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';
$parsed = null;
$items = [];
$resolvedParticipants = [];

function callOpenAIExtract(string $text): array {
    $apiKey = getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null);
    if (!$apiKey) throw new Exception('Missing OPENAI_API_KEY');
    $url = 'https://api.openai.com/v1/chat/completions';
    $system = 'Extract structured data for a guild resource tracker. '
            . 'Return ONLY JSON with keys: items (array of {resource_name, quantity, notes?}), participants (array of strings), target (personal|group|run), split (equal|none), run_hint?. '
            . 'Infer integers; keep resource_name concise (matchable). If names in parentheses like (Cap/Kes/Vva) are present, output participants split by /.';
    $userMsg = 'Text: ' . $text . "\nRespond with JSON only.";
    $payload = [
        'model' => 'gpt-4o-mini',
        'temperature' => 0,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userMsg]
        ]
    ];

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];

    $resp = null; $code = 0; $err = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) { $err = curl_error($ch); }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($payload),
                'timeout' => 20,
            ]
        ]);
        $resp = @file_get_contents($url, false, $context);
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) { if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $h, $m)) { $code = (int)$m[1]; break; } }
        }
        if ($resp === false) { $err = 'stream_context request failed'; }
    }

    if ($resp === false || $resp === null) {
        error_log('AI request failed: ' . ($err ?: 'unknown'));
        throw new Exception('AI request failed');
    }
    if ($code < 200 || $code >= 300) {
        $body = json_decode($resp, true);
        $detail = $body['error']['message'] ?? ('HTTP ' . $code);
        error_log('AI request error: ' . $detail);
        throw new Exception('AI request error');
    }
    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    $jsonStart = strpos($content, '{');
    if ($jsonStart !== false) $content = substr($content, $jsonStart);
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        error_log('AI parse error. Raw content: ' . substr($content, 0, 300));
        throw new Exception('AI parse error');
    }
    return $parsed;
}

function findResourceIdByName(string $name) {
    $db = getDB();
    $nameLike = '%' . $name . '%';
    $stmt = $db->prepare("SELECT id FROM resources WHERE name LIKE ? ORDER BY CASE WHEN name = ? THEN 0 ELSE 1 END, CHAR_LENGTH(name) ASC LIMIT 1");
    $stmt->execute([$nameLike, $name]);
    return $stmt->fetchColumn() ?: null;
}

function resolveParticipantNames(array $names): array {
    $db = getDB();
    $resolved = [];
    foreach ($names as $nRaw) {
        $n = trim($nRaw);
        if ($n === '') continue;
        // Try exact in_game_name, then username, then LIKE
        $stmt = $db->prepare("SELECT id, COALESCE(in_game_name, username) as name FROM users WHERE in_game_name = ? OR username = ? LIMIT 1");
        $stmt->execute([$n, $n]);
        $row = $stmt->fetch();
        if (!$row) {
            $like = '%' . $n . '%';
            $stmt = $db->prepare("SELECT id, COALESCE(in_game_name, username) as name FROM users WHERE in_game_name LIKE ? OR username LIKE ? ORDER BY LENGTH(name) ASC LIMIT 1");
            $stmt->execute([$like, $like]);
            $row = $stmt->fetch();
        }
        if ($row) {
            $resolved[] = ['id' => (int)$row['id'], 'name' => $row['name']];
        } else {
            // keep unresolved placeholder
            $resolved[] = ['id' => null, 'name' => $n];
        }
    }
    return $resolved;
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
                $items = isset($parsed['items']) && is_array($parsed['items']) ? $parsed['items'] : [];
                if (empty($items)) {
                    // Backward compat: single item keys
                    $single = [
                        'resource_name' => (string)($parsed['resource_name'] ?? ''),
                        'quantity' => (int)($parsed['quantity'] ?? 0),
                        'notes' => (string)($parsed['notes'] ?? '')
                    ];
                    if (!empty($single['resource_name']) && $single['quantity'] > 0) $items = [$single];
                }
                $names = [];
                if (!empty($parsed['participants']) && is_array($parsed['participants'])) {
                    $names = $parsed['participants'];
                }
                $resolvedParticipants = resolveParticipantNames($names);
            } catch (Throwable $e) {
                error_log('AI analyze error: ' . $e->getMessage());
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
    } elseif ($_POST['action'] === 'save_multi') {
        // Save multiple items, possibly split among participants
        $rawItems = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
        $rawParticipants = json_decode($_POST['participants_json'] ?? '[]', true) ?: [];
        if (empty($rawItems)) {
            $message = 'Nothing to save.';
            $message_type = 'error';
        } else {
            // Resolve participants again server-side
            $participants = resolveParticipantNames($rawParticipants);
            $validUsers = array_values(array_filter($participants, fn($p) => !empty($p['id'])));
            $numUsers = count($validUsers);
            try {
                foreach ($rawItems as $it) {
                    $rname = trim((string)($it['resource_name'] ?? ''));
                    $qty = (int)($it['quantity'] ?? 0);
                    $notes = trim((string)($it['notes'] ?? ''));
                    if ($rname === '' || $qty <= 0) continue;
                    $rid = findResourceIdByName($rname);
                    if (!$rid) continue;
                    if ($numUsers > 0) {
                        $base = intdiv($qty, $numUsers);
                        $rem = $qty - ($base * $numUsers);
                        foreach ($validUsers as $idx => $p) {
                            $q = $base + ($idx < $rem ? 1 : 0);
                            if ($q > 0) {
                                addContribution($p['id'], (int)$rid, $q, $notes ? ($notes . ' (AI split)') : 'AI split');
                            }
                        }
                    } else {
                        addContribution($user['db_id'], (int)$rid, $qty, $notes ?: null);
                    }
                }
                $message = 'Submission saved.';
                $message_type = 'success';
            } catch (Throwable $e) {
                error_log('AI save_multi failed: ' . $e->getMessage());
                $message = 'Failed to save.';
                $message_type = 'error';
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
      $target = (string)($parsed['target'] ?? 'personal');
      $split = (string)($parsed['split'] ?? 'equal');
    ?>
      <div class="card" style="margin-top:1rem;">
        <h3>Review & Confirm</h3>
        <div class="table-responsive">
          <table class="data-table"><thead><tr><th>Resource</th><th>Quantity</th><th>Notes</th></tr></thead><tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)($it['resource_name'] ?? '')); ?></td>
                <td class="quantity"><?php echo number_format((int)($it['quantity'] ?? 0)); ?></td>
                <td><?php echo htmlspecialchars((string)($it['notes'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table>
        </div>
        <?php if (!empty($resolvedParticipants)): ?>
          <div style="margin:0.75rem 0; color:var(--text-secondary);">
            Participants: <?php echo htmlspecialchars(implode(', ', array_map(fn($p) => $p['name'], $resolvedParticipants))); ?>
            <?php if ($split === 'equal'): ?> — split equally<?php endif; ?>
          </div>
        <?php endif; ?>
        <form method="POST" style="margin-top:1rem;">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="save_multi">
          <input type="hidden" name="items_json" value='<?php echo json_encode($items); ?>'>
          <input type="hidden" name="participants_json" value='<?php echo json_encode(array_map(fn($p)=>$p['name'], $resolvedParticipants)); ?>'>
          <button class="btn btn-success" type="submit">Confirm & Save</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>


