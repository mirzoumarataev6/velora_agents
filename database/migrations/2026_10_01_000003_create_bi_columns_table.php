<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bi_dataset_id')->constrained()->cascadeOnDelete();

            // Position in the source file, 0-based. What "column 3" means to the
            // owner, and the order the schema editor lists them in.
            $table->unsignedSmallInteger('ordinal');

            // THE THREE NAMES A COLUMN HAS, and why it needs all three:
            //
            //  - `source_name` is what the file's header row said ("Дата заказа").
            //    Shown to the owner, used by the AI's schema context, never in SQL.
            //  - `physical_name` is OURS ("c7"). It is the only one that reaches a
            //    CREATE TABLE or a SELECT, which is what makes an injected column
            //    name impossible rather than merely unlikely: there is no path
            //    from the file's bytes to an identifier.
            //  - `label` is the owner's override, shown in charts when set.
            $table->string('source_name', 512);
            $table->string('physical_name', 16);
            $table->string('label')->nullable();

            // text | integer | decimal | date | datetime | boolean.
            // `type` is what the table was built with; `inferred_type` is what
            // inference originally proposed. Keeping both means the schema editor
            // can show "you changed this" and offer to put it back.
            $table->string('type', 20);
            $table->string('inferred_type', 20);
            // dimension | measure | time. Derived from the type and the distinct
            // count, overridable: a year stored as an integer is a dimension, and
            // only the owner knows that.
            $table->string('role', 20);

            // Type-specific hints the caster needs, notably the date pattern a
            // text date column was recognized with. Stored rather than re-guessed
            // so materialization and re-materialization cannot disagree.
            $table->json('format')->nullable();

            // What inference actually saw. Drives the dimension decision, the
            // schema editor's preview, and the sampled values the AI is shown so
            // it can tell a region column from a product column.
            $table->unsignedBigInteger('distinct_count')->nullable();
            $table->boolean('distinct_exact')->default(true);
            $table->unsignedBigInteger('blank_count')->default(0);
            $table->unsignedInteger('max_length')->nullable();
            $table->json('sample_values')->nullable();
            $table->string('min_value', 512)->nullable();
            $table->string('max_value', 512)->nullable();

            // Values that would not cast to `type` during materialization and
            // were stored as NULL. Non-zero is not an error — it is the honest
            // report that this column had 12 rows saying "уточнить" — but it is
            // shown, because a silently 8%-empty column is how a dashboard lies.
            $table->unsignedBigInteger('cast_failures')->default(0);
            $table->json('cast_failure_samples')->nullable();

            // Whether this column is materialized with an index. Not every
            // column: 150 indexes would make the insert pass crawl for indexes
            // no GROUP BY will ever use.
            $table->boolean('indexed')->default(false);

            // The owner's own note about what this column means. Fed to the AI
            // analyst's schema context, and the single most effective fix when
            // it keeps picking `sum_base` over `sum_with_vat`.
            $table->string('description', 512)->nullable();

            $table->timestamps();

            // The schema editor and every spec validation read a dataset's whole
            // column list in ordinal order.
            $table->index(['bi_dataset_id', 'ordinal']);
            // A spec names a physical column; validation looks it up by this
            // pair and nothing else, so the lookup itself cannot cross datasets.
            $table->unique(['bi_dataset_id', 'physical_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_columns');
    }
};
