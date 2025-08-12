✅ To-Do List
🧰 Setup & Config
 Register Discord app and generate client ID/secret
 Add redirect URI in Discord Developer Portal
 Create MySQL database and user in GoDaddy cPanel
 Upload starter PHP files to /public_html/
🔐 Discord OAuth
 Build login.php to redirect to Discord
 Build callback.php to exchange token + fetch user
 Store Discord ID, username, avatar in session
 Optionally insert/update into users table
 Build auth.php to gatekeep pages
💾 Backend + DB Logic
 Create db.php for MySQL connection
 Create config.php to store constants
 Protect routes with auth.php
 Insert user into users table if not found
📥 Contribution Form
 Build submit.php:
Dropdown for resource
Input for quantity
Optional notes
 Insert into contributions with user_id
📊 Dashboard
 Build index.php:
Totals by resource type
Table of recent contributions
Show logged-in user's name + avatar
👤 Contribution Log
 Build my-contributions.php
Table of user submissions
Totals per resource
Optional export to CSV
🔐 Optional Admin Panel
 Build admin.php
Full table of all contributions
Filter by user or resource
Distribution logs
🧪 Testing
 Test login/logout flow
 Test form validation and DB inserts
 Test dashboard aggregation and display
 Test session persistence across pages
🌐 Deployment
 Final upload to GoDaddy /public_html/
 Enable HTTPS if needed
 Final production config (client secrets, DB creds)

🏠 Hub Chores (High Priority)
- [ ] Weekly hub registrations (track who is registered this week)
- [ ] Filters contributed per player vs weekly requirement
- [ ] Move-out / Move-in assistance logs
- [ ] Circuit roster (weekly)
- [ ] Admin page to manage hub chores and quotas

🚜 Group Farming (High Priority)
- [ ] Persist Spice→Melange distributions to `run_distributions`
- [ ] Add time participation tracking (`left_at` or sessions) for weighted-by-time shares
- [ ] Multi-run distribution (select multiple runs in preview)
- [ ] Weekly/daily run stats (participants, totals, outputs)

📦 Other Contributions (Medium)
- [ ] Report: other hub resources contributed by players (filters, supplies)
- [ ] Top resource contributors (date range)

🏛️ Landsraad (Medium)
- [ ] Table for Landsraad points per player
- [ ] Admin page to add/view points
- [ ] Leaderboards and weekly totals

⚔️ Combat Statistics (Medium)
- [ ] Table for combat events (ground kills, air kills, weapon)
- [ ] Admin page to add/view combat logs
- [ ] Top weapons/players, daily/weekly summaries

🗄️ Database Migrations
- [ ] 019_add_run_participant_left_at.sql
- [ ] 020_add_hub_tables.sql
- [ ] 021_add_landsraad_points.sql
- [ ] 022_add_combat_events.sql