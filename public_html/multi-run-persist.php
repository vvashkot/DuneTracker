<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /farming-runs-enhanced.php');
    exit;
}

verifyPOST();

$user = getCurrentUser();

$idsParam = $_POST['run_ids'] ?? '';
$algo = $_POST['algo'] ?? 'weighted_across_runs';
$discounted = isset($_POST['discounted']) && $_POST['discounted'] === '1';
$override = isset($_POST['override']) && $_POST['override'] === '1';

$run_ids = array_values(array_filter(array_map('intval', is_array($idsParam) ? $idsParam : explode(',', $idsParam)), fn($v) => $v > 0));
if (empty($run_ids)) {
    header('Location: /farming-runs-enhanced.php?tab=multi&msg=' . urlencode('No runs selected'));
    exit;
}

$db = getDB();

// Resolve resource ids
$melange_id = $db->query("SELECT id FROM resources WHERE LOWER(name) LIKE 'melange%' ORDER BY id LIMIT 1")->fetchColumn();
if (!$melange_id) {
    header('Location: /farming-runs-enhanced.php?tab=multi&msg=' . urlencode('Melange resource not found'));
    exit;
}
$spice_id = $db->query("SELECT id FROM resources WHERE LOWER(name) LIKE '%spice%' AND LOWER(name) NOT LIKE '%coffee%' ORDER BY id LIMIT 1")->fetchColumn();
if (!$spice_id) { $spice_id = $melange_id; }

$persisted = [];
$skipped = [];

foreach ($run_ids as $rid) {
    try {
        // Guard existing distributions
        $stmt = $db->prepare("SELECT COUNT(*) FROM run_distributions WHERE run_id = ? AND resource_id = ?");
        $stmt->execute([$rid, $melange_id]);
        $existing = (int)$stmt->fetchColumn();
        if ($existing > 0 && !$override) {
            $skipped[] = $rid;
            continue;
        }

        // Compute per-run distribution using selected algorithm
        $result = computeSpiceToMelangeDistribution([$rid], $algo, $discounted);
        $total_input = (int)($result['total_input'] ?? 0);
        $total_output = (int)($result['total_output'] ?? 0);
        if ($total_output <= 0) {
            $skipped[] = $rid;
            continue;
        }

        // Record refined output summary for bookkeeping
        addRefinedOutput($rid, (int)$spice_id, (int)$melange_id, $total_input, $total_output, $user['db_id']);

        // Persist per-user distributions
        $note = 'Multi-run auto (' . ($algo === 'equal_per_run' ? 'equal-per-run' : 'weighted') . ($discounted ? ', discounted' : '') . ')';
        foreach ($result['per_user'] as $uid => $row) {
            $qty = (int)($row['output'] ?? 0);
            if ($qty > 0) {
                distributeRunResources($rid, (int)$melange_id, (int)$uid, $qty, $user['db_id'], $note);
            }
        }

        $persisted[] = $rid;
    } catch (Throwable $e) {
        error_log('Multi-run persist failed for run ' . $rid . ': ' . $e->getMessage());
        $skipped[] = $rid;
    }
}

$msg = 'Persisted runs: ' . implode(',', $persisted);
if (!empty($skipped)) { $msg .= ' | Skipped: ' . implode(',', $skipped); }

header('Location: /farming-runs-enhanced.php?tab=multi&msg=' . urlencode($msg));
exit;


