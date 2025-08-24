<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ai-submission.php';

requireLogin();
$user = getCurrentUser();
$db = getDB();

$message = '';
$message_type = '';
$parsed = null;
$items = [];
$resolvedParticipants = [];
$shares = [];
$refined = [];
$refinery_note = '';
$landsraad = [];
$missing_resources = [];
$withdrawals = [];

// Functions moved to includes/ai-submission.php
/*
function callOpenAIExtract(string $text): array {
    $apiKey = getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null);
    if (!$apiKey) throw new Exception('Missing OPENAI_API_KEY');
    $url = 'https://api.openai.com/v1/chat/completions';
    $system = 'Extract structured data for a guild resource tracker. '
            . 'Return ONLY JSON with keys: '
            . 'items (array of {resource_name, quantity, notes?}), '
            . 'participants (array of strings), target (personal|group|run), split (equal|weighted|none), shares (object mapping participant name to fraction between 0 and 1; only if split is weighted), run_hint?, '
            . 'landsraad (array of entries, each {house?, item_name?, quantity?, points_per_unit?, bonus_pct?, total_points?, notes?}), '
            . 'withdrawals (array of {resource_name, quantity, purpose?, notes?}). '
            . 'Infer integers; keep resource_name concise (matchable). If names in parentheses like (Cap/Kes/Vva) are present, output participants split by /. '
            . 'If text contains patterns like "split by Cap 50%, Kes 30%, Vva 20%" or similar, set split="weighted" and shares to {"Cap":0.5,"Kes":0.3,"Vva":0.2} (normalized to sum 1). Otherwise set split="equal" when participants are provided without explicit weights.';
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

function normalizeResourceName(string $name): string {
    $n = strtolower(trim($name));
    $n = preg_replace('/[^a-z0-9]+/i', '', $n);
    // Common aliases/abbreviations
    $aliases = [
        // Titanium
        '/^(ti|tit|tita|titan|titanium|t)$/' => 'titanium',
        // Stravidium
        '/^(strav|stra|stravid|stravidium|s)$/' => 'stravidium',
        // Spice
        '/^(sp|spi|spice)$/' => 'spice',
        // Melange
        '/^(mel|melan|melange)$/' => 'melange',
        // Plastanium
        '/^(plas|plast|plastan|plastanium|pla)$/' => 'plastanium',
        // Filters (explicit mapping)
        '/^(advfilter|advfilters|advancedfilter|advancedfilters|advancedparticulatefilters?|apf)$/' => 'advanced particulate filter',
        '/^(filter|filters|fil|particulatefilters?)$/' => 'particulate filter',
    ];
    foreach ($aliases as $pattern => $target) {
        if (preg_match($pattern, $n)) return $target;
    }
    return $name;
}

function findResourceIdByName(string $name) {
    $db = getDB();
    $canon = normalizeResourceName($name);
    // Special-case filters: default "filters" to particulate filter, "adv" to advanced particulate filter
    $lcraw = strtolower(trim($name));
    if (preg_match('/\badv(anced)?\b.*\bfilters?\b/', $lcraw)) {
        $canon = 'advanced particulate filter';
    } elseif (preg_match('/\bfilters?\b/', $lcraw) && !preg_match('/\badv(anced)?\b/', $lcraw)) {
        $canon = 'particulate filter';
    }
    $lc = strtolower($canon);
    $starts = $lc . '%';
    $contains = '%' . $lc . '%';
    $stmt = $db->prepare(
        "SELECT id FROM resources 
         WHERE LOWER(name) = ? OR LOWER(name) LIKE ? OR LOWER(name) LIKE ?
         ORDER BY 
           CASE WHEN LOWER(name) = ? THEN 0 
                WHEN LOWER(name) LIKE ? THEN 1 
                ELSE 2 END,
           CHAR_LENGTH(name) ASC
         LIMIT 1"
    );
    $stmt->execute([$lc, $starts, $contains, $lc, $starts]);
    return $stmt->fetchColumn() ?: null;
}

function resolveResource(array $item): array {
    $name = (string)($item['resource_name'] ?? '');
    $rid = findResourceIdByName($name);
    if ($rid) {
        $db = getDB();
        $stmt = $db->prepare("SELECT name FROM resources WHERE id = ?");
        $stmt->execute([$rid]);
        $resolvedName = $stmt->fetchColumn() ?: $name;
        $item['resolved_id'] = (int)$rid;
        $item['resolved_name'] = $resolvedName;
    } else {
        $item['resolved_id'] = null;
        $item['resolved_name'] = $name;
    }
    return $item;
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

function normalizeKey(string $s): string {
    return preg_replace('/[^a-z0-9]+/i', '', strtolower($s));
}

function scoreNameMatch(string $key, string $candidate): int {
    $k = normalizeKey($key);
    $c = normalizeKey($candidate);
    if ($k === '' || $c === '') return 0;
    if (str_starts_with($c, $k)) return 3;      // best: key is a prefix of candidate
    if (str_contains($c, $k)) return 2;        // good: key appears inside candidate
    if (levenshtein($k, substr($c, 0, min(strlen($c), strlen($k)))) <= 1) return 1; // fuzzy
    return 0;
}

function mapSharesToParticipants(array $rawShares, array $participants): array {
    // rawShares: name => fraction or percent; participants: [ ['id'=>, 'name'=>], ... ]
    $weights = [];
    foreach ($rawShares as $name => $value) {
        $w = is_numeric($value) ? floatval($value) : 0.0;
        if ($w <= 0) continue;
        if ($w > 1.0 && $w <= 100.0) $w = $w / 100.0; // percent → fraction
        // find best participant match for this share name
        $bestUid = null; $bestScore = 0;
        foreach ($participants as $p) {
            if (empty($p['id'])) continue;
            $score = scoreNameMatch((string)$name, (string)$p['name']);
            if ($score > $bestScore) { $bestScore = $score; $bestUid = (int)$p['id']; }
        }
        if ($bestUid !== null) {
            $weights[$bestUid] = ($weights[$bestUid] ?? 0.0) + $w;
        }
    }
    return $weights;
}
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyPOST();
    if ($_POST['action'] === 'add_missing') {
        // Admin-only: add missing resources quickly
        if (!isAdmin()) {
            $message = 'Only admins can add new resources.';
            $message_type = 'error';
        } else {
            try {
                $names = $_POST['res_name'] ?? [];
                $cats = $_POST['res_category'] ?? [];
                $db = getDB();
                foreach ((array)$names as $idx => $n) {
                    $name = trim((string)$n);
                    if ($name === '') continue;
                    $cat = in_array(($cats[$idx] ?? 'Raw Materials'), ['Raw Materials','Refined']) ? $cats[$idx] : 'Raw Materials';
                    try {
                        $stmt = $db->prepare("INSERT INTO resources (name, category) VALUES (?, ?)");
                        $stmt->execute([$name, $cat]);
                    } catch (Throwable $e) {
                        // ignore duplicates
                    }
                }
                // Re-run analyze with prior text
                $_POST['action'] = 'analyze';
                $_POST['freeform'] = $_POST['freeform_prev'] ?? '';
            } catch (Throwable $e) {
                $message = 'Failed to add resources.';
                $message_type = 'error';
            }
        }
    }
    if ($_POST['action'] === 'analyze') {
        $freeform = trim($_POST['freeform'] ?? '');
        if ($freeform === '') {
            $message = 'Please enter some text.';
            $message_type = 'error';
        } else {
            $result = processAISubmission($freeform, $user);
            if ($result['success']) {
                $parsed = $result['parsed'];
                $items = $result['items'];
                $refined = $result['refined'];
                $landsraad = $result['landsraad'];
                $withdrawals = $result['withdrawals'];
                $resolvedParticipants = $result['participants'];
                $shares = $result['shares'];
                $missing_resources = $result['missing_resources'];
                $refinery_note = $result['refinery_note'];
            } else {
                $message = $result['message'];
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
        $rawShares = json_decode($_POST['shares_json'] ?? 'null', true);
        $landsraadRaw = json_decode($_POST['landsraad_json'] ?? '[]', true) ?: [];
        $withdrawalsRaw = json_decode($_POST['withdrawals_json'] ?? '[]', true) ?: [];
        
        if (empty($rawItems) && empty($landsraadRaw) && empty($withdrawalsRaw)) {
            $message = 'Nothing to save.';
            $message_type = 'error';
        } else {
            $result = saveAISubmission($rawItems, $rawParticipants, $rawShares, $landsraadRaw, $withdrawalsRaw, $user);
            if ($result['success']) {
                $message = $result['message'];
                $message_type = 'success';
            } else {
                $message = $result['message'];
                $message_type = 'error';
                if (!empty($result['missing_resources'])) {
                    $missing_resources = $result['missing_resources'];
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
      <a href="/index.php" class="btn btn-secondary">Dashboard</a>
      <a href="/submit.php" class="btn btn-secondary">Submit</a>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card">
      <h3>Quick Add</h3>
      <p style="color:var(--text-secondary); font-size:0.9rem;">Describe what you want to log, e.g. “Added 10,000 Spice to the guild”, “Logged 5 filters to hub”, or “I contributed 10,500 points to the Landsraad this week for House Alexis”.</p>
      <form method="POST">
        <?php echo csrfField(); ?>
        <?php if (!empty($missing_resources)): ?>
          <input type="hidden" name="action" value="add_missing">
          <input type="hidden" name="freeform_prev" value="<?php echo htmlspecialchars($_POST['freeform'] ?? ''); ?>">
          <div class="alert alert-error" style="margin-bottom:0.75rem;">Some resources are not in the database. Add them and re-run analyze:</div>
          <?php foreach ($missing_resources as $idx => $rname): ?>
            <div class="form-inline" style="gap:0.5rem; margin-bottom:0.5rem;">
              <input type="text" name="res_name[]" class="form-control" value="<?php echo htmlspecialchars($rname); ?>">
              <select name="res_category[]" class="form-control"><option value="Raw Materials">Raw Materials</option><option value="Refined">Refined</option></select>
            </div>
          <?php endforeach; ?>
          <button class="btn btn-primary" type="submit">Add Resources & Re-Analyze</button>
        <?php else: ?>
          <input type="hidden" name="action" value="analyze">
          <textarea name="freeform" rows="4" class="form-control" placeholder="Type your note..."><?php echo htmlspecialchars($_POST['freeform'] ?? ''); ?></textarea>
          <button class="btn btn-primary" type="submit" style="margin-top:0.75rem;">Analyze</button>
        <?php endif; ?>
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
                <td><?php echo htmlspecialchars((string)($it['resolved_name'] ?? ($it['resource_name'] ?? ''))); ?></td>
                <td class="quantity"><?php echo number_format((int)($it['quantity'] ?? 0)); ?></td>
                <td><?php echo htmlspecialchars((string)($it['notes'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table>
        </div>
        <?php if (!empty($refined)): ?>
        <div class="table-responsive" style="margin-top:1rem;">
          <h4>Refined Outputs <?php echo htmlspecialchars($refinery_note); ?></h4>
          <table class="data-table"><thead><tr><th>Output</th><th>Quantity</th><th>Notes</th></tr></thead><tbody>
            <?php foreach ($refined as $it): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)($it['resolved_name'] ?? ($it['resource_name'] ?? ''))); ?></td>
                <td class="quantity"><?php echo number_format((int)($it['quantity'] ?? 0)); ?></td>
                <td><?php echo htmlspecialchars((string)($it['notes'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table>
        </div>
        <?php endif; ?>
        <?php if (!empty($landsraad)): ?>
        <div class="table-responsive" style="margin-top:1rem;">
          <h4>Landsraad Turn-ins</h4>
          <table class="data-table"><thead><tr><th>House</th><th>Item</th><th>Qty</th><th>PPU</th><th>Bonus%</th><th>Total Points</th></tr></thead><tbody>
            <?php foreach ($landsraad as $lr): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)($lr['house'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($lr['item_name'] ?? '')); ?></td>
                <td class="quantity"><?php echo number_format((int)($lr['quantity'] ?? 0)); ?></td>
                <td class="quantity"><?php echo number_format((int)($lr['points_per_unit'] ?? 0)); ?></td>
                <td class="quantity"><?php echo (float)($lr['bonus_pct'] ?? 0); ?></td>
                <td class="quantity"><?php echo number_format((int)($lr['total_points'] ?? 0)); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table>
        </div>
        <?php endif; ?>
        <?php if (!empty($withdrawals)): ?>
        <div class="table-responsive" style="margin-top:1rem;">
          <h4>Withdrawals</h4>
          <table class="data-table"><thead><tr><th>Resource</th><th>Quantity</th><th>Purpose</th><th>Notes</th></tr></thead><tbody>
            <?php foreach ($withdrawals as $wit): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)($wit['resolved_name'] ?? ($wit['resource_name'] ?? ''))); ?></td>
                <td class="quantity">-<?php echo number_format((float)($wit['quantity'] ?? 0), 2); ?></td>
                <td><?php echo htmlspecialchars((string)($wit['purpose'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($wit['notes'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table>
        </div>
        <?php endif; ?>
        <?php if (!empty($resolvedParticipants)): ?>
          <div style="margin:0.75rem 0; color:var(--text-secondary);">
            Participants: <?php echo htmlspecialchars(implode(', ', array_map(fn($p) => $p['name'], $resolvedParticipants))); ?>
            <?php if ($split === 'equal'): ?> — split equally<?php elseif ($split === 'weighted' && !empty($shares)): ?> — weighted: 
              <?php 
                $parts = [];
                foreach ($shares as $k=>$v) { $parts[] = htmlspecialchars($k) . ' ' . round($v*100) . '%'; }
                echo htmlspecialchars(implode(', ', $parts));
              ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <form method="POST" style="margin-top:1rem;">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="save_multi">
          <input type="hidden" name="items_json" value='<?php echo json_encode($items); ?>'>
          <input type="hidden" name="participants_json" value='<?php echo json_encode(array_map(fn($p)=>$p['name'], $resolvedParticipants)); ?>'>
          <input type="hidden" name="shares_json" value='<?php echo json_encode($shares); ?>'>
          <input type="hidden" name="refined_json" value='<?php echo json_encode($refined); ?>'>
          <input type="hidden" name="landsraad_json" value='<?php echo json_encode($landsraad); ?>'>
          <input type="hidden" name="withdrawals_json" value='<?php echo json_encode($withdrawals); ?>'>
          <?php if (!empty($refined)): ?>
          <label style="display:inline-flex; align-items:center; gap:0.5rem; margin-right:1rem;">
            <input type="checkbox" name="save_refined" value="1" checked> Save refined outputs too
          </label>
          <?php endif; ?>
          <button class="btn btn-success" type="submit">Confirm & Save</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>


