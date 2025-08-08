<?php
/**
 * Logout handler
 * Destroys session and redirects to login
 */

require_once 'includes/auth.php';

// Log out the user
logoutUser();

// Redirect to login page with a success message
header('Location: /login.php?show_page=1&logout=success');
exit();