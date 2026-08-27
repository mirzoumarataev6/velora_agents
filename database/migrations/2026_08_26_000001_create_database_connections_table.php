<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_connections', function (Blueprint $table) {
            $table->id();
            // Unique, like `datasets`: an agent has at most one live connection.
            // The tenant scope is this column and nothing else — every query the
            // agent runs is built from the row found by agent_id, so there is no
            // connection id anywhere for a request or a model to name.
            $table->foreignId('agent_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('engine', 20);
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('database');
            $table->string('username');
            // Laravel's `encrypted` cast — ciphertext is longer than the secret,
            // so text rather than string. Never rendered back to the browser.
            $table->text('password')->nullable();
            $table->boolean('ssl')->default(false);

            // The one table or view holding products, plus the columns discovered
            // on it. Both are written only by schema discovery, never straight
            // from a request: the identifiers that reach the SQL builder are
            // checked against this snapshot first.
            $table->string('table_name')->nullable();
            $table->string('table_type', 20)->nullable();
            $table->json('schema_columns')->nullable();
            $table->timestamp('discovered_at')->nullable();

            // field => column NAME (not index — a live table has no header row
            // whose order could be relied on).
            $table->json('mapping')->nullable();

            // What the last connection attempt did. `status` decides whether the
            // search tool is offered at all, so a connection that has never been
            // verified cannot put the tool in the prompt.
            $table->string('status', 20)->default('unverified');
            $table->string('last_error', 64)->nullable();
            $table->timestamp('last_tested_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_connections');
    }
};
