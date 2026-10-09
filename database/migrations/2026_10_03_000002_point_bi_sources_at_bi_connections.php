<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move connection-backed sources onto the BI module's own connections, and
     * remove the foreign key that pointed into the agent's table.
     *
     * No data migration accompanies this. A `kind=connection` source holds no
     * rows of its own — it is a pointer at someone else's live tables — and the
     * credentials it used belong to an agent, which is precisely the coupling
     * being removed. Carrying those rows across would mean copying an agent's
     * password into a BI row on the owner's behalf, which is not a decision a
     * migration gets to make. Any such source is dropped with the column.
     */
    public function up(): void
    {
        Schema::table('bi_sources', function (Blueprint $table) {
            /*
             * `restrictOnDelete`, NOT cascade, and this is load-bearing.
             *
             * A database-level cascade does not fire Eloquent observers, and in
             * this module the observer chain — BiSourceObserver →
             * BiDatasetObserver → DropDatasetTableJob — is the only thing that
             * drops the runtime `bi_ds_*` tables and the NDJSON shards. A cascade
             * here would delete the rows and leave the physical tables behind,
             * orphaned and invisible to every migration.
             *
             * So the database REFUSES the delete, and BiConnectionController
             * deletes the sources through Eloquent first. The constraint is the
             * backstop that makes forgetting to do that a loud error rather than
             * a silent leak.
             */
            $table->foreignId('bi_connection_id')->nullable()->after('database_connection_id')
                ->constrained('bi_connections')->restrictOnDelete();
        });

        // Dropped in its own closure: SQLite rebuilds the table for each schema
        // change, and adding a column while dropping a foreign key in one pass
        // is where that rebuild goes wrong.
        Schema::table('bi_sources', function (Blueprint $table) {
            $table->dropForeign(['database_connection_id']);
            $table->dropColumn('database_connection_id');
        });
    }

    public function down(): void
    {
        Schema::table('bi_sources', function (Blueprint $table) {
            $table->foreignId('database_connection_id')->nullable()
                ->constrained()->nullOnDelete();
        });

        Schema::table('bi_sources', function (Blueprint $table) {
            $table->dropForeign(['bi_connection_id']);
            $table->dropColumn('bi_connection_id');
        });
    }
};
