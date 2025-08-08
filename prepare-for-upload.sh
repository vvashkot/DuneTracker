#!/bin/bash
# Prepare files for Hostinger upload

echo "Preparing Dune Tracker for Hostinger deployment..."

# Create deployment directory
mkdir -p deployment/public_html
mkdir -p deployment/database
mkdir -p deployment/logs

# Copy all public files
cp -r public_html/* deployment/public_html/
cp .htaccess.hostinger deployment/public_html/.htaccess

# Copy database files
cp -r database/* deployment/database/

# Create empty log directory
touch deployment/logs/.gitkeep

# Remove any local config files
rm -f deployment/public_html/config.local.php
rm -f deployment/public_html/test-db.php

# Create a file list for reference
find deployment -type f > deployment/file-list.txt

echo "Deployment package ready in ./deployment/"
echo "Total files: $(find deployment -type f | wc -l)"
echo ""
echo "Next steps:"
echo "1. Update deployment/public_html/config.local.php with your settings"
echo "2. Upload deployment/public_html/* to Hostinger public_html/"
echo "3. Upload deployment/database/* to a safe location for import"
echo "4. Create logs/ directory in your Hostinger home folder"