<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable `team_id` to every table that is scoped today by `user_id`
 * or by `agent_id` (one hop from `agents.user_id`). Nullable on purpose — this
 * is pure schema, read by nothing yet. The backfill command
 * (`team:migrate-users`) populates it; a later, separate migration flips it
 * to NOT NULL once `team:verify-migration` passes clean.
 *
 * Deliberately denormalized onto the agent-scoped tables too, rather than
 * leaving `team_id` reachable only via a join through `agent->team_id` — the
 * same reasoning `bi_datasets`/`dataset_products` already apply to
 * `user_id`/`agent_id`: a forgotten join can leak; a forgotten where cannot.
 *
 * `messages` is deliberately absent: it carries neither `user_id` nor
 * `agent_id` today (it reaches its owner via `conversation->agent->user_id`),
 * so it stays a two-hop lookup for now — a later stage decides whether to
 * close that gap when it rewrites MessagePolicy.
 *
 * No foreign key constraint on purpose, unlike every other new column in
 * this stage. SQLite (the test connection) cannot add a column with an
 * inline REFERENCES clause without rebuilding the table, and rebuilding
 * `deals`/`customers`/`crm_workspaces`/`crm_stages` reorders their existing
 * foreign keys in sqlite_master — which changes the order SQLite visits
 * tables in in a cascading delete, which trips the composite
 * `(user_id, crm_workspace_id)`/`(user_id, crm_stage_id)` RESTRICT
 * constraints added by `create_crm_workspaces_table`/`create_crm_stages_table`
 * (reproduced: deleting a user with a deal attached raises a FOREIGN KEY
 * constraint failed error that does not occur without this migration). A
 * bare, unconstrained column sidesteps the rebuild entirely — Stage 1 reads
 * nothing from `team_id` yet, so there is nothing to protect with a DB-level
 * constraint until a later stage adds it deliberately, with this ordering
 * hazard in mind.
 */
return new class extends Migration
{
    private const TABLES = [
        'agents',
        'usage_logs', 'deals', 'bi_sources', 'bi_datasets', 'bi_columns',
        'bi_dashboards', 'bi_widgets', 'bi_connections', 'bi_chat_messages',
        'tasks', 'customers', 'crm_workspaces', 'crm_stages', 'crm_notes',
        'customer_merges', 'deal_activities',
        'knowledge_sources', 'channels', 'knowledge_chunks', 'conversations',
        'leads', 'datasets', 'dataset_products', 'database_connections', 'product_images',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('team_id')->nullable();
                $table->index('team_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('team_id');
            });
        }
    }
};
