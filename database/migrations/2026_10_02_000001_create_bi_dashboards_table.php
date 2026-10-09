<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_dashboards', function (Blueprint $table) {
            $table->id();
            // Owner-level, like every other `bi_*` table and for the same
            // reason: a dashboard belongs to the business, not to an agent.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            /*
             * DASHBOARD-LEVEL FILTERS, stored as the same untrusted array shape
             * a widget's own filters use and validated by the same SpecValidator
             * at read time rather than at write time.
             *
             * Validated late on purpose. A filter references a `bi_columns` row,
             * and those rows are REPLACED when a dataset is rematerialized — so
             * a filter that was valid when it was saved can stop resolving
             * later, through no fault of the person who saved it. Validating on
             * write would make the save succeed and the dashboard break
             * silently; validating on read lets the page show which filter no
             * longer applies and keep rendering the rest.
             *
             * Keyed BY DATASET ID, because one dashboard can show widgets over
             * several datasets and "region = Душанбе" means nothing to a
             * dataset with no region column. See FilterMerger.
             */
            $table->json('filters')->nullable();

            // The owner's landing dashboard. Exactly one per account, enforced
            // in BiDashboard::makeDefault() rather than by a unique index: a
            // partial unique index on (user_id) WHERE is_default is not portable
            // to SQLite, which the test suite runs on.
            $table->boolean('is_default')->default(false);

            // Ordering in the dashboard switcher, so an owner can put the one
            // they open daily first without renaming it.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_dashboards');
    }
};
