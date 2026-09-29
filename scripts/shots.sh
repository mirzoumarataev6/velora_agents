#!/usr/bin/env bash
#
# Boot a throwaway, demo-seeded copy of the app and screenshot every surface in
# both themes at 1920x1080 via scripts/shots.mjs.
#
# The dev MySQL database is never touched: this runs against a temp SQLite file
# with its own file session/cache drivers.
#
# Usage:  bash scripts/shots.sh [outdir]
#         SHOT_ONLY=dashboard bash scripts/shots.sh      # one surface
#         SHOT_THEMES=light   bash scripts/shots.sh      # one theme
set -euo pipefail

cd "$(dirname "$0")/.."

OUT="${1:-docs/screenshots/redesign}"
PORT="${SHOT_PORT:-8899}"
BASE="http://127.0.0.1:${PORT}"
TMP="$(mktemp -d)"
DB="$TMP/shots.sqlite"

export DB_CONNECTION=sqlite DB_DATABASE="$DB" DB_FOREIGN_KEYS=true \
    APP_URL="$BASE" SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=database \
    APP_ENV=local APP_DEBUG=false XDG_CONFIG_HOME="$TMP/config"

mkdir -p "$XDG_CONFIG_HOME"
touch "$DB"

echo "→ migrating + seeding a throwaway database"
php artisan config:clear >/dev/null
php artisan migrate --force --no-interaction >/dev/null
php artisan tinker --execute '
    App\Models\User::forceCreate([
        "name" => "Демо", "email" => "demo@velora.test",
        "password" => bcrypt("password"), "email_verified_at" => now(),
    ]);' >/dev/null
php artisan db:seed --class=DemoSeeder --force --no-interaction >/dev/null

echo "→ serving on $BASE"
php artisan serve --host=127.0.0.1 --port="$PORT" --no-reload >"$TMP/serve.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true; rm -rf "$TMP"' EXIT

for _ in $(seq 1 40); do
    curl -sf -o /dev/null "$BASE/ru/login" && break
    sleep 0.5
done

echo "→ capturing → $OUT"
SHOT_BASE="$BASE" SHOT_OUT="$OUT" node scripts/shots.mjs
