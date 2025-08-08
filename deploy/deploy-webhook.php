<?php
/**
 * GitHub Webhook handler for automatic deployment
 * Place this in public_html as deploy-webhook.php
 */

// Configuration
$WEBHOOK_SECRET = 'your_github_webhook_secret';
$DEPLOY_SCRIPT_URL = 'https://yourdomain.com/path-to-deploy.php';
$DEPLOY_TOKEN = 'same_token_as_in_deploy.php';

// Get payload
$payload = file_get_contents('php://input');
$headers = getallheaders();

// Verify GitHub signature
if (!isset($headers['X-Hub-Signature-256'])) {
    http_response_code(403);
    die('No signature');
}

$signature = 'sha256=' . hash_hmac('sha256', $payload, $WEBHOOK_SECRET);
if (!hash_equals($signature, $headers['X-Hub-Signature-256'])) {
    http_response_code(403);
    die('Invalid signature');
}

// Parse payload
$data = json_decode($payload, true);

// Only deploy on push to main branch
if ($data['ref'] !== 'refs/heads/main') {
    die('Not main branch');
}

// Trigger deployment
$ch = curl_init($DEPLOY_SCRIPT_URL . '?token=' . $DEPLOY_TOKEN);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$result = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Log result
error_log("Deployment triggered: HTTP $http_code - $result");

// Return response
header('Content-Type: application/json');
echo $result;