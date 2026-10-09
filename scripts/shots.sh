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
    ]);
    App\Models\User::forceCreate([
        "name" => "Новый владелец", "email" => "empty@velora.test",
        "password" => bcrypt("password"), "email_verified_at" => now(),
    ]);' >/dev/null
php artisan db:seed --class=DemoSeeder --force --no-interaction >/dev/null
# The BI board runs the real ingest pipeline, so it needs the queue to be
# synchronous — which it is here, because nothing starts a worker.
php artisan db:seed --class=BiDemoSeeder --force --no-interaction >/dev/null
php artisan tinker --execute '
    $board = App\Models\BiDashboard::where("name", "Продажи · Таджикистан")->firstOrFail();
    $dataset = $board->mainDataset;
    $spec = app(App\Services\Bi\Query\SpecValidator::class)->validate($dataset, [
        "dimensions" => [["column" => "c1"]],
        "measures" => [["aggregate" => "sum", "column" => "c7"]],
        "sorts" => [["field" => "m0", "direction" => "desc"]], "limit" => 6,
    ]);
    $result = app(App\Services\Bi\Query\QueryRunner::class)->run($spec);
    foreach ([
        ["user", "Покажи выручку по регионам", []],
        ["assistant", "Сумма по «Выручка» для каждого «Регион», все записи.", ["effects" => [[
            "kind" => "answer", "rows" => $result->rows, "columns" => $result->columns,
            "has_more" => $result->hasMore,
        ]]]],
    ] as [$role, $content, $payload]) {
        $message = new App\Models\BiChatMessage(compact("role", "content", "payload"));
        $message->user_id = $board->user_id;
        $message->bi_dashboard_id = $board->id;
        $message->save();
    }' >/dev/null
if [[ "${SHOT_DEBUG_BI:-0}" == "1" ]]; then
    php artisan tinker --execute 'App\Models\BiColumn::orderBy("ordinal")->get(["source_name", "type", "role", "distinct_count"])->each(fn ($c) => print($c->source_name." | ".$c->type->value." | ".$c->role->value." | ".$c->distinct_count."\n"));'
fi

echo "→ serving on $BASE"
php artisan serve --host=127.0.0.1 --port="$PORT" --no-reload >"$TMP/serve.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true; rm -rf "$TMP"' EXIT

for _ in $(seq 1 40); do
    curl -sf -o /dev/null "$BASE/ru/login" && break
    sleep 0.5
done

echo "→ capturing → $OUT"
# A focused audit driver can reuse exactly the same isolated demo data. The
# default remains the full screenshot run used by the documentation.
SHOT_BASE="$BASE" SHOT_OUT="$OUT" node "${SHOT_DRIVER:-scripts/shots.mjs}"
