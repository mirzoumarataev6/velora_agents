<?php

use App\Services\Teams\AssertsTeamIdIsBackfilled;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2d-2, group (a): the 22 of the 26 `StampsTeam` tables that carry no
 * composite foreign key involving `team_id`'s sibling columns — read from
 * every `create_*_table` migration cited below, not guessed. None of these
 * reference `crm_workspaces`/`crm_stages` (the two tables with the
 * cascading-delete hazard `2026_10_17_000007_add_team_id_to_tenant_tables.php`
 * lines 24-36 describes and reproduces), so this group carries no ordering
 * constraint relative to itself or to groups (b)/(c) — it is listed first
 * only because it is the simplest.
 *
 * Verified empirically before writing this file (not assumed): flipping
 * `team_id` to NOT NULL on all 22 of these, via a real `php artisan migrate`
 * run against a scratch SQLite database seeded with one row per table,
 * followed by deleting the owning user (which cascades through every one
 * of them via `user_id` or, two hops, `agent_id`), raised no foreign key
 * error. No MySQL-only fallback is needed for this group.
 *
 * `$table->unsignedBigInteger('team_id')->nullable(false)->change()` is a
 * plain column-constraint change — no FK, no index — so on MySQL it is a
 * single in-place `ALTER TABLE ... MODIFY`, and on SQLite the usual
 * create-copy-drop-rename rebuild `SQLiteGrammar::compileAlter()` always
 * does for any `->change()`. That rebuild is exactly the operation
 * 2026_10_17_000007's docblock warns about for `deals`/`customers`/
 * `crm_workspaces`/`crm_stages` specifically — not for these 22 tables.
 */
return new class extends Migration
{
    use AssertsTeamIdIsBackfilled;

    private const TABLES = [
        'agents', 'usage_logs', 'bi_sources', 'bi_datasets', 'bi_columns',
        'bi_dashboards', 'bi_widgets', 'bi_connections', 'bi_chat_messages',
        'tasks', 'crm_notes', 'customer_merges', 'deal_activities',
        'knowledge_sources', 'channels', 'knowledge_chunks', 'conversations',
        'leads', 'datasets', 'dataset_products', 'database_connections', 'product_images',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            // Checked immediately before THIS table is altered, not once
            // for the whole file — see AssertsTeamIdIsBackfilled's own
            // docblock for why that is what keeps a failure from leaving
            // any table altered on bad data.
            $this->assertTeamIdBackfilled($table);

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('team_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('team_id')->nullable()->change();
            });
        }
    }
};
