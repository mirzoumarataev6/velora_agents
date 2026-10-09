<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_datasets', function (Blueprint $table) {
            $table->id();
            // Denormalized from bi_sources on purpose, exactly as `agent_id` is
            // denormalized onto `dataset_products`: every scope check and every
            // quota count reads this row, so the tenant boundary is a column on
            // the row itself rather than a join a caller could forget to write.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bi_source_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // One workbook sheet becomes one dataset, because that is how real
            // files arrive: "Продажи", "Возвраты", "Справочник" in one .xlsx are
            // three tables, not one with three shapes.
            $table->string('sheet_name')->nullable();

            // The physical analytical table this dataset materialized into —
            // always `bi_ds_{id}`, derived from this row's own primary key and
            // never from anything a request supplied. Nullable because the row
            // exists from the moment its schema is inferred, before the table
            // has been built.
            $table->string('physical_table', 64)->nullable();
            // For kind=connection sources: the table or view on the customer's
            // server instead. Written by schema discovery only.
            $table->string('external_table')->nullable();

            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedSmallInteger('column_count')->default(0);

            // Bumped on every successful materialization. Query cache keys carry
            // it, which is what makes the cache correct without invalidation: a
            // re-import produces new keys rather than stale hits on old ones.
            $table->unsignedInteger('version')->default(0);

            // inferring → materializing → ready | failed.
            $table->string('status', 20)->default('inferring');
            $table->string('error_code', 40)->nullable();
            $table->text('error_detail')->nullable();

            // Rows the ingest deliberately dropped (entirely empty, or wider
            // than the header), as a count plus a per-reason breakdown — the
            // same bargain `datasets` strikes, so a short table is explained
            // rather than merely noticed.
            $table->unsignedBigInteger('skipped_rows')->default(0);
            $table->json('skipped_reasons')->nullable();
            // True when the file was longer than the row budget. The owner is
            // told; the dataset is still perfectly usable.
            $table->boolean('truncated')->default(false);

            // Where the normalized NDJSON shards for this dataset live, relative
            // to the local disk. Kept after materialization so a type
            // correction can rebuild the table without re-reading the original
            // spreadsheet — which, for .xlsx, is the expensive half of ingest.
            $table->string('shard_directory')->nullable();
            $table->unsignedInteger('shard_count')->default(0);

            $table->timestamp('materialized_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['bi_source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_datasets');
    }
};
