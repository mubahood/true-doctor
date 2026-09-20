# Deploying on shared cPanel hosting

How **true-doctor.online** is actually deployed. [deployment.md](deployment.md)
describes the reference target — a dedicated host with Redis, Horizon and the
document root pointed at `public/`. This host has none of that, so the shape
is different in three specific ways and identical in every other.

| Thing | Where |
| --- | --- |
| Site | <https://true-doctor.online> |
| Repository | <https://github.com/mubahood/true-doctor> (public) |
| Application | `~/true_doctor_app` — outside the web root |
| Document root | `~/true_doctor` — what Apache serves |
| PHP | 8.3 (cli and web) |
| Deploy | `~/true_doctor_app/deploy/deploy.sh` |

## The three constraints, and what is done about each

**The document root cannot be moved.** A cPanel addon domain's root is fixed
and it is not `public/`. So `deploy.sh` mirrors the contents of `public/` into
the document root and installs [`deploy/docroot-index.php`](../deploy/docroot-index.php),
which boots the application from `~/true_doctor_app` — **outside** the web
root. That keeps exactly the protection the `public/` layout exists to give:
`.env`, the source, `storage/` and `vendor/` sit somewhere Apache will not
serve even if a rewrite rule breaks one day.

**There is no Node.** `npm run build` cannot run on the host, so `public/build`
is committed — a few hundred kilobytes of compiled CSS and JS. Build locally
and commit the result alongside the change that caused it. `DesignSystemCssTest`
fails if the committed output stops matching the sources, so it cannot quietly
go stale.

**There is no Redis server.** The PHP extension is installed but nothing is
listening. `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` are all
`database`, and **Horizon is not used** — setting any of them to `redis` takes
the site down.

## Routine deploy

```bash
# locally
npm run build && git add -A && git commit && git push

# on the server
ssh schooics@gator4311.hostgator.com
~/true_doctor_app/deploy/deploy.sh
```

The script goes into maintenance mode first and comes back up even if it fails
part way, so a broken deploy leaves a 503 rather than a half-migrated
application. It never writes `.env`, never runs a destructive seeder, and never
deletes `.well-known` (SSL renewal needs it).

## First-time setup

```bash
# 1. Composer — not on $PATH here
mkdir -p ~/bin && cd ~/bin
curl -sS https://getcomposer.org/installer | php -- --install-dir=$HOME/bin --filename=composer

# 2. The application, BESIDE the document root, never inside it
cd ~ && git clone https://github.com/mubahood/true-doctor.git true_doctor_app
cd true_doctor_app && git checkout main

# 3. Database (cPanel prefixes both names with the account)
uapi Mysql create_database name=schooics_truedoctor
uapi Mysql create_user name=schooics_tduser password='<a long random one>'
uapi Mysql set_privileges_on_database user=schooics_tduser \
     database=schooics_truedoctor privileges=ALL

# 4. .env — never in the repository
cp .env.example .env    # then edit; see the table below
php artisan key:generate

# 5. Everything else
./deploy/deploy.sh

# 6. The first administrator. Prints a random password ONCE — save it.
php artisan db:seed --class=Database\\Seeders\\AdminUserSeeder --force

# 7. The scheduler. APPEND — this account runs other sites' jobs too.
crontab -l > /tmp/ct
echo "* * * * * cd ~/true_doctor_app && /usr/local/bin/php artisan schedule:run >/dev/null 2>&1" >> /tmp/ct
crontab /tmp/ct
```

### The `.env` that matters

| Key | Value | Why |
| --- | --- | --- |
| `APP_ENV` | `production` | Turns off the demo seeders and the demonstration door |
| `APP_DEBUG` | `false` | A stack trace on a public page is a disclosure |
| `APP_URL` | `https://true-doctor.online` | See the warning below — Field Mode depends on it |
| `APP_KEY` | generated once | **Never rotate casually** — see below |
| `DB_*` | the cPanel database | Both names are prefixed with the account |
| `CACHE_STORE` | `database` | No Redis server on this host |
| `SESSION_DRIVER` | `database` | Same |
| `QUEUE_CONNECTION` | `database` | Same — and every notification is queued |
| `SESSION_SECURE_COOKIE` | `true` | The site is HTTPS-only |
| `MAIL_MAILER` | `sendmail` | cPanel's local MTA works with no credentials |
| `MAIL_FROM_ADDRESS` | `noreply@true-doctor.online` | Must be a domain this host may send for |
| `TELESCOPE_ENABLED` / `DEBUGBAR_ENABLED` | `false` | Never in production |
| `PRICING_USD_RATE` | `3800` | See [public-site.md](public-site.md) |

### Two things that will bite, carried over from deployment.md §3 and §7

**`APP_KEY` also seeds the prepaid-card lookup hash** (`CardService`). Rotating
it invalidates every card lookup unless the old key goes into
`APP_PREVIOUS_KEYS`. Treat rotation as a migration, not as housekeeping.

**`APP_URL` has to be exactly right or Field Mode fails silently.**
`SANCTUM_STATEFUL_DOMAINS` defaults to `APP_URL`'s host; a request whose
`Origin` is not in that list never gets a session, so `auth:sanctum` answers
`401` to every sync request for ever while `/admin` answers `200` beside it.
The `td-base` meta tag comes from the same place. Neither throws. After any
deploy that changes the host, the path or the proxy in front:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  -H 'Accept: application/json' -H "Origin: https://true-doctor.online" \
  -b cookiejar "https://true-doctor.online/api/v1/sync/status"   # 200, not 401
```

## The queue

Every notification implements `ShouldQueue`, so that a mail provider having a
bad afternoon cannot turn somebody's sign-up into a 500. The other half of that
bargain is that something must drain the queue — otherwise mail is accepted and
then silently never sent, which is worse than failing loudly.

`routes/console.php` schedules a short-lived worker every minute
(`queue:work --stop-when-empty --max-time=55`), not a daemon: there is no
supervisor here, and a long-running process would be killed without notice and
never come back. **The one-minute cron entry above is the only thing keeping
mail flowing.**

```bash
php artisan queue:monitor default     # how deep
php artisan queue:failed              # what gave up
```

## After deploying, check these

```bash
curl -sI https://true-doctor.online | head -1          # 200
curl -s  https://true-doctor.online/up                 # health check
curl -sI https://true-doctor.online/.env | head -1     # MUST be 403 or 404
```

The third is the one that matters. If `.env` is ever fetchable, the document
root is wrong and the application has ended up inside the web root.

## Rolling back

```bash
cd ~/true_doctor_app
git log --oneline -10
git reset --hard <the good commit>
./deploy/deploy.sh
```

Migrations are **not** rolled back, and should not be — reversing one that
dropped a column does not bring the data back. If a release contains a
migration you might need to undo, take a dump before deploying it.

## What is never in the repository

`.env`, `ssh.txt`, any `*.sql` dump, `auth.json`, `storage/*.key`. All are in
`.gitignore` and `scripts/ci/secrets-scan.sh` fails the build if one is staged.
The repository is **public** — anything committed is published the moment it is
pushed, and stays published in forks and caches after any delete.
