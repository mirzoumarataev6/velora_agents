<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair dashboards created before the analysis-link migration reached an
     * already-running MySQL database.
     *
     * `2026_10_04_000001` is the canonical schema for new installations. This
     * guard exists for databases whose migration history says that release was
     * deployed while the two columns are absent (for example after a partial
     * restore). New code cannot safely create an automatic analysis without
     * recording its source and main dataset.
     */
    public function up(): void
    {
        if (! Schema::hasTable('bi_dashboards')) {
            return;
        }

        if (! Schema::hasColumn('bi_dashboards', 'bi_source_id')) {
            Schema::table('bi_dashboards', function (Blueprint $table) {
                $table->foreignId('bi_source_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('bi_dashboards', 'bi_dataset_id')) {
            Schema::table('bi_dashboards', function (Blueprint $table) {
                $table->foreignId('bi_dataset_id')->nullable()->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // This is a recovery migration. Reverting it must not erase links
        // created by the canonical migration or by an owner since the repair.
    }
};
