<?php
/**
 * Discord OAuth callback handler
 * Exchanges authorization code for access token and logs in user
 */

require_once 'includes/config-loader.php';
require_once 'includes/auth.php';

// Enforce canonical host to match DISCORD_REDIRECT_URI
$currentHost = $_SERVER['HTTP_HOST'] ?? '';
$redirectHost = parse_url(DISCORD_REDIRECT_URI, PHP_URL_HOST);
if ($redirectHost && strcasecmp($currentHost, $redirectHost) !== 0) {
    $scheme = 'https';
    $target = $scheme . '://' . $redirectHost . '/callback.php';
    header('Location: ' . $target);
    exit();
}

// Check for error from Discord
if (isset($_GET['error'])) {
    die('Discord OAuth error: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
}

// Verify state parameter
if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    die('Invalid state parameter. Please try logging in again.');
}

// Clear the state from session
unset($_SESSION['oauth_state']);

// Check for authorization code
if (!isset($_GET['code'])) {
    die('No authorization code received. Please try logging in again.');
}

$code = $_GET['code'];

// Exchange code for access token
$token_url = 'https://discord.com/api/oauth2/token';
$token_data = [
    'client_id' => DISCORD_CLIENT_ID,
    'client_secret' => DISCORD_CLIENT_SECRET,
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => DISCORD_REDIRECT_URI
];

$curl = curl_init($token_url);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($token_data));
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);

$token_response = curl_exec($curl);
$token_http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($token_http_code !== 200) {
    error_log('Discord token exchange failed: ' . $token_response);
    die('Failed to exchange authorization code. Please try logging in again.');
}

$token_info = json_decode($token_response, true);

if (!isset($token_info['access_token'])) {
    die('No access token received. Please try logging in again.');
}

// Use access token to get user information
$user_url = 'https://discord.com/api/users/@me';

$curl = curl_init($user_url);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token_info['access_token']
]);

$user_response = curl_exec($curl);
$user_http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($user_http_code !== 200) {
    error_log('Discord user fetch failed: ' . $user_response);
    die('Failed to get user information. Please try logging in again.');
}

$discord_user = json_decode($user_response, true);

if (!isset($discord_user['id'])) {
    die('Invalid user data received. Please try logging in again.');
}

// Optional: Check if user is in required guild
if (REQUIRED_GUILD_ID !== null) {
    $guild_url = 'https://discord.com/api/users/@me/guilds';
    
    $curl = curl_init($guild_url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token_info['access_token']
    ]);
    
    $guilds_response = curl_exec($curl);
    $guilds_http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($guilds_http_code === 200) {
        $guilds = json_decode($guilds_response, true);
        $in_guild = false;
        
        foreach ($guilds as $guild) {
            if ($guild['id'] === REQUIRED_GUILD_ID) {
                $in_guild = true;
                break;
            }
        }
        
        if (!$in_guild) {
            die('You must be a member of the guild to access this application.');
        }
    }
}

// Login the user
loginUser($discord_user);

// Redirect to originally requested page or dashboard
$redirect_to = $_SESSION['redirect_after_login'] ?? '/index.php';
unset($_SESSION['redirect_after_login']);

header('Location: ' . $redirect_to);
exit();