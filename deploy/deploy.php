<?php
/**
 * Simple deployment script for GoDaddy
 * Place this OUTSIDE of public_html for security
 * Access via: https://yourdomain.com/deploy.php?token=your_secret_token
 */

// Configuration
$SECRET_TOKEN = 'change_this_to_random_string_' . bin2hex(random_bytes(16));
$GITHUB_REPO = 'https://github.com/yourusername/rubi-ka.git';
$DEPLOY_BRANCH = 'main';
$DEPLOY_PATH = '/home/yourusername/public_html/tracker';
$BACKUP_PATH = '/home/yourusername/backups';

// Verify token
if (!isset($_GET['token']) || $_GET['token'] !== $SECRET_TOKEN) {
    http_response_code(403);
    die('Forbidden');
}

// Create response array
$response = [
    'status' => 'starting',
    'timestamp' => date('Y-m-d H:i:s'),
    'steps' => []
];

try {
    // Step 1: Create backup
    $backup_name = 'backup_' . date('Y-m-d_H-i-s');
    $response['steps'][] = "Creating backup: $backup_name";
    
    if (!file_exists($BACKUP_PATH)) {
        mkdir($BACKUP_PATH, 0755, true);
    }
    
    exec("cp -r $DEPLOY_PATH $BACKUP_PATH/$backup_name 2>&1", $output, $return);
    if ($return !== 0) {
        throw new Exception("Backup failed: " . implode("\n", $output));
    }
    
    // Step 2: Pull latest changes from Git
    $response['steps'][] = "Pulling latest changes from Git";
    
    chdir($DEPLOY_PATH);
    
    // Stash any local changes
    exec("git stash 2>&1", $output, $return);
    $response['steps'][] = "Git stash: " . implode(" ", $output);
    
    // Pull latest
    exec("git pull origin $DEPLOY_BRANCH 2>&1", $output, $return);
    $response['steps'][] = "Git pull: " . implode(" ", $output);
    
    if ($return !== 0) {
        throw new Exception("Git pull failed");
    }
    
    // Step 3: Run any database migrations
    if (file_exists("$DEPLOY_PATH/database/migrations")) {
        $response['steps'][] = "Checking for database migrations";
        // Add migration logic here if needed
    }
    
    // Step 4: Clear any caches
    $response['steps'][] = "Clearing caches";
    if (file_exists("$DEPLOY_PATH/cache")) {
        exec("rm -rf $DEPLOY_PATH/cache/* 2>&1");
    }
    
    // Step 5: Set proper permissions
    $response['steps'][] = "Setting permissions";
    exec("chmod -R 755 $DEPLOY_PATH 2>&1");
    exec("chmod 644 $DEPLOY_PATH/config.php 2>&1");
    
    $response['status'] = 'success';
    $response['message'] = 'Deployment completed successfully';
    
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['error'] = $e->getMessage();
    
    // Attempt rollback
    if (isset($backup_name) && file_exists("$BACKUP_PATH/$backup_name")) {
        $response['steps'][] = "Rolling back to backup";
        exec("rm -rf $DEPLOY_PATH/* && cp -r $BACKUP_PATH/$backup_name/* $DEPLOY_PATH/ 2>&1");
    }
}

// Output response
header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);