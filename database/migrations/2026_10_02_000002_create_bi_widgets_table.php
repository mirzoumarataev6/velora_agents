<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_widgets', function (Blueprint $table) {
            $table->id();
            // Denormalized from the dashboard, exactly as `bi_datasets.user_id`
            // is denormalized from its source: every policy check reads this
            // row, so the tenant key is ON the row rather than a join away.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bi_dashboard_id')->constrained()->cascadeOnDelete();

            /*
             * The dataset this widget reads.
             *
             * `cascadeOnDelete` rather than `nullOnDelete`: a widget with no
             * dataset is a card that can never render again, and leaving it
             * behind would mean every dashboard accumulates tombstones from
             * deleted uploads. Deleting the dataset deletes the chart that read
             * it, which is what the owner means when they delete the dataset.
             */
            $table->foreignId('bi_dataset_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            // line | area | bar | column | donut | table | kpi — WidgetType.
            $table->string('type', 20);

            /*
             * THE QUERY, as the same untrusted array SpecValidator takes from a
             * request. Not a compiled QuerySpec and not SQL: storing either
             * would put a cached artifact of our own compiler in a row that
             * outlives the deploy that wrote it.
             *
             * Columns inside are referenced by PHYSICAL NAME (`c3`) rather than
             * by `bi_columns.id` wherever the editor can manage it, because a
             * rematerialization issues new rows with new ids but keeps the
             * ordinals — so a widget survives its dataset being re-imported.
             * SpecValidator accepts both spellings for exactly this reason.
             */
            $table->json('spec');

            // Widget-level filters, merged with the dashboard's. Same shape,
            // same late validation. See FilterMerger for which wins.
            $table->json('filters')->nullable();

            // Presentation that is not part of the question: value format,
            // whether to show the legend, an explicit series colour. Kept apart
            // from `spec` so that changing a colour does not change the cache
            // key — the numbers did not move.
            $table->json('options')->nullable();

            /*
             * THE GRID SLOT. Gridstack's four numbers, persisted verbatim.
             *
             * Stored as columns rather than inside a JSON blob because they are
             * the only part of a widget that is written on its own, by a drag
             * that touches every widget on the board at once. A bulk UPDATE of
             * four small ints per row is a different operation from rewriting a
             * JSON document, and the layout endpoint does nothing else.
             *
             * Unsigned smallints: the grid is 12 columns wide and nobody builds
             * a board 65,000 rows deep.
             */
            $table->unsignedSmallInteger('grid_x')->default(0);
            $table->unsignedSmallInteger('grid_y')->default(0);
            $table->unsignedSmallInteger('grid_w')->default(6);
            $table->unsignedSmallInteger('grid_h')->default(4);

            $table->timestamps();

            // The dashboard's own render reads every widget in grid order.
            $table->index(['bi_dashboard_id', 'grid_y', 'grid_x']);
            // "What breaks if I delete this dataset" — asked by the delete
            // confirmation, and by the observer chain.
            $table->index(['bi_dataset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_widgets');
    }
};
