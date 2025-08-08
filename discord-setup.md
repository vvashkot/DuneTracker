# Discord OAuth Setup Guide

## 1. Create Discord Application

1. Go to https://discord.com/developers/applications
2. Click "New Application"
3. Name it (e.g., "Dune Tracker Dev")
4. Click "Create"

## 2. Get Your Credentials

1. In your application, go to "OAuth2" → "General"
2. Copy the **Client ID** (this is a long number like `1234567890123456789`)
3. Copy the **Client Secret** (click "Reset Secret" if needed)

## 3. Add Redirect URI

1. Still in "OAuth2" → "General"
2. Add Redirect URI: `http://localhost:8000/callback.php`
3. Click "Save Changes"

## 4. Update Your Local Config

Create/edit `public_html/config.local.php`:

```php
<?php
// Copy this from config.local.example.php and update:

define('DISCORD_CLIENT_ID', '1234567890123456789'); // Your actual client ID
define('DISCORD_CLIENT_SECRET', 'your-actual-secret-here'); // Your actual secret
define('DISCORD_REDIRECT_URI', 'http://localhost:8000/callback.php');

// Rest of the config...
```

## 5. Test It

1. Restart your PHP server
2. Go to http://localhost:8000
3. Click login - should redirect to Discord
4. Authorize the app
5. Should redirect back and log you in!

## Common Issues

**"Value is not snowflake" error**
- You're using the placeholder text instead of a real Discord client ID
- Discord IDs are long numbers (18-19 digits)

**"Invalid redirect URI" error**
- Make sure the redirect URI in Discord matches EXACTLY
- Include the `http://` and port `:8000`

**"Unauthorized" error**
- Check your client secret is correct
- Make sure you're using the local config file