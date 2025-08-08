# Deployment Checklist for houserubi-ka.com

## Pre-Deployment Tasks

### 1. Discord OAuth Setup
- [ ] Go to https://discord.com/developers/applications/1401618920301133906/oauth2
- [ ] Add production redirect URI: `https://houserubi-ka.com/callback.php`
- [ ] Keep local redirect URI: `http://localhost:8000/callback.php`

### 2. Update Local Config Files
Update `public_html/config.local.php` for local development:
```php
$ADMIN_USERS = [
    '305074630744342528', // Charlie Anderson
];
```

Update `public_html/config.production.php` admin section:
```php
$ADMIN_USERS = [
    '305074630744342528', // Charlie Anderson
];
```

## Files to Deploy

Upload entire `/public_html/` directory to Hostinger's `public_html/`:

### Core Files
- `index.php` - Main dashboard
- `login.php` - Discord OAuth login
- `callback.php` - OAuth callback handler
- `logout.php` - Session termination
- `config.php` - Main config loader
- `config.production.php` - Production template

### Feature Pages
- `contributions.php` - View all contributions
- `my-contributions.php` - User's contributions
- `submit.php` - Submit resources form
- `farming-runs.php` - Active farming runs
- `farming-runs-enhanced.php` - Enhanced farming interface
- `farming-run.php` - Individual run details
- `reports.php` - Resource reports
- `settings.php` - User settings
- `withdraw.php` - Withdrawal requests
- `pending-approval.php` - Pending user approvals
- `export-report.php` - Export functionality

### Directories
- `/admin/` - All admin panel files (13 files)
- `/includes/` - PHP includes (auth.php, db.php, config-loader.php, webhooks.php)
- `/css/` - Stylesheets (style.css, style-v2.css)
- `/js/` - JavaScript files (if any)

## Production Configuration

Create `config.local.php` on the server:
```php
<?php
// Database Configuration - Get from Hostinger hPanel
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Discord OAuth Configuration
define('DISCORD_CLIENT_ID', '1401618920301133906');
define('DISCORD_CLIENT_SECRET', 'CHv44GjgACyh4hS_krkDjL6lCiStClc8');
define('DISCORD_REDIRECT_URI', 'https://houserubi-ka.com/callback.php');
define('DISCORD_SCOPE', 'identify');

// Application Settings
define('APP_NAME', 'Dune Awakening Tracker');
define('APP_URL', 'https://houserubi-ka.com');
define('GUILD_NAME', 'House Rubi-Ka');
define('SESSION_NAME', 'dune_tracker_session');

// Optional Settings
define('REQUIRED_GUILD_ID', null);
define('SESSION_LIFETIME', 86400); // 24 hours
define('CSRF_TOKEN_NAME', 'csrf_token');

// Admin Users
$ADMIN_USERS = [
    '305074630744342528', // Charlie Anderson
];

// Timezone
date_default_timezone_set('UTC');

// Error Reporting (disabled for production)
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Session Configuration
ini_set('session.name', SESSION_NAME);
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
ini_set('session.cookie_lifetime', SESSION_LIFETIME);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // HTTPS only
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
```

## Database Setup

### 1. Create Database in Hostinger
- Go to hPanel → Databases → MySQL Databases
- Create new database
- Note the database name, username, and password

### 2. Import Schema and Migrations
Import in this exact order via phpMyAdmin:
1. `/database/schema.sql` - Base tables
2. `/database/migrations/001_initial_schema.sql`
3. `/database/migrations/002_add_farming_runs.sql`
4. `/database/migrations/003_simplify_resources.sql`
5. `/database/migrations/004_update_crafting_chains.sql`
6. `/database/migrations/005_update_melange_recipe.sql`
7. `/database/migrations/006_simplify_categories.sql`
8. `/database/migrations/007_remove_water.sql`
9. `/database/migrations/008_simplify_farming_runs.sql`
10. `/database/migrations/009_add_admin_role.sql`
11. `/database/migrations/010_add_manual_users.sql`
12. `/database/migrations/011_add_resource_goals.sql`
13. `/database/migrations/012_farming_run_enhancements.sql`
14. `/database/migrations/013_allow_null_created_by.sql`
15. `/database/migrations/014_add_user_approval.sql`
16. `/database/migrations/015_add_discord_webhooks.sql`
17. `/database/migrations/016_add_guild_tax_system.sql`
18. `/database/migrations/016_add_guild_tax_system_fix.sql`
19. `/database/migrations/016b_add_current_stock.sql`
20. `/database/migrations/017_add_withdrawals_system.sql`
21. `/database/migrations/017_add_withdrawals_system_fix.sql`

## Deployment Steps

### 1. Connect via FTP
- **Host**: Your Hostinger FTP host
- **Username**: Your FTP username
- **Password**: Your FTP password
- **Port**: 21 (FTP) or 22 (SFTP)

### 2. Upload Files
```
/home/u_______/
├── public_html/         # Upload all files here
│   ├── admin/
│   ├── css/
│   ├── includes/
│   ├── js/
│   ├── *.php files
│   └── config.local.php (create after upload)
└── logs/               # Create this directory (755)
```

### 3. Set Permissions
- All `.php` files: 644
- All directories: 755
- `/home/u_______/logs/`: 755

### 4. Test Deployment
1. Visit https://houserubi-ka.com
2. Click "Login with Discord"
3. Authorize the application
4. You should be redirected back and logged in
5. As admin (Discord ID: 305074630744342528), you'll have access to `/admin/`

## Post-Deployment

### 1. Verify Admin Access
- Login with your Discord account
- Check that you can access `/admin/`
- If not, manually update database:
```sql
UPDATE users SET role = 'admin', approval_status = 'approved' 
WHERE discord_id = '305074630744342528';
```

### 2. Configure Guild Settings
- Go to `/admin/guild-settings.php`
- Set guild tax rates if desired
- Configure Discord webhooks for notifications

### 3. Initial Data Setup
- Add resources via `/admin/resources.php`
- Set resource goals via `/admin/goals.php`
- Configure webhook notifications

## Troubleshooting

### Common Issues

**500 Internal Server Error**
- Check PHP version (needs 8.1+)
- Verify `.htaccess` if present
- Check error logs in `/home/u_______/logs/`

**Database Connection Failed**
- Verify credentials in `config.local.php`
- Ensure database user has all privileges
- Host should be `localhost` on Hostinger

**OAuth Redirect Error**
- Verify Discord redirect URI matches exactly
- Ensure HTTPS is working
- Check that session cookies are enabled

**Missing Admin Access**
- Login first, then check database for your user record
- Manually set role to 'admin' if needed
- Ensure your Discord ID is in `$ADMIN_USERS`

## Security Checklist
- [ ] `config.local.php` created with production credentials
- [ ] Error display disabled in production
- [ ] HTTPS enabled for domain
- [ ] Database has strong password
- [ ] Admin Discord IDs configured
- [ ] Logs directory created outside public_html

## Maintenance

### Backups
- Use Hostinger's backup service
- Export database regularly via phpMyAdmin
- Keep local copy of code

### Monitoring
- Check `/home/u_______/logs/error.log` for issues
- Monitor activity via `/admin/logs.php`
- Track resource usage in Hostinger hPanel