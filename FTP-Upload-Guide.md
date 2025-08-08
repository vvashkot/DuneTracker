# FTP Upload Guide for Hostinger

## FTP Connection Details
Get these from Hostinger hPanel → Files → FTP Accounts:
- **Host/Server**: ftp.yourdomain.com (or IP address)
- **Username**: u442226222 (or full FTP username)
- **Password**: Your FTP password
- **Port**: 21 (FTP) or 22 (SFTP)

## Recommended FTP Clients

### Option 1: FileZilla (Free, Visual)
1. Download from https://filezilla-project.org/
2. File → Site Manager → New Site
3. Enter your connection details
4. Connect

### Option 2: Cyberduck (Free, Mac/Windows)
1. Download from https://cyberduck.io/
2. Open Connection → FTP
3. Enter details and connect

### Option 3: Command Line (Mac/Linux)
```bash
# Connect
ftp ftp.yourdomain.com

# Or using lftp (better)
lftp ftp://username@yourdomain.com
```

## Upload Structure

```
Your Computer                    →  Hostinger Server
---------------------------------------------------------
/deployment/public_html/*        →  /public_html/
/deployment/public_html/.htaccess →  /public_html/.htaccess
/deployment/database/*           →  /home/u442226222/database/
Create manually                  →  /home/u442226222/logs/
```

## Step-by-Step Upload Process

### 1. Prepare Your Files
```bash
# Make the script executable
chmod +x prepare-for-upload.sh

# Run it
./prepare-for-upload.sh
```

### 2. Create config.local.php
```bash
cd deployment/public_html
cp config.local.example-hostinger.php config.local.php
# Edit with your actual values
```

### 3. Connect via FTP Client

#### Using FileZilla:
1. Connect to your server
2. Left side = your computer (`/deployment/public_html/`)
3. Right side = server (`/public_html/`)
4. Select all files on left
5. Right-click → Upload
6. Wait for completion

#### Important Files to Verify:
- [ ] `.htaccess` (might be hidden - enable hidden files)
- [ ] `config.local.php` (with your settings)
- [ ] All folders: `admin/`, `includes/`, `css/`, etc.

### 4. Create Directories on Server
In FTP client, navigate to `/home/u442226222/` and create:
- `database/` folder
- `logs/` folder (set permissions to 755)

### 5. Upload Database Files
Upload contents of `/deployment/database/` to `/home/u442226222/database/`

## File Permissions (Important!)

After upload, right-click in FTP client and set permissions:
- All `.php` files: 644
- All directories: 755  
- `.htaccess`: 644
- `logs/` directory: 755

## Quick Command-Line Alternative

If you prefer command line:

```bash
# Using lftp (install with: brew install lftp)
lftp ftp://u442226222@yourdomain.com <<EOF
mirror -R deployment/public_html/ /public_html/
mkdir -p /home/u442226222/logs
mkdir -p /home/u442226222/database
bye
EOF
```

## After Upload

1. **Test Connection**: Visit `https://yourdomain.com/test-db.php`
2. **Import Database**: Use phpMyAdmin in Hostinger hPanel
3. **Delete Test File**: Remove test-db.php via FTP
4. **Test Login**: Try Discord OAuth login

## For Future Updates

To update specific files:
1. Connect via FTP
2. Navigate to the file location
3. Upload the new version (overwrites old)
4. Clear browser cache

## Troubleshooting

- **500 Error**: Check `.htaccess` was uploaded correctly
- **404 Error**: Verify files are in `public_html/` not a subfolder
- **White Page**: Enable error display temporarily in config
- **Can't see .htaccess**: Enable "Show hidden files" in FTP client

Would you like me to create a specific update script for pushing individual file changes?