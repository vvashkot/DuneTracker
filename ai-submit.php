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
$shares = [];
$refined = [];
$refinery_note = '';
$landsraad = [];
$missing_resources = [];

function callOpenAIExtract(string $text): array {
    $apiKey = getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null);
    if (!$apiKey) throw new Exception('Missing OPENAI_API_KEY');
    $url = 'https://api.openai.com/v1/chat/completions';
    $system = 'Extract structured data for a guild resource tracker. '
            . 'Return ONLY JSON with keys: '
            . 'items (array of {resource_name, quantity, notes?}), '
            . 'participants (array of strings), target (personal|group|run), split (equal|weighted|none), shares (object mapping participant name to fraction between 0 and 1; only if split is weighted), run_hint?, '
            . 'landsraad (array of entries, each {house?, item_name?, quantity?, points_per_unit?, bonus_pct?, total_points?, notes?}). '
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
                // Resolve resources now so review shows canonical names
                $items = array_map('resolveResource', $items);
                // Collect missing resource names
                $seen = [];
                foreach ($items as $it) {
                    if (empty($it['resolved_id'])) {
                        $label = (string)($it['resolved_name'] ?? $it['resource_name'] ?? '');
                        $label = trim($label);
                        if ($label !== '' && !isset($seen[strtolower($label)])) {
                            $missing_resources[] = $label;
                            $seen[strtolower($label)] = true;
                        }
                    }
                }

                // Compute refined outputs from inputs (Spice→Melange; Stravidium+Titanium→Plastanium)
                $totals = ['spice'=>0, 'titanium'=>0, 'stravidium'=>0];
                foreach ($items as $it) {
                    $n = normalizeResourceName((string)($it['resource_name'] ?? ''));
                    $q = (int)($it['quantity'] ?? 0);
                    if (isset($totals[$n])) $totals[$n] += $q;
                }
                $discounted = stripos($freeform, 'discount') !== false || stripos($freeform, '7,500') !== false;
                if ($totals['spice'] > 0) {
                    $per = $discounted ? 7500 : 10000;
                    $units = intdiv($totals['spice'], $per);
                    $mel = $units * 200;
                    if ($mel > 0) {
                        $refined[] = resolveResource(['resource_name'=>'melange', 'quantity'=>$mel, 'notes'=> $discounted ? 'Discounted refinery' : 'Standard refinery']);
                        $refinery_note = $discounted ? '(discounted 7,500→200)' : '(10,000→200)';
                    }
                }
                if ($totals['titanium'] > 0 || $totals['stravidium'] > 0) {
                    $units = min(intdiv((int)$totals['stravidium'], 3), intdiv((int)$totals['titanium'], 4));
                    if ($units > 0) {
                        $refined[] = resolveResource(['resource_name'=>'plastanium', 'quantity'=>$units, 'notes'=>'3 Stravidium + 4 Titanium → 1 Plastanium']);
                    }
                }
                $names = [];
                if (!empty($parsed['participants']) && is_array($parsed['participants'])) {
                    $names = $parsed['participants'];
                }
                $resolvedParticipants = resolveParticipantNames($names);
                if (!empty($parsed['shares']) && is_array($parsed['shares'])) {
                    $shares = $parsed['shares'];
                } elseif (!empty($parsed['shares']) && is_object($parsed['shares'])) {
                    $shares = (array)$parsed['shares'];
                }
                // Landsraad parsing
                $landsraad = [];
                if (!empty($parsed['landsraad']) && is_array($parsed['landsraad'])) {
                    foreach ($parsed['landsraad'] as $lr) {
                        $house = trim((string)($lr['house'] ?? ''));
                        $itemName = trim((string)($lr['item_name'] ?? ''));
                        $qty = isset($lr['quantity']) ? (int)$lr['quantity'] : 0;
                        $ppu = isset($lr['points_per_unit']) ? (int)$lr['points_per_unit'] : 0;
                        $bonus = isset($lr['bonus_pct']) ? (float)$lr['bonus_pct'] : 0.0;
                        $total = isset($lr['total_points']) ? (int)$lr['total_points'] : 0;
                        $note = trim((string)($lr['notes'] ?? ''));

                        // Resolve landsraad item if provided
                        $itemId = null; $createItem = false;
                        if ($itemName !== '') {
                            $stmt = $db->prepare("SELECT id, points_per_unit FROM landsraad_items WHERE active=1 AND LOWER(name)=LOWER(?) LIMIT 1");
                            $stmt->execute([$itemName]);
                            $row = $stmt->fetch();
                            if ($row) {
                                $itemId = (int)$row['id'];
                                if ($ppu <= 0 && isset($row['points_per_unit'])) { $ppu = (int)$row['points_per_unit']; }
                            } else {
                                // Will create on save if ppu>0
                                $createItem = true;
                            }
                        }

                        if ($total <= 0) {
                            if ($qty > 0 && $ppu > 0) {
                                $total = (int)floor($qty * $ppu * (1 + ($bonus/100.0)));
                            }
                        }
                        $landsraad[] = [
                            'house' => $house,
                            'item_name' => $itemName,
                            'item_id' => $itemId,
                            'create_item' => $createItem ? 1 : 0,
                            'quantity' => $qty,
                            'points_per_unit' => $ppu,
                            'bonus_pct' => $bonus,
                            'total_points' => $total,
                            'notes' => $note,
                        ];
                    }
                } else {
                    // Heuristic: direct landsraad points line e.g., "I contributed 10,500 points to the landsraad this week for House Alexis"
                    if (preg_match('/(\d[\d,]*)\s*points?\s+to\s+the\s+landsraad/i', $freeform, $m)) {
                        $p = (int)str_replace(',', '', $m[1]);
                        $house = '';
                        if (preg_match('/for\s+House\s+([A-Za-z0-9\- ]+)/i', $freeform, $hm)) {
                            $house = trim($hm[1]);
                        }
                        $landsraad[] = [
                            'house' => $house,
                            'item_name' => '',
                            'item_id' => null,
                            'create_item' => 0,
                            'quantity' => 0,
                            'points_per_unit' => 0,
                            'bonus_pct' => 0,
                            'total_points' => $p,
                            'notes' => 'Direct points',
                        ];
                    }
                }
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
        $rawShares = json_decode($_POST['shares_json'] ?? 'null', true);
        $landsraadRaw = json_decode($_POST['landsraad_json'] ?? '[]', true) ?: [];
        if (empty($rawItems) && empty($landsraadRaw)) {
            $message = 'Nothing to save.';
            $message_type = 'error';
        } else {
            // Validate all resources exist; if not, prompt to add
            $missingSet = [];
            foreach ($rawItems as $it) {
                $rname = trim((string)($it['resource_name'] ?? ''));
                if ($rname === '') continue;
                $ridCheck = isset($it['resolved_id']) && $it['resolved_id'] ? (int)$it['resolved_id'] : findResourceIdByName($rname);
                if (!$ridCheck) { $missingSet[$rname] = true; }
            }
            if (!empty($missingSet)) {
                $message = 'Resource not added, would you like to add it now?';
                $message_type = 'error';
                $missing_resources = array_keys($missingSet);
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
                    $rid = isset($it['resolved_id']) && $it['resolved_id'] ? (int)$it['resolved_id'] : findResourceIdByName($rname);
                    if (!$rid) continue;
                    if ($numUsers > 0) {
                        // Build weights using fuzzy mapping for share keys
                        $weights = [];
                        $sum = 0.0;
                        if (is_array($rawShares) && !empty($rawShares)) {
                            $weights = mapSharesToParticipants($rawShares, $validUsers);
                            $sum = array_sum($weights);
                        }
                        if ($sum <= 0) {
                            // equal split
                            foreach ($validUsers as $p) { $weights[$p['id']] = 1.0; }
                            $sum = (float)$numUsers;
                        }
                        // Normalize and allocate integer quantities using largest remainder
                        $alloc = [];
                        $fractions = [];
                        $assigned = 0;
                        foreach ($validUsers as $p) {
                            $w = $weights[$p['id']] / $sum;
                            $portion = $qty * $w;
                            $q = (int)floor($portion);
                            $alloc[$p['id']] = $q;
                            $fractions[$p['id']] = $portion - $q;
                            $assigned += $q;
                        }
                        $rem = $qty - $assigned;
                        if ($rem > 0) {
                            arsort($fractions);
                            foreach (array_keys($fractions) as $uid) {
                                if ($rem <= 0) break;
                                $alloc[$uid] += 1;
                                $rem--;
                            }
                        }
                        foreach ($validUsers as $p) {
                            $q = $alloc[$p['id']] ?? 0;
                            if ($q > 0) {
                                addContribution($p['id'], (int)$rid, $q, $notes ? ($notes . ' (AI split)') : 'AI split');
                            }
                        }
                    } else {
                        addContribution($user['db_id'], (int)$rid, $qty, $notes ?: null);
                    }

                    // Landsraad goals auto-log: if an active goal matches this item and user is a member, log qty
                    try {
                        $stmtGoal = $db->prepare("SELECT id FROM landsraad_item_goals WHERE active=1 AND LOWER(item_name)=LOWER(?) LIMIT 1");
                        $stmtGoal->execute([$rname]);
                        $goalId = (int)($stmtGoal->fetchColumn() ?: 0);
                        if ($goalId > 0) {
                            $stmtMem = $db->prepare("SELECT 1 FROM landsraad_goal_members WHERE goal_id=? AND user_id=? LIMIT 1");
                            $stmtMem->execute([$goalId, $user['db_id']]);
                            if ($stmtMem->fetchColumn()) {
                                $stmtLog = $db->prepare("INSERT INTO landsraad_goal_stock_logs (goal_id, user_id, qty, note) VALUES (?,?,?,?)");
                                $stmtLog->execute([$goalId, $user['db_id'], $qty, ($notes ?: 'AI submit')]);
                            }
                        }
                    } catch (Throwable $ignored) { /* best effort; ignore */ }
                }
                // Save Landsraad entries
                foreach ($landsraadRaw as $lr) {
                    $house = trim((string)($lr['house'] ?? ''));
                    $itemName = trim((string)($lr['item_name'] ?? ''));
                    $itemId = isset($lr['item_id']) && $lr['item_id'] ? (int)$lr['item_id'] : null;
                    $createItem = !empty($lr['create_item']);
                    $qty = (int)($lr['quantity'] ?? 0);
                    $ppu = (int)($lr['points_per_unit'] ?? 0);
                    $bonus = (float)($lr['bonus_pct'] ?? 0);
                    $total = (int)($lr['total_points'] ?? 0);
                    $notes = trim((string)($lr['notes'] ?? ''));
                    if ($total <= 0 && $qty > 0 && $ppu > 0) {
                        $total = (int)floor($qty * $ppu * (1 + ($bonus/100.0)));
                    }
                    if ($itemId === null && $createItem && $itemName !== '' && $ppu > 0) {
                        $stmt = $db->prepare("INSERT INTO landsraad_items (name, points_per_unit, created_by) VALUES (?, ?, ?)");
                        $stmt->execute([$itemName, $ppu, $user['db_id']]);
                        $itemId = (int)$db->lastInsertId();
                    }
                    if ($total > 0) {
                        $category = $house ? ('House: ' . $house) : null;
                        $noteFull = trim(($notes ? ($notes . ' | ') : '')
                            . ($itemName !== '' ? ('Item: ' . $itemName . ', ') : '')
                            . ($qty > 0 ? ('Qty: ' . $qty . ', ') : '')
                            . ($ppu > 0 ? ('PPU: ' . $ppu . ', ') : '')
                            . ($bonus > 0 ? ('Bonus%: ' . $bonus) : ''));
                        $stmt = $db->prepare("INSERT INTO landsraad_points (user_id, points, category, occurred_at, notes) VALUES (?, ?, ?, NOW(), ?)");
                        $stmt->execute([$user['db_id'], $total, $category, $noteFull ?: null]);
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
          <textarea name="freeform" rows="4" class="form-control" placeholder="Type your note...">&lt;?php echo htmlspecialchars($_POST['freeform'] ?? ''); ?&gt;</textarea>
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


