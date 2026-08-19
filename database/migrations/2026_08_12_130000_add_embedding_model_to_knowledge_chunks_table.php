<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which embedding model produced each chunk's vector.
 *
 * The vector store already refused to compare embeddings of differing length,
 * but two models can share a length and still be completely incomparable —
 * text-embedding-3-small and -3-large both emit 1536 dimensions at the default
 * setting. Without this column, switching models produces confident-looking
 * cosine scores computed across unrelated vector spaces, which is worse than
 * retrieving nothing. With it, the store can skip stale vectors and tell the
 * operator to reindex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->string('embedding_model')->nullable()->after('embedding');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->dropColumn('embedding_model');
        });
    }
};
