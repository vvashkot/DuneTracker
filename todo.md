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
- [x] Weekly hub registrations (track who is registered this week)
- [x] Filters contributed per player vs weekly requirement (user progress + quick add)
- [x] Move-out / Move-in assistance logs (user self-log)
- [ ] Circuit roster (weekly) – add user-visible view
- [x] Admin page to manage hub chores and quotas

🚜 Group Farming (High Priority)
- [ ] Persist Spice→Melange distributions to `run_distributions` (with duplicate protection)
- [ ] Add time participation tracking (`left_at` or sessions) for weighted-by-time shares + UI to mark leave/join
- [ ] Multi-run distribution (select multiple runs in preview)
- [ ] Weekly/daily run stats (participants, totals, outputs) + user "My Run Payouts"

📦 Other Contributions (Medium)
- [ ] Report: other hub resources contributed by players (filters, supplies)
- [ ] Top resource contributors (date range) – public leaderboard

🏛️ Landsraad (Medium)
- [x] Table for Landsraad points per player
- [x] Admin page to add/view points
- [ ] Leaderboards and weekly totals – public view

⚔️ Combat Statistics (Medium)
- [x] Table for combat events (ground kills, air kills, weapon)
- [x] Admin page to add/view combat logs
- [ ] Top weapons/players, daily/weekly summaries – public view and weekly charts

▶ Next Up (Implementation Order)
1) Persist Spice→Melange distribution (single-run) with duplicate guard + UI button on run page
2) Circuit roster user-visible view (current week)
3) Landsraad public leaderboard + weekly totals
4) Combat public leaderboards + weekly charts
5) Time participation tracking (left/join) and weighted-by-time distribution
6) Multi-run distribution workflow

🗄️ Database Migrations
- [ ] 019_add_run_participant_left_at.sql
- [ ] 020_add_hub_tables.sql
- [ ] 021_add_landsraad_points.sql
- [ ] 022_add_combat_events.sql