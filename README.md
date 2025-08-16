# Dune Awakening Guild Resource Tracker

A PHP 8 + MySQL web app for tracking guild resources, farming runs, hub chores, Landsraad points, combat stats, and more. Includes Discord OAuth, admin tools, reports, AI-powered submission, and Discord notifications.

## Tech Stack
- PHP 8 (PDO MySQL)
- MySQL (Hostinger)
- Discord OAuth2 (identify)
- Sessions + CSRF protection
- Vanilla HTML/CSS/JS (+ Chart.js)
- Discord webhooks & optional bot DM
- OpenAI API (optional, for AI submit)

## Repo Layout
- `public_html/` – All web-accessible code (deployed to server docroot)
- `database/migrations/` – SQL migrations (note: for subtree deploy, see below)
- `todo.md` – Development roadmap

Key app files (under `public_html/`):
- `includes/` – `auth.php`, `db.php`, `webhooks.php`, `config-loader.php`
- `config.php` – Defaults for local/dev
- `config.local.php` – Production overrides (not committed)
- `admin/` – Admin tools (users, inventory, migrations, etc.)
- `deploy/run-migrations.php` – Token-protected migration runner endpoint

## Configuration
Create `public_html/config.local.php` on the server with real values. Important constants:

- Database
  - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- Discord OAuth
  - `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`
  - `DISCORD_REDIRECT_URI` (use exact host, e.g. `https://houserubi-ka.com/callback.php`)
  - `DISCORD_SCOPE` (`identify`)
- App + Session
  - `APP_URL`, `SESSION_NAME`, `SESSION_LIFETIME`
  - Recommended for production:
    - `ini_set('session.cookie_secure', 1);`
    - `ini_set('session.cookie_samesite', 'Lax');`
    - `ini_set('session.cookie_domain', '.houserubi-ka.com');` // so apex/www share session
- Discord Notifications
  - Webhooks: configure in `includes/webhooks.php` or admin UI
  - Direct Message (optional): `DISCORD_BOT_TOKEN`, `DISCORD_DM_USER_ID`
- OpenAI (optional; for AI submit): `OPENAI_API_KEY`

## Database Migrations
- Add SQL files to `database/migrations/` using incremental numeric prefixes (e.g., `023_add_circuit_number.sql`).
- Run migrations:
  - Admin: `Admin → Migrations` page; or
  - Endpoint: `https://yourdomain.com/deploy/run-migrations.php?token=YOUR_TOKEN`
- MySQL note: DDL autocommits; do not wrap `CREATE/ALTER TABLE` in manual transactions.

If you deploy only `public_html` (via subtree), ensure the migration SQL files also exist under `public_html/database/migrations/` so the server can see them.

## OAuth & Login Notes
- We enforce a canonical host on `login.php` and `callback.php` to match `DISCORD_REDIRECT_URI` so OAuth begins and ends on the same host.
- If you ever see “Invalid state parameter” loops:
  - Ensure users access the same host (apex vs www); the app normalizes automatically.
  - Verify `session.cookie_domain` is set to `.houserubi-ka.com`.
  - Clear cookies and retry.

## AI Submit
- Set `OPENAI_API_KEY` in `config.local.php` (or environment) if you want AI parsing for submissions.
- AI supports multi-resource parsing, participant mapping, equal/weighted splits, and refined outputs.

## Deployment (Hostinger Git Auto-Deploy)
There are two ways to deploy. Recommended is to deploy only `public_html` using a dedicated branch via Git subtree.

### A) Subtree Deploy (recommended)
This creates a `deploy` branch that contains only the contents of `public_html` at its root.

Initial setup (one-time):
```bash
# Create deploy branch from the current public_html contents
git subtree split --prefix public_html -b deploy
# Push the deploy branch to GitHub
git push -u origin deploy
```

Update the deploy branch after you change files under `public_html/`:
```bash
# Push only public_html changes to the deploy branch
git subtree push --prefix public_html origin deploy
```

Hostinger control panel:
- Add a Git deployment, set repository to your GitHub repo, branch `deploy`, target directory to your server `public_html`.
- Target directory must be empty on first setup (backup and restore `config.local.php` afterwards if needed).
- Enable webhooks/auto-deploy if available.

After deployment, run migrations:
- Open Admin → Migrations, or hit the token endpoint: `/deploy/run-migrations.php?token=...`

### B) Full-repo Deploy (not typical here)
If deploying the repo root directly, ensure your server docroot points to `public_html/`. Most shared hosts don’t support this cleanly; prefer subtree (A).

## Common Tasks
- Commit and push feature work (regular dev branch):
```bash
git add -A
git commit -m "Your message"
git push origin main
```
- Trigger production deploy (subtree):
```bash
# From repo root
git subtree push --prefix public_html origin deploy
```
- Run DB migrations after deploy:
  - Admin → Migrations, or `GET /deploy/run-migrations.php?token=...`

## Troubleshooting
- “Invalid state parameter” on login:
  - Use the apex host in `DISCORD_REDIRECT_URI`.
  - Ensure session cookie domain `.houserubi-ka.com` is set.
  - We already enforce canonical host in `login.php`/`callback.php`.
- “Project directory is not empty” in Hostinger:
  - Empty `public_html` before initial Git deploy (backup `config.local.php`), or deploy to a fresh folder and switch.
- “No new migrations to apply” but you added files:
  - Confirm migration files exist under `public_html/database/migrations/` if you use subtree deploy.
- Duplicate column errors while migrating:
  - The column already exists; adjust the migration or mark it as applied.
- AI submit fails:
  - Verify `OPENAI_API_KEY` and check server error logs.
- Admin inventory update fails:
  - Ensure numeric inputs are sanitized (committed fix is in place).

## Security Notes
- Keep `config.local.php` only on the server; do not commit it.
- Use HTTPS (cookie_secure=1).
- Do not expose secrets in the repo or screenshots.

## LICENSE
Internal guild project (add license if needed).
