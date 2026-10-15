<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every dashboard widget reading "assistant replies since $since" —
 * unanswered(), askedProducts(), responseTrend(), averageResponseMs() — had
 * no index to filter `role` or `created_at` on, only `conversation_id`. On a
 * seeded account (12 agents, 3k conversations, 42k messages) EXPLAIN ANALYZE
 * showed the worst of them (ORDER BY id DESC LIMIT 500 over a sparse match)
 * doing a full reverse scan of the whole messages table — 42,080 rows read
 * to return 342 — because matching rows are not clustered near either end of
 * the id range. (conversation_id) alone cannot help: the agent filter here
 * matches virtually every conversation the owner has, so it narrows nothing.
 *
 * (role, created_at) lets those four queries range-scan straight to the
 * assistant rows in the requested window instead of reading the table once
 * per widget. Verified on a scratch MySQL 8.4 database (never the real
 * one — see CLAUDE.md): the worst query's rows-examined dropped from 42,080
 * to 2,239 and its time from ~22ms to ~4ms; end-to-end /dashboard HTTP time
 * dropped from a ~265ms average to a ~240ms average over five runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['role', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['role', 'created_at']);
        });
    }
};
