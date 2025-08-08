<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

try {
    requireLogin();
    if (!isAdmin()) {
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $run_id = isset($_GET['run_id']) ? intval($_GET['run_id']) : 0;
    $algo = $_GET['algo'] ?? 'weighted_across_runs';
    $discounted = isset($_GET['discounted']) && intval($_GET['discounted']) === 1;
    if ($run_id <= 0) {
        echo json_encode(['error' => 'Invalid run']);
        exit;
    }
    $result = computeSpiceToMelangeDistribution([$run_id], $algo, $discounted);
    // Normalize per_user to array values (not keyed) for easy JSON
    $per_user = [];
    foreach ($result['per_user'] as $uid => $row) {
        $per_user[] = [
            'user_id' => $uid,
            'username' => $row['username'],
            'input' => $row['input'],
            'share' => $row['share'],
            'output' => $row['output']
        ];
    }
    echo json_encode([
        'total_input' => $result['total_input'],
        'total_output' => $result['total_output'],
        'per_user' => $per_user,
        'note' => $discounted ? 'Using discounted refinery rate (7,500 → 200)' : 'Using standard refinery rate (10,000 → 200)'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}


