# Deployment Checklist for houserubi-ka.com on Hostinger

## First-Time Setup
- [ ] Add Discord redirect URI: `https://houserubi-ka.com/callback.php`
- [ ] Create MySQL database in Hostinger hPanel
- [ ] Import `database/schema.sql` and all migrations
- [ ] Upload all files from `/public_html/` via FTP
- [ ] Create `config.local.php` with database credentials
- [ ] Create `/logs/` directory outside public_html
- [ ] Test Discord login flow
- [ ] Verify admin access (Discord ID: 305074630744342528)

## For Each Update
- [ ] Test changes locally at http://localhost:8000
- [ ] Choose deployment method:
  - [ ] FTP client (FileZilla/Cyberduck)
  - [ ] Hostinger File Manager
  - [ ] Command line FTP
- [ ] Upload changed files to Hostinger
- [ ] Run any new database migrations
- [ ] Clear browser cache
- [ ] Test critical functions:
  - [ ] Login/logout
  - [ ] Submit resources
  - [ ] View dashboard
  - [ ] Admin panel access
- [ ] Check error logs at `/home/u_____/logs/`

## FTP Connection Details (Hostinger)
Get from hPanel → Files → FTP Accounts:
- **Host**: Your Hostinger FTP host
- **Username**: u_______ (your Hostinger username)
- **Password**: Your FTP password
- **Port**: 21 (FTP) or 22 (SFTP)
- **Path**: `/public_html/`

## What Never Gets Overwritten
- `config.local.php` (production database settings)
- Database contents
- User uploaded files
- Log files

## Emergency Rollback
1. Access Hostinger File Manager in hPanel
2. Restore from Hostinger backup
3. OR: Re-upload previous version files
4. Check database compatibility
5. Review error logs for issues