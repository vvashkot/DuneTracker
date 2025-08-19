<?php
/**
 * Login page - Redirects to Discord OAuth
 */

require_once 'includes/config-loader.php';
require_once 'includes/auth.php';

// Enforce canonical host to match DISCORD_REDIRECT_URI
$currentHost = $_SERVER['HTTP_HOST'] ?? '';
$redirectHost = parse_url(DISCORD_REDIRECT_URI, PHP_URL_HOST);
if ($redirectHost && strcasecmp($currentHost, $redirectHost) !== 0) {
    $scheme = 'https';
    $target = $scheme . '://' . $redirectHost . '/login.php' . (isset($_GET['show_page']) ? '?show_page=1' : '');
    header('Location: ' . $target);
    exit();
}

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: /index.php');
    exit();
}

// Generate state parameter for OAuth security (keep a short history to avoid multi-tab races)
$state = bin2hex(random_bytes(16));
if (!isset($_SESSION['oauth_states']) || !is_array($_SESSION['oauth_states'])) {
    $_SESSION['oauth_states'] = [];
}
$_SESSION['oauth_states'][] = ['v' => $state, 't' => time()];
// Keep only the last 5 states within 10 minutes
$_SESSION['oauth_states'] = array_values(array_filter(
    array_slice($_SESSION['oauth_states'], -5),
    function ($s) { return isset($s['t']) && (time() - (int)$s['t']) < 600; }
));
// Also keep a single latest copy for backward-compat reads
$_SESSION['oauth_state'] = $state;

// Also set a short-lived secure cookie as a fallback for environments where the
// session cookie is lost during the OAuth redirect. We'll verify either.
$cookieHost = parse_url(DISCORD_REDIRECT_URI, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? '');
$cookieDomain = $cookieHost && strpos($cookieHost, '.') !== false ? '.' . $cookieHost : '';
setcookie('oauth_state', $state, [
    'expires' => time() + 600,
    'path' => '/',
    'domain' => $cookieDomain,
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None',
]);
// Log issuance of state for debugging stubborn cases
error_log('[OAuthState] ISSUE ' . substr($state,0,8) . '... ' . 'ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? 'n/a') . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'n/a'));

// Build Discord OAuth URL
$params = [
    'client_id' => DISCORD_CLIENT_ID,
    'redirect_uri' => DISCORD_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => DISCORD_SCOPE,
    'state' => $state
];

$discord_auth_url = 'https://discord.com/api/oauth2/authorize?' . http_build_query($params);

// If this is a direct request to login.php, show a simple login page
// Otherwise, redirect directly to Discord
if (isset($_GET['show_page'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - <?php echo htmlspecialchars(APP_NAME); ?></title>
        <link rel="stylesheet" href="/css/style-v2.css">
        <style>
            body {
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                background-color: #2c2f33;
                color: #ffffff;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            .login-container {
                text-align: center;
                padding: 2rem;
                background-color: #23272a;
                border-radius: 8px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
                max-width: 400px;
                width: 90%;
            }
            h1 {
                color: #f5a623;
                margin-bottom: 1rem;
            }
            p {
                margin-bottom: 2rem;
                color: #99aab5;
            }
            .discord-button {
                display: inline-flex;
                align-items: center;
                padding: 12px 24px;
                background-color: #5865f2;
                color: white;
                text-decoration: none;
                border-radius: 4px;
                font-weight: 600;
                transition: background-color 0.2s;
            }
            .discord-button:hover {
                background-color: #4752c4;
            }
            .discord-button svg {
                margin-right: 8px;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h1><?php echo htmlspecialchars(APP_NAME); ?></h1>
            <p>Track your guild's Deep Desert resources</p>
            <a href="<?php echo htmlspecialchars($discord_auth_url); ?>" class="discord-button">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189z"/>
                </svg>
                Login with Discord
            </a>
        </div>
    </body>
    </html>
    <?php
} else {
    // Direct redirect to Discord
    header('Location: ' . $discord_auth_url);
    exit();
}