<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            // Unique: an agent has exactly one catalog. Re-uploading replaces it
            // rather than accumulating datasets the agent would have to choose
            // between at query time.
            $table->foreignId('agent_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('original_filename')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            // Rows the import deliberately dropped (no product name, unreadable),
            // kept as a count plus a per-reason breakdown so the owner is told
            // what happened instead of silently getting a short catalog.
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('skipped_reasons')->nullable();
            // field => column index, as confirmed by the owner. Kept after the
            // import so the next upload of the same export can reuse it.
            $table->json('mapping')->nullable();
            // Header row of the imported file, so the stored mapping can be shown
            // (and re-suggested) in terms of the owner's own column names.
            $table->json('columns')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};
