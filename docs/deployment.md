# Deployment

True-Doctor is a standard Laravel 12 application. **The supported deployment
serves `public/` as the web server's document root at a domain root** — not a
path prefix. Everything else in this document follows from that.

> **The live site does not run on this shape.** true-doctor.online is on shared
> cPanel hosting, where the document root cannot be moved and there is no Node
> and no Redis server. See **[deployment-cpanel.md](deployment-cpanel.md)** for
> how that one is actually deployed. This document is the reference target —
> what to build towards on a dedicated host, and what the application assumes
> when nothing constrains it. Sections 3 and 7 apply to both.

## 1. Requirements

| Component | Version |
|---|---|
| PHP | 8.2+ with `bcmath`, `gd`, `intl`, `mbstring`, `pdo_mysql`, `redis` |
| MySQL | 8.0+ (`utf8mb4`) |
| Redis | 6+ (cache, sessions, queues via Horizon) |
| Node | 20.19+ (build only — `.nvmrc`) |
| Composer | 2.4+ |

## 2. Web server

```
DocumentRoot /var/www/true-doctor/current/public
```

- `public/.htaccess` (stock Laravel) handles the front controller. Do **not**
  use the repository-root `index.php`/`.htaccess`: they exist only for the
  MAMP subdirectory development setup and are ignored once `public/` is the
  document root.
- `APP_URL=https://true-doctor.online` (no path). With no path prefix,
  `AppServiceProvider::fixLivewireSubdirectoryUrls()` is a no-op.
- Behind a load balancer/CDN, configure trusted proxies
  (`bootstrap/app.php` → `$middleware->trustProxies(...)`) instead of relying
  on `APP_URL` to force HTTPS.

## 3. Environment

Copy `.env.example`, then set at minimum: `APP_ENV=production`,
`APP_DEBUG=false`, `APP_KEY` (generate once, never rotate without reading §7),
`APP_URL`, `DB_*`, `REDIS_*`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`,
`QUEUE_CONNECTION=redis`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`,
`MAIL_*`, `FLW_*`, `CORS_ALLOWED_ORIGINS`, `SUBSCRIPTION_GATE=true`,
`ONBOARDING_GATE=true`, `TELESCOPE_ENABLED=false`.

### Offline mode (Field Mode) needs `APP_URL` to be exactly right

Field Mode is a browser on this same origin, signed in with the ordinary
session cookie. Two things derive from `APP_URL` and both fail **silently** if
it is wrong:

- **`SANCTUM_STATEFUL_DOMAINS`.** Its default is built from `APP_URL`'s host
  and port. A request whose `Origin` is not in that list never gets a session,
  so `auth:sanctum` sees no bearer token and answers `401` — to every sync
  request, for ever, while `/admin` answers `200` beside it. Set
  `SANCTUM_STATEFUL_DOMAINS=true-doctor.online` explicitly if the app is
  reached on a host that is not `APP_URL`'s (a vanity domain, a staging
  alias, an internal hostname behind a proxy).
- **The `td-base` meta tag**, which is where the client and the service worker
  learn what path the app is served from. Wrong, and the worker never
  registers and the API is unreachable.

Neither throws. The only symptom is offline mode quietly not working, which
looks exactly like a device that is genuinely offline — so **check it after
every deploy that changes the host, the path or the proxy in front**:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  -H 'Accept: application/json' -H "Origin: $APP_URL" \
  -b cookiejar "$APP_URL/api/v1/sync/status"      # must be 200, not 401
```

`SESSION_SECURE_COOKIE=true` is required over HTTPS, and `SESSION_LIFETIME`
governs how long a device can go between syncs before somebody has to sign in
again. Nothing unsent is ever lost when that happens — the queue waits and the
screen says so — but a very short lifetime means saying so often.

## 4. Release steps

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build                # produces public/build — committed,
                                       # because the shared host has no Node
                                       # (see deployment-cpanel.md)
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link
php artisan horizon:terminate          # workers reload the new code
```

`route:cache` must succeed — `routes/web.php` contains no closures. CI runs the
same command as a smoke test.

## 5. Long-running processes

Supervise with systemd/supervisord (one unit each). **Horizon needs a Redis
server**; where there is none, `routes/console.php` schedules a short-lived
`queue:work` every minute instead, which needs nothing but the cron entry:

```
php artisan horizon
php artisan schedule:work      # or a cron entry: * * * * * php artisan schedule:run
```

Horizon's dashboard (`/admin/horizon`) is restricted to the SaaS super-admin.

## 6. Health & monitoring

- `GET /up` — framework liveness. A deeper `/health` (DB, cache, queue) is on
  the roadmap (`docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md`, M5).
- Logs: `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=14`. Ship to your aggregator.

## 7. Secrets and keys

- Never commit `.env*` (other than `.env.example`), `ssh.txt`, dumps or backups;
  `scripts/ci/secrets-scan.sh` fails the build on dump extensions and files
  over 5 MB.
- `APP_KEY` also seeds the prepaid-card lookup hash (`CardService`). Rotating
  it invalidates every card lookup unless the old key is kept in
  `APP_PREVIOUS_KEYS`. Treat rotation as a migration.

## 8. Subdirectory hosting (development only)

MAMP serves the repository root at `http://localhost:8888/true-doctor`. The
root `index.php` requires `public/index.php`, the root `.htaccess` proxies
requests into `public/` and **denies** the application tree and sensitive
extensions, and `APP_URL` carries the `/true-doctor` path so Livewire's
script/update URLs resolve. This is a convenience for local work, not a
deployment target.
