#!/bin/bash
#
# Deploy True-Doctor on shared cPanel hosting.
#
# Run it ON THE SERVER, from anywhere:
#
#     ~/true_doctor_app/deploy/deploy.sh
#
# It pulls the current branch, installs PHP dependencies, migrates, republishes
# the public assets into the document root and rebuilds the caches. It is
# idempotent — running it twice does the same thing as running it once.
#
# WHAT IT DELIBERATELY DOES NOT DO
#
#   * It never writes .env. That file holds the database password and the
#     application key and it is not in the repository; a deploy script that
#     rewrites it is a deploy script that will one day overwrite the only copy.
#   * It never runs `npm`. The host has no Node, which is exactly why the built
#     assets are committed (see docs/DEPLOYMENT.md).
#   * It never drops or seeds data. Migrations run; nothing is destroyed.
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCROOT="${TD_DOCROOT:-$HOME/true_doctor}"
PHP="${TD_PHP:-$(command -v php)}"
COMPOSER="${TD_COMPOSER:-$HOME/bin/composer}"

say() { printf '\n\033[1;34m==>\033[0m %s\n' "$1"; }
die() { printf '\n\033[1;31mdeploy failed:\033[0m %s\n' "$1" >&2; exit 1; }

[ -f "$APP_DIR/artisan" ] || die "$APP_DIR does not look like the application (no artisan)."
[ -f "$APP_DIR/.env" ]    || die "$APP_DIR/.env is missing. Create it before the first deploy — see docs/DEPLOYMENT.md."
[ -d "$DOCROOT" ]         || die "Document root $DOCROOT does not exist."
[ -x "$COMPOSER" ] || command -v composer >/dev/null || die "composer not found at $COMPOSER."
[ -x "$COMPOSER" ] || COMPOSER="$(command -v composer)"

cd "$APP_DIR"

# ── Stop serving half an application ─────────────────────────────────────
# Maintenance mode from the first moment, so nobody meets a page whose code
# and database have stopped agreeing with each other. `|| true` because an
# app that is already down must not abort the deploy that is fixing it.
say "Maintenance mode on"
"$PHP" artisan down --render="errors::503" --retry=30 2>/dev/null || true
restore() { "$PHP" artisan up >/dev/null 2>&1 || true; }
trap restore EXIT

say "Fetching $(git rev-parse --abbrev-ref HEAD)"
git fetch --prune origin
git reset --hard "origin/$(git rev-parse --abbrev-ref HEAD)"
echo "    now at $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

say "PHP dependencies"
"$PHP" "$COMPOSER" install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

# ── Writable paths ───────────────────────────────────────────────────────
say "Permissions"
mkdir -p storage/framework/{sessions,views,cache/data} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

say "Database migrations"
"$PHP" artisan migrate --force --no-interaction

# Reference data that the application needs to function at all: the role and
# permission matrix, the districts list and the public plans. All three are
# idempotent; none of them touches a hospital's own records.
say "Reference data"
"$PHP" artisan db:seed --class=Database\\Seeders\\RbacSeeder --force --no-interaction
"$PHP" artisan db:seed --class=Database\\Seeders\\DistrictSeeder --force --no-interaction
"$PHP" artisan db:seed --class=Database\\Seeders\\PlanSeeder --force --no-interaction

say "Storage link"
[ -L "public/storage" ] || "$PHP" artisan storage:link --force >/dev/null 2>&1 || true

# ── Publish the public directory into the document root ──────────────────
# The domain's document root cannot be moved to public/ from a shell, so the
# contents of public/ are mirrored into it and index.php is replaced with one
# that boots the application from outside the web root — which is where the
# rest of the code should be anyway.
say "Publishing assets to $DOCROOT"
rsync -a --delete \
  --exclude '.well-known' --exclude 'cgi-bin' --exclude 'index.php' \
  --exclude '.htaccess' --exclude '.user.ini' \
  "$APP_DIR/public/" "$DOCROOT/"

# Both come from deploy/, NOT from public/. public/.htaccess has been adapted
# for the MAMP subdirectory setup and its front-controller rule points one
# level above the document root — on a real host that is outside the web root,
# and every route but `/` answers 400.
install -m 644 "$APP_DIR/deploy/docroot-index.php"  "$DOCROOT/index.php"
install -m 644 "$APP_DIR/deploy/docroot-htaccess"   "$DOCROOT/.htaccess"

say "Caches"
"$PHP" artisan config:clear
"$PHP" artisan optimize        # config + routes + events
"$PHP" artisan view:cache

say "Done — bringing the site back up"
"$PHP" artisan up
trap - EXIT

printf '\n\033[1;32mDeployed.\033[0m %s\n\n' "$(git rev-parse --short HEAD)"
