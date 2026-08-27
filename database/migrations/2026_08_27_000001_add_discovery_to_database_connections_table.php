<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic schema discovery: what was found, what it composes, and how sure we
 * were.
 *
 * Nothing here replaces the existing columns. `table_name`, `schema_columns` and
 * `mapping` keep their exact meaning and are still what a single-table search
 * validates against — the manual flow is untouched and remains the fallback. The
 * columns below only ever ADD to that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_connections', function (Blueprint $table) {
            // The WHOLE schema as discovery read it — every table, its columns,
            // the declared relationships and the unique keys. This is the closed
            // set a joined query validates its identifiers against, so it has to
            // be the snapshot the owner confirmed rather than a fresh read of a
            // schema that may have changed since.
            $table->json('schema_snapshot')->nullable();

            // The confirmed join plan: which tables are brought alongside, on
            // which columns, and what each contributes. Written only by the
            // confirm endpoint, from relationships found in the snapshot above.
            $table->json('joins')->nullable();

            // What the owner was shown when they confirmed: the ranked
            // candidates and the reasons for each. Kept so a later "why is the
            // agent reading THAT table" can be answered.
            $table->json('discovery')->nullable();

            // 'high' | 'medium' | 'low', and 'automatic' | 'manual'. Stored flat
            // rather than dug out of the JSON because the tab renders them on
            // every paint.
            $table->string('discovery_confidence', 10)->nullable();
            $table->string('discovery_method', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('database_connections', function (Blueprint $table) {
            $table->dropColumn([
                'schema_snapshot',
                'joins',
                'discovery',
                'discovery_confidence',
                'discovery_method',
            ]);
        });
    }
};
