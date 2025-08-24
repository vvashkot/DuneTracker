<?php
/**
 * AI Submission API Endpoint
 * Handles AJAX requests for AI-powered submissions from the dashboard
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai-submission.php';

// Require login
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$user = getCurrentUser();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$action = $input['action'] ?? '';

header('Content-Type: application/json');

switch ($action) {
    case 'analyze':
        $freeform = trim($input['freeform'] ?? '');
        if (empty($freeform)) {
            echo json_encode(['success' => false, 'message' => 'Please enter some text to analyze']);
            break;
        }
        
        $result = processAISubmission($freeform, $user);
        echo json_encode($result);
        break;
        
    case 'save':
        $items = $input['items'] ?? [];
        $participants = $input['participants'] ?? [];
        $shares = $input['shares'] ?? [];
        $landsraad = $input['landsraad'] ?? [];
        $withdrawals = $input['withdrawals'] ?? [];
        
        if (empty($items) && empty($landsraad) && empty($withdrawals)) {
            echo json_encode(['success' => false, 'message' => 'Nothing to save']);
            break;
        }
        
        $result = saveAISubmission($items, $participants, $shares, $landsraad, $withdrawals, $user);
        echo json_encode($result);
        break;
        
    case 'add_resources':
        // Admin-only: add missing resources
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'message' => 'Admin access required']);
            break;
        }
        
        $resources = $input['resources'] ?? [];
        $db = getDB();
        $added = [];
        
        foreach ($resources as $resource) {
            $name = trim($resource['name'] ?? '');
            $category = $resource['category'] ?? 'Raw Materials';
            
            if (empty($name)) continue;
            
            try {
                $stmt = $db->prepare("INSERT INTO resources (name, category) VALUES (?, ?)");
                $stmt->execute([$name, $category]);
                $added[] = $name;
            } catch (Throwable $e) {
                // Ignore duplicates
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => count($added) > 0 ? 'Resources added successfully' : 'No new resources added',
            'added' => $added
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}