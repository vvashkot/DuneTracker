# GoDaddy Deployment & Update Guide

## Initial Setup on GoDaddy

### Step 1: Database Setup
1. Log into GoDaddy cPanel
2. Go to **MySQL Databases**
3. Create new database (e.g., `dune_tracker`)
4. Create new user with strong password
5. Add user to database with ALL privileges
6. Go to **phpMyAdmin**
7. Select your database
8. Import `database/schema.sql`
9. Create migrations table:
```sql
CREATE TABLE IF NOT EXISTS migrations (
    version INT PRIMARY KEY,
    description VARCHAR(255),
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Step 2: File Upload
1. In cPanel, go to **File Manager**
2. Navigate to `public_html`
3. Create a subdirectory if needed (e.g., `tracker`)
4. Upload contents of your `public_html` folder

### Step 3: Production Configuration
1. In File Manager, edit `config.php`:
```php
// Update with GoDaddy database info
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_godaddy_db_name');
define('DB_USER', 'your_godaddy_db_user');
define('DB_PASS', 'your_godaddy_db_password');

// Update Discord OAuth
define('DISCORD_REDIRECT_URI', 'https://yourdomain.com/tracker/callback.php');
define('APP_URL', 'https://yourdomain.com/tracker');

// Disable error display for production
error_reporting(0);
ini_set('display_errors', 0);
```

## Deployment Methods

### Method 1: FTP Deployment (Simplest)

1. **Setup FTP credentials** in GoDaddy cPanel
2. **Configure** `deploy/ftp-deploy.php` with your FTP details
3. **Deploy** from your local machine:
```bash
cd /Users/charlieanderson/Code/Rubi-Ka/deploy
php ftp-deploy.php
```

This script:
- Only uploads files that have changed
- Preserves your production config.php
- Creates directories as needed
- Shows progress in real-time

### Method 2: Git + Webhook (Advanced)

If you have SSH access or can run Git on GoDaddy:

1. **Initialize Git** on GoDaddy:
```bash
cd /home/username/public_html/tracker
git init
git remote add origin https://github.com/yourusername/rubi-ka.git
git pull origin main
```

2. **Setup webhook**:
- Copy `deploy/deploy.php` to a secure location
- Copy `deploy/deploy-webhook.php` to public_html
- Configure GitHub webhook to point to deploy-webhook.php
- Set webhook secret in both files

### Method 3: Manual Updates via cPanel

For small updates:
1. Use cPanel File Manager
2. Edit files directly or upload individual files
3. Good for config changes or quick fixes

## Update Workflow

### For Code Updates:
1. Make changes locally
2. Test thoroughly
3. Commit to Git (if using Git)
4. Deploy using chosen method
5. Test on production

### For Database Updates:
1. Create migration file in `database/migrations/`
2. Test migration locally
3. Deploy migration file
4. Run migration in phpMyAdmin:
```sql
SOURCE /path/to/migration.sql;
INSERT INTO migrations (version, description) VALUES (2, 'Description here');
```

### What Gets Preserved:
- `config.php` (never overwritten)
- Database data
- User uploads (if any)
- Session data

### Rollback Plan:
1. Keep backups before major updates
2. Use GoDaddy's backup feature
3. The deploy.php script creates automatic backups

## Best Practices

1. **Never commit** production config.php to Git
2. **Test locally** before deploying
3. **Deploy during low-traffic times**
4. **Monitor error logs** after deployment
5. **Keep database migrations** small and reversible

## Quick Commands Reference

```bash
# Local testing
cd public_html && php -S localhost:8000

# FTP deployment
cd deploy && php ftp-deploy.php

# Check deployment status
curl https://yourdomain.com/deploy.php?token=your_token

# View error logs (in cPanel)
tail -f /home/username/public_html/error_log
```