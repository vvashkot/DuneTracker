# Local Testing Setup Guide

## Prerequisites

1. **PHP 8.1+** installed locally
2. **MySQL** or **MariaDB** installed locally
3. **Discord Application** for OAuth testing

## Step 1: Set Up Local Database

1. Start MySQL/MariaDB:
```bash
# macOS (with Homebrew)
brew services start mysql

# Or if using MAMP/XAMPP, start it from the control panel
```

2. Create database and import schema:
```bash
# Login to MySQL
mysql -u root -p

# In MySQL prompt:
CREATE DATABASE dune_tracker;
USE dune_tracker;
SOURCE /Users/charlieanderson/Code/Rubi-Ka/database/schema.sql;
EXIT;
```

## Step 2: Configure Discord OAuth for Local Testing

1. Go to https://discord.com/developers/applications
2. Create a new application or use existing
3. Go to OAuth2 → General
4. Add redirect URI: `http://localhost:8000/callback.php`
5. Save changes
6. Copy your Client ID and Client Secret

## Step 3: Create Local Configuration

Create a local config file that won't be committed:
```bash
cp public_html/config.php public_html/config.local.php
```

Edit `public_html/config.local.php`:
```php
// Database Configuration - Local
define('DB_HOST', 'localhost');
define('DB_NAME', 'dune_tracker');
define('DB_USER', 'root');
define('DB_PASS', 'your_mysql_password');

// Discord OAuth Configuration - Local
define('DISCORD_CLIENT_ID', 'your_discord_client_id');
define('DISCORD_CLIENT_SECRET', 'your_discord_client_secret');
define('DISCORD_REDIRECT_URI', 'http://localhost:8000/callback.php');

// Application Settings - Local
define('APP_URL', 'http://localhost:8000');

// Disable HTTPS for local testing
ini_set('session.cookie_secure', 0);
```

## Step 4: Update Files for Local Testing

We need to check for local config in our PHP files. Update the require statements:
- In all PHP files that require 'config.php', change to:
```php
require_once file_exists('config.local.php') ? 'config.local.php' : 'config.php';
```

## Step 5: Start Local PHP Server

From the project root:
```bash
cd public_html
php -S localhost:8000
```

## Step 6: Test the Application

1. Open http://localhost:8000 in your browser
2. You should be redirected to login page
3. Click "Login with Discord"
4. Authorize the application
5. You should be redirected back and logged in

## Troubleshooting

### Common Issues:

1. **"Failed to exchange authorization code"**
   - Check Discord Client ID and Secret
   - Ensure redirect URI matches exactly

2. **Database connection failed**
   - Check MySQL is running
   - Verify database credentials
   - Ensure database and tables exist

3. **Session errors**
   - Make sure PHP has write permissions to session directory
   - Check PHP session configuration

### Debug Mode

Add to your local config:
```php
// Enable debug mode
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

### View PHP Logs

```bash
# Check PHP error log
tail -f /usr/local/var/log/php-fpm.log

# Or check Apache/Nginx error logs if using those
```

## Alternative: Using MAMP/XAMPP

If you prefer a GUI solution:

1. **MAMP (macOS)** or **XAMPP (Windows/macOS/Linux)**
2. Place project in `htdocs` folder
3. Start Apache and MySQL from control panel
4. Access via `http://localhost/Rubi-Ka/public_html/`

## Security Note

The `config.local.php` file contains sensitive credentials. Make sure to:
1. Never commit this file to git
2. Add `config.local.php` to `.gitignore`
3. Keep your Discord app credentials secure