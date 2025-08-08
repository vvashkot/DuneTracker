# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP + MySQL web application for tracking guild resources in the game Dune: Awakening. Members authenticate via Discord OAuth to log Deep Desert resources they've collected, view total guild inventory, and track individual contributions.

## Tech Stack & Environment

- **Backend**: PHP 8.1
- **Database**: MySQL
- **Frontend**: HTML/CSS (Bootstrap optional)
- **Authentication**: Discord OAuth2
- **Hosting**: GoDaddy shared hosting
- **No build process** - Traditional PHP application

## Development Commands

Since this is a traditional PHP application without a build process:

```bash
# Start local PHP development server (if testing locally)
php -S localhost:8000 -t public_html/

# Access MySQL locally (replace with your credentials)
mysql -u your_db_user -p your_db_name
```

## Architecture & Structure

### Directory Layout
```
/public_html/              # Web root
├── includes/             # PHP includes (auth, database)
├── css/                  # Stylesheets
├── js/                   # JavaScript files
├── config.php           # Configuration constants
├── login.php            # Discord OAuth redirect
├── callback.php         # OAuth callback handler
├── index.php            # Dashboard (main entry)
├── submit.php           # Resource contribution form
├── my-contributions.php # User's contribution history
├── admin.php            # Admin panel (optional)
└── logout.php           # Session termination
```

### Core Components

1. **Discord OAuth Flow**
   - `login.php` redirects to Discord
   - `callback.php` handles token exchange and user data
   - `includes/auth.php` provides session validation for protected pages

2. **Database Schema**
   - `users`: Discord user information
   - `resources`: Resource types and categories
   - `contributions`: User resource submissions
   - `distributions`: Resource allocations (optional)

3. **Session Management**
   - PHP sessions store authenticated user data
   - All protected pages require `includes/auth.php`

### Key Implementation Notes

- **No framework** - Use native PHP functions
- **Database connections** via `includes/db.php` using mysqli or PDO
- **Config values** in `config.php` (Discord OAuth credentials, DB settings)
- **Form validation** should be server-side in PHP
- **SQL injection prevention** - Use prepared statements
- **XSS prevention** - Use `htmlspecialchars()` for output

## Testing Approach

Manual testing via browser:
1. Discord OAuth login/logout flow
2. Form submission and validation
3. Dashboard data aggregation
4. Session persistence across pages
5. Admin panel access control (if implemented)

## Deployment

1. Upload files to GoDaddy `/public_html/` via FTP or cPanel
2. Create MySQL database and tables via cPanel
3. Update `config.php` with production credentials
4. Set Discord OAuth redirect URI to production URL
5. Ensure HTTPS is enabled