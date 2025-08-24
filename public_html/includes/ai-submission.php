<?php
/**
 * AI Submission Functions
 * Shared functions for processing AI-powered resource submissions
 */

require_once __DIR__ . '/db.php';

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

function processAISubmission(string $freeform, $user) {
    $db = getDB();
    $result = [
        'success' => false,
        'message' => '',
        'parsed' => null,
        'items' => [],
        'refined' => [],
        'landsraad' => [],
        'withdrawals' => [],
        'participants' => [],
        'shares' => [],
        'missing_resources' => [],
        'refinery_note' => ''
    ];
    
    try {
        $parsed = callOpenAIExtract($freeform);
        $result['parsed'] = $parsed;
        
        // Process items
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
        
        // Resolve resources
        $items = array_map('resolveResource', $items);
        $result['items'] = $items;
        
        // Parse withdrawals
        $withdrawals = isset($parsed['withdrawals']) && is_array($parsed['withdrawals']) ? $parsed['withdrawals'] : [];
        if (empty($withdrawals)) {
            // Heuristic: "withdrew 3 titanium", "removed 10 spice", etc.
            if (preg_match_all('/\\b(withdrew|withdraw|removed|spent|took)\\s+(\\d{1,3}(?:[\\d,]*)(?:\\.\\d+)?)\\s+([A-Za-z ]{2,})/i', $freeform, $wm, PREG_SET_ORDER)) {
                foreach ($wm as $m1) {
                    $qtyStr = str_replace(',', '', $m1[2]);
                    $withdrawals[] = [
                        'resource_name' => trim($m1[3]),
                        'quantity' => (float)$qtyStr,
                        'purpose' => 'AI submit',
                        'notes' => ''
                    ];
                }
            }
        }
        $withdrawals = array_map('resolveResource', $withdrawals);
        $result['withdrawals'] = $withdrawals;
        
        // Collect missing resources
        $seen = [];
        foreach ($items as $it) {
            if (empty($it['resolved_id'])) {
                $label = (string)($it['resolved_name'] ?? $it['resource_name'] ?? '');
                $label = trim($label);
                if ($label !== '' && !isset($seen[strtolower($label)])) {
                    $result['missing_resources'][] = $label;
                    $seen[strtolower($label)] = true;
                }
            }
        }
        foreach ($withdrawals as $wit) {
            if (empty($wit['resolved_id'])) {
                $label = (string)($wit['resolved_name'] ?? $wit['resource_name'] ?? '');
                $label = trim($label);
                if ($label !== '' && !isset($seen[strtolower($label)])) {
                    $result['missing_resources'][] = $label;
                    $seen[strtolower($label)] = true;
                }
            }
        }
        
        // Compute refined outputs
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
                $result['refined'][] = resolveResource(['resource_name'=>'melange', 'quantity'=>$mel, 'notes'=> $discounted ? 'Discounted refinery' : 'Standard refinery']);
                $result['refinery_note'] = $discounted ? '(discounted 7,500→200)' : '(10,000→200)';
            }
        }
        if ($totals['titanium'] > 0 || $totals['stravidium'] > 0) {
            $units = min(intdiv((int)$totals['stravidium'], 3), intdiv((int)$totals['titanium'], 4));
            if ($units > 0) {
                $result['refined'][] = resolveResource(['resource_name'=>'plastanium', 'quantity'=>$units, 'notes'=>'3 Stravidium + 4 Titanium → 1 Plastanium']);
            }
        }
        
        // Process participants
        $names = [];
        if (!empty($parsed['participants']) && is_array($parsed['participants'])) {
            $names = $parsed['participants'];
        }
        $result['participants'] = resolveParticipantNames($names);
        
        // Process shares
        if (!empty($parsed['shares']) && is_array($parsed['shares'])) {
            $result['shares'] = $parsed['shares'];
        } elseif (!empty($parsed['shares']) && is_object($parsed['shares'])) {
            $result['shares'] = (array)$parsed['shares'];
        }
        
        // Landsraad parsing
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
                $result['landsraad'][] = [
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
            // Heuristic: direct landsraad points line
            if (preg_match('/(\d[\d,]*)\s*points?\s+to\s+the\s+landsraad/i', $freeform, $m)) {
                $p = (int)str_replace(',', '', $m[1]);
                $house = '';
                if (preg_match('/for\s+House\s+([A-Za-z0-9\- ]+)/i', $freeform, $hm)) {
                    $house = trim($hm[1]);
                }
                $result['landsraad'][] = [
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
        
        $result['success'] = true;
        $result['message'] = 'Successfully parsed submission';
        
    } catch (Throwable $e) {
        error_log('AI analyze error: ' . $e->getMessage());
        $result['message'] = 'AI failed to analyze input.';
    }
    
    return $result;
}

function saveAISubmission($items, $participants, $shares, $landsraad, $withdrawals, $user) {
    $db = getDB();
    
    // Validate all resources exist
    $missingSet = [];
    foreach ($items as $it) {
        $rname = trim((string)($it['resource_name'] ?? ''));
        if ($rname === '') continue;
        $ridCheck = isset($it['resolved_id']) && $it['resolved_id'] ? (int)$it['resolved_id'] : findResourceIdByName($rname);
        if (!$ridCheck) { $missingSet[$rname] = true; }
    }
    foreach ($withdrawals as $wit) {
        $rname = trim((string)($wit['resource_name'] ?? ''));
        if ($rname === '') continue;
        $ridCheck = isset($wit['resolved_id']) && $wit['resolved_id'] ? (int)$wit['resolved_id'] : findResourceIdByName($rname);
        if (!$ridCheck) { $missingSet[$rname] = true; }
    }
    
    if (!empty($missingSet)) {
        return [
            'success' => false,
            'message' => 'Some resources are not in the database',
            'missing_resources' => array_keys($missingSet)
        ];
    }
    
    // Resolve participants again server-side
    $resolvedParticipants = resolveParticipantNames($participants);
    $validUsers = array_values(array_filter($resolvedParticipants, fn($p) => !empty($p['id'])));
    $numUsers = count($validUsers);
    
    try {
        foreach ($items as $it) {
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
                if (is_array($shares) && !empty($shares)) {
                    $weights = mapSharesToParticipants($shares, $validUsers);
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
            
            // Landsraad goals auto-log
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
        foreach ($landsraad as $lr) {
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
        
        // Save withdrawals
        foreach ($withdrawals as $wit) {
            $rname = trim((string)($wit['resource_name'] ?? ''));
            $qty = (float)($wit['quantity'] ?? 0);
            $purpose = trim((string)($wit['purpose'] ?? 'AI submit'));
            $notes = trim((string)($wit['notes'] ?? ''));
            if ($rname === '' || $qty <= 0) continue;
            $rid = isset($wit['resolved_id']) && $wit['resolved_id'] ? (int)$wit['resolved_id'] : findResourceIdByName($rname);
            if (!$rid) continue;
            $stmt = $db->prepare("INSERT INTO withdrawals (user_id, resource_id, quantity, purpose, notes, approval_status, approved_at) VALUES (?,?,?,?,?,'approved', NOW())");
            $stmt->execute([$user['db_id'], (int)$rid, $qty, ($purpose ?: 'AI submit'), ($notes ?: null)]);
        }
        
        return ['success' => true, 'message' => 'Submission saved successfully'];
        
    } catch (Throwable $e) {
        error_log('AI save_multi failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save submission'];
    }
}