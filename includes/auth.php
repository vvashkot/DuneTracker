<?php
/**
 * Authentication and session management
 * Include this file in all pages that require authentication
 */

require_once __DIR__ . '/config-loader.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user']) && 
           isset($_SESSION['user']['discord_id']) && 
           isset($_SESSION['login_time']) &&
           (time() - $_SESSION['login_time'] < SESSION_LIFETIME);
}

/**
 * Check if user is approved
 */
function isUserApproved($user_id) {
    require_once dirname(__DIR__) . '/includes/db.php';
    $db = getDB();
    $stmt = $db->prepare("SELECT approval_status FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $status = $stmt->fetchColumn();
    return $status === 'approved';
}

/**
 * Require user to be logged in, redirect to login if not
 */
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: /login.php');
        exit();
    }
    
    // Check if user is approved
    $user = getCurrentUser();
    if (isset($user['db_id']) && !isUserApproved($user['db_id'])) {
        header('Location: /pending-approval.php');
        exit();
    }
    
    // Refresh display name from DB to respect admin-set in-game names
    try {
        require_once dirname(__DIR__) . '/includes/db.php';
        $db = getDB();
        $stmt = $db->prepare("SELECT COALESCE(in_game_name, username) AS display_name FROM users WHERE id = ?");
        $stmt->execute([$user['db_id']]);
        $display_name = $stmt->fetchColumn();
        if ($display_name) {
            $_SESSION['user']['username'] = $display_name;
        }
    } catch (Exception $e) {
        // Non-fatal if refresh fails
    }
    
    // Refresh session timeout
    $_SESSION['login_time'] = time();
}

/**
 * Get current logged in user
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    return $_SESSION['user'];
}

/**
 * Check if current user is admin (legacy function, use isUserAdmin instead)
 */
function isAdmin() {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    require_once dirname(__DIR__) . '/includes/db.php';
    return isUserAdmin($user['db_id']);
}

/**
 * Require user to be admin, redirect to home if not
 */
function requireAdmin() {
    requireLogin();
    
    $user = getCurrentUser();
    require_once dirname(__DIR__) . '/includes/db.php';
    
    if (!isUserAdmin($user['db_id'])) {
        header('HTTP/1.1 403 Forbidden');
        die('Access denied. Admin privileges required.');
    }
}

/**
 * Login user with Discord data
 */
function loginUser($discord_user) {
    $_SESSION['user'] = [
        'discord_id' => $discord_user['id'],
        'username' => $discord_user['username'],
        'avatar' => $discord_user['avatar'],
        'discriminator' => $discord_user['discriminator'] ?? '0'
    ];
    $_SESSION['login_time'] = time();
    
    // Generate CSRF token
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    
    // Store/update user in database
    require_once dirname(__DIR__) . '/includes/db.php';
    $user_id = createOrUpdateUser(
        $discord_user['id'],
        $discord_user['username'],
        $discord_user['avatar']
    );
    $_SESSION['user']['db_id'] = $user_id;
    
    // Set display name from DB preference (in_game_name overrides Discord username)
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COALESCE(in_game_name, username) AS display_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $display_name = $stmt->fetchColumn();
        if ($display_name) {
            $_SESSION['user']['username'] = $display_name;
        }
    } catch (Exception $e) {
        // Ignore if not available yet
    }
    
    // Log the login activity
    logActivity($user_id, 'User login', null, $_SERVER['REMOTE_ADDR'] ?? null);
}

/**
 * Logout current user
 */
function logoutUser() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Get CSRF token
 */
function getCSRFToken() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && 
           hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * Get Discord avatar URL
 */
function getAvatarUrl($user) {
    // Handle manual users or users without Discord ID
    if (!isset($user['discord_id']) || !$user['discord_id']) {
        // Use a consistent default avatar for manual users
        return "https://cdn.discordapp.com/embed/avatars/0.png";
    }
    
    if (!$user['avatar']) {
        // Default avatar based on discriminator or user ID
        $default_avatar = intval($user['discriminator'] ?? $user['discord_id']) % 5;
        return "https://cdn.discordapp.com/embed/avatars/{$default_avatar}.png";
    }
    
    $avatar_hash = $user['avatar'];
    $user_id = $user['discord_id'];
    
    // Check if avatar is animated
    $extension = substr($avatar_hash, 0, 2) === 'a_' ? 'gif' : 'png';
    
    return "https://cdn.discordapp.com/avatars/{$user_id}/{$avatar_hash}.{$extension}";
}

/**
 * Helper function for CSRF token in forms
 */
function csrfField() {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . htmlspecialchars(getCSRFToken()) . '">';
}

/**
 * Verify request method and CSRF token for POST requests
 */
function verifyPOST() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die('Method not allowed');
    }
    
    if (!verifyCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
}