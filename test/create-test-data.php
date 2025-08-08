<?php
/**
 * Test data generator for local testing
 * Run this from command line: php create-test-data.php
 */

require_once __DIR__ . '/../public_html/includes/config-loader.php';
require_once __DIR__ . '/../public_html/includes/db.php';

echo "Creating test data for Dune Tracker...\n\n";

try {
    $db = getDB();
    
    // Create test users
    echo "Creating test users...\n";
    $testUsers = [
        ['discord_id' => 'test_' . uniqid(), 'username' => 'Paul Atreides', 'avatar' => null],
        ['discord_id' => 'test_' . uniqid(), 'username' => 'Duncan Idaho', 'avatar' => null],
        ['discord_id' => 'test_' . uniqid(), 'username' => 'Gurney Halleck', 'avatar' => null],
        ['discord_id' => 'test_' . uniqid(), 'username' => 'Stilgar', 'avatar' => null],
        ['discord_id' => 'test_' . uniqid(), 'username' => 'Chani', 'avatar' => null],
    ];
    
    $userIds = [];
    foreach ($testUsers as $user) {
        $stmt = $db->prepare("INSERT INTO users (discord_id, username, avatar) VALUES (?, ?, ?)");
        $stmt->execute([$user['discord_id'], $user['username'], $user['avatar']]);
        $userIds[] = $db->lastInsertId();
        echo "  Created user: {$user['username']}\n";
    }
    
    // Create a test farming run
    echo "\nCreating test farming run...\n";
    $runId = createFarmingRun(
        'Test Spice Harvest - Sector 7',
        'spice',
        'Northern Deep Desert, Sector 7',
        $userIds[0], // Paul as leader
        'Testing the new farming run system. Watch for worm sign!'
    );
    echo "  Created run ID: $runId\n";
    
    // Add participants
    echo "\nAdding participants...\n";
    foreach ($userIds as $i => $userId) {
        $role = $i === 0 ? 'leader' : 'member';
        addRunParticipant($runId, $userId, $role);
        echo "  Added {$testUsers[$i]['username']} as $role\n";
    }
    
    // Add some collections
    echo "\nAdding test collections...\n";
    $collections = [
        [$runId, 16, 250, $userIds[0], 'Found large spice blow'],  // Paul - Raw Spice
        [$runId, 16, 180, $userIds[1], 'Secondary location'],       // Duncan - Raw Spice
        [$runId, 2, 50, $userIds[2], 'Emergency water cache'],      // Gurney - Water
        [$runId, 16, 320, $userIds[3], 'Major deposit found'],      // Stilgar - Raw Spice
        [$runId, 3, 15, $userIds[4], 'Salvaged from wreck'],        // Chani - Stillsuit Components
    ];
    
    foreach ($collections as $i => $collection) {
        addRunCollection(...$collection);
        echo "  Added collection from {$testUsers[$i]['username']}\n";
    }
    
    // Add refined output
    echo "\nAdding refined outputs...\n";
    addRefinedOutput($runId, 16, 1, 750, 638, $userIds[0]); // Raw Spice -> Melange
    echo "  Added refinement: 750 Raw Spice -> 638 Melange (85% efficiency)\n";
    
    echo "\n✅ Test data created successfully!\n";
    echo "\nYou can now:\n";
    echo "1. Go to http://localhost:8000/farming-runs.php\n";
    echo "2. Click on 'Test Spice Harvest - Sector 7'\n";
    echo "3. Test all the features with this populated run\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}