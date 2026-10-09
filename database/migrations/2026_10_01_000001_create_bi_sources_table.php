<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_sources', function (Blueprint $table) {
            $table->id();
            // The OWNER, not an agent. A spreadsheet of last quarter's sales is
            // not the property of whichever assistant happens to exist — it is
            // the business's, like `customers` and `deals`. This column is the
            // whole tenant boundary for the BI module.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'file' — an upload sitting on the local disk, normalized once into
            // NDJSON shards. 'connection' — a live read-only external database,
            // which stores no rows here and borrows the existing driver layer.
            $table->string('kind', 20);
            $table->string('name');

            // Populated for kind=file only.
            $table->string('original_filename')->nullable();
            $table->string('disk_path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('extension', 12)->nullable();

            // Populated for kind=connection only. Reuses the connection the
            // owner already set up for an agent rather than asking for the same
            // credentials twice — and inherits its encryption, SSRF guard and
            // read-only session setup unchanged.
            $table->foreignId('database_connection_id')->nullable()
                ->constrained()->nullOnDelete();

            // pending → normalizing → ready | failed. Only 'ready' makes the
            // datasets under this source queryable; anything else and the UI
            // shows what is happening or what went wrong.
            $table->string('status', 20)->default('pending');
            // A CODE, not a sentence: the user-facing text is a translation key
            // resolved at render time, so the same failure reads correctly in
            // ru, tg and en. `error_detail` carries the technical remainder for
            // the owner to paste into a support message, never a stack trace.
            $table->string('error_code', 40)->nullable();
            $table->text('error_detail')->nullable();

            $table->timestamp('normalized_at')->nullable();
            $table->timestamps();

            // The source list, newest first, scoped to the owner.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_sources');
    }
};
