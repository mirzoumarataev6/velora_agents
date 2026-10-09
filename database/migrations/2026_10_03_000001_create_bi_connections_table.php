<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_connections', function (Blueprint $table) {
            $table->id();

            /*
             * OWNED BY THE USER, NOT BY AN AGENT — and that is the entire reason
             * this table exists rather than the BI module borrowing a row from
             * `database_connections`.
             *
             * That table is structurally agent-scoped: its `agent_id` is UNIQUE,
             * so it holds at most one connection per agent and has no shape for a
             * connection that belongs to nobody in particular. Its policy reaches
             * ownership only by traversing `$connection->agent->user_id`. Reusing
             * it meant a BI data source hung off a row whose lifetime belonged to
             * an agent — so deleting an agent cascaded into its connection and
             * silently left a BI source pointing at nothing.
             *
             * A BI connection is the business's own, for analysis, and survives
             * every agent the account ever creates or deletes.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * NOT unique on user_id, unlike the agent table.
             *
             * An agent has one product database because it answers one shop's
             * questions. An owner analysing their business may well want the
             * warehouse and the till on the same board, so several are allowed —
             * which is also why this table has a `name`: with one connection per
             * agent, "the connection" needed no label.
             */
            $table->string('name');

            $table->string('engine', 20);
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('database');
            $table->string('username');
            // Laravel's `encrypted` cast — ciphertext is longer than the secret,
            // so text rather than string. Never rendered back to the browser; the
            // form shows a masked placeholder and an empty field means "keep it".
            $table->text('password')->nullable();
            $table->boolean('ssl')->default(false);

            // unverified → ready | error. Only `ready` is offered as something to
            // register tables from: a connection that has never dialled would
            // produce datasets that fail on first query.
            $table->string('status', 20)->default('unverified');
            $table->string('last_error', 64)->nullable();
            $table->timestamp('last_tested_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_connections');
    }
};
