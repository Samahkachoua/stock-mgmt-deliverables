# Stock Management System

Multi-warehouse stock management for a 3PL: stock always belongs to a customer.
Receive, Issue, Transfer (dispatch → receive), Adjustment, Damage and Opening
Balance documents, approvals above limits, reports with Excel/PDF export, and
Excel import of items and opening balances. English, Arabic (right to left)
and French; light and dark themes.

Laravel 12 · Livewire 3 · Tailwind 4 · PostgreSQL (Supabase, used as plain
Postgres) · maatwebsite/excel · mPDF.

Developer rules (read before changing anything): [`CLAUDE.md`](CLAUDE.md).
Requirements: `docs/SRS.docx`. Endpoints: [`docs/api_endpoints.md`](docs/api_endpoints.md).
Acceptance testing: [`docs/uat-checklist.md`](docs/uat-checklist.md).
Performance results: [`docs/performance.md`](docs/performance.md).

---

## 1. Local setup

Needs PHP 8.3+ (extensions: pdo_pgsql, mbstring, gd, zip, xml, intl), Composer,
Node 20+, and Docker (for the Supabase CLI's local Postgres).

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

npx supabase start                 # local Postgres on port 54322 (see .env)
php artisan migrate
php artisan db:seed                # demo data: warehouses, customers, items, stock
```

Demo accounts (password `password`): `admin@`, `manager@`, `operator@`
(Beirut Main only), `dataentry@`, `auditor@`, `customer@` — all `@example.test`.

Run it (three terminals):

```bash
php artisan serve --port=8080      # the app: http://127.0.0.1:8080
npm run dev                        # styles and scripts, with live reload
php artisan queue:work --tries=1   # reads uploaded Excel imports
```

> If pages show without styles, `public/hot` is left over from a stopped
> `npm run dev`: start it again, or delete `public/hot` and run `npm run build`.

## 2. Checks before every commit

```bash
php artisan test                   # Pest, against the stock_test database (never SQLite)
vendor/bin/pint                    # formatting
vendor/bin/phpstan analyse         # static analysis
```

Tests need the local Postgres running and a `stock_test` database
(`createdb -h 127.0.0.1 -p 54322 -U postgres stock_test`). They never touch
the hosted project.

Performance check on 200 items and 100,000 movements (separate database):

```bash
export DB_DATABASE=stock_perf      # createdb ... stock_perf first
php artisan migrate --force && php artisan db:seed --class=LocalSeeder --force
php artisan db:seed --class=PerformanceSeeder --force
php artisan test tests/Performance
```

## 3. Deploy (hosted Supabase)

### 3.1 Supabase project, once

1. Create the project; note the **database password**.
2. **Turn the Data API off** (Project Settings → Data API). The app talks to
   Postgres directly; every table also has row-level security enabled with no
   policies, so the Data API could read nothing anyway.
3. Storage → create a **private** bucket `imports`. Storage → Settings → S3
   Connection → create an access key.
4. Database → Connection string → **Session pooler** (or Direct). Use port
   **5432**, never the transaction pooler on 6543 (Laravel needs prepared
   statements and `SELECT … FOR UPDATE`).

### 3.2 Server environment

A Linux server (or platform) with PHP 8.3 FPM + Nginx, Composer, Node 20 for
the build. Set these in `.env` (all listed in `.env.example`):

| Setting | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_KEY` | `php artisan key:generate --show` once, then keep it (it encrypts sessions) |
| `APP_URL` | the public `https://` address |
| `DB_HOST` / `DB_PORT` | `aws-0-<region>.pooler.supabase.com` / `5432` |
| `DB_USERNAME` | `postgres.<project-ref>` |
| `DB_PASSWORD` | the database password |
| `DB_SSLMODE` | `require` |
| `SESSION_SECURE_COOKIE` / `SESSION_ENCRYPT` | `true` / `true` |
| `IMPORT_DISK` | `s3` |
| `AWS_*` | Supabase S3 key, region, bucket `imports`, `AWS_ENDPOINT=https://<project-ref>.supabase.co/storage/v1/s3`, `AWS_USE_PATH_STYLE_ENDPOINT=true` |
| `MAIL_*` | your SMTP provider (for the nightly stock check alert) |
| `RECONCILE_MAIL_TO` | who gets that alert (empty = all administrators) |
| `TRUSTED_PROXIES` | `*` behind a platform load balancer, else empty |
| `LOG_STACK` / `LOG_LEVEL` | `daily` / `info` |

### 3.3 Each release

```bash
php artisan down --retry=60                      # users see the translated "back in a few minutes" page
git pull                                         # or unpack the release
composer install --no-dev --optimize-autoloader
npm ci && npm run build && rm -f public/hot
php artisan migrate --force                      # never migrate:fresh in production
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart                        # workers pick up the new code
php artisan up
```

Writable by the web server: `storage/` and `bootstrap/cache/`.
Health check for the platform: `GET /up`.

### 3.4 First administrator (new installation only)

```bash
php artisan user:create-admin --email=you@company.com --name="Your Name"
```

It asks for a password (12+ characters). On an empty system it also creates
the first warehouse, since an account needs one to open any page. Everything
else (warehouses, customers, units, users, approval limits) is set up in the
app under **Settings**.

### 3.5 Queue worker

Excel imports are read in the background. Keep one worker running, restarted
if it stops. With Supervisor (`/etc/supervisor/conf.d/stock-worker.conf`):

```ini
[program:stock-worker]
command=php /var/www/stock/artisan queue:work --tries=1 --timeout=600 --sleep=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=660
stdout_logfile=/var/www/stock/storage/logs/worker.log
```

Without a worker, uploads stay at "Reading the file…".

### 3.6 Scheduler

One cron entry runs everything scheduled in `routes/console.php`:

```cron
* * * * * cd /var/www/stock && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled today:

- **02:00 UTC, nightly — `stock:reconcile`:** checks every stock balance against
  the sum of its movements. On any difference it logs each one (level `critical`)
  and emails the administrators; it changes nothing. Run it by hand any time:
  `php artisan stock:reconcile`.
- **Weekly:** prunes failed queue jobs older than 30 days.

## 4. Backup and restore

The SRS asks for daily backups kept 30 days (NFR-6). Supabase's own daily
backups are kept 7 days on the Pro plan, so also take your own dump, from a
machine other than the app server:

```bash
# Daily, e.g. 03:00 (after the stock check). Only the app's schema: public.
export PGPASSWORD='<database password>'
pg_dump "host=aws-0-<region>.pooler.supabase.com port=5432 dbname=postgres user=postgres.<project-ref> sslmode=require" \
  --schema=public --no-owner --no-privileges --format=custom \
  --file="stock-$(date +%F).dump"
# Keep 30 days:
find /backups -name 'stock-*.dump' -mtime +30 -delete

# Uploaded import files (Supabase Storage, S3 API):
aws s3 sync s3://imports /backups/imports --endpoint-url https://<project-ref>.supabase.co/storage/v1/s3
```

**Restore** (into a new, empty database — never over the live one):

```bash
createdb -h <host> -p 5432 -U <user> stock_restore      # or a new Supabase project
pg_restore --no-owner --no-privileges --dbname="host=<host> port=5432 dbname=stock_restore user=<user> sslmode=require" stock-YYYY-MM-DD.dump
```

Then point a copy of the app at it (`DB_DATABASE=stock_restore`) and check:

```bash
php artisan migrate:status          # all migrations "Ran"
php artisan stock:reconcile         # "Every balance matches its movements."
```

Only switch the live app over once both are clean. Try a restore at least
once a quarter, so you know it works before you need it.

## 5. Troubleshooting

| Symptom | Check |
|---|---|
| Pages without styles | `public/hot` left over (see §1); `npm run build` ran? |
| Upload stays "Reading the file…" | queue worker running (§3.5)? `storage/logs/worker.log` |
| "No warehouse assigned" after signing in | the account has no warehouse: Settings → Users |
| Nightly alert email | `storage/logs` (`stock:reconcile` entries) lists each warehouse / customer / item; a difference means something changed stock outside the app's screens — investigate before relying on those quantities |
| `prepared statement … does not exist` | connected through the transaction pooler (6543): use 5432 (§3.1) |
