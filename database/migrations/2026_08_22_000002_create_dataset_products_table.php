<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_products', function (Blueprint $table) {
            $table->id();
            // agent_id is denormalized alongside dataset_id on purpose: every
            // search_products query filters by it, so the tenant scope is a
            // column on the row being read rather than a join the caller could
            // forget to write.
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 12, 2)->nullable();
            $table->integer('stock')->nullable();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->string('url', 1024)->nullable();
            // Lowercased name + description + sku, and lowercased category.
            //
            // Case-insensitive matching cannot be left to the database: MySQL's
            // utf8mb4_unicode_ci LIKE folds Cyrillic case, SQLite's LIKE folds
            // only ASCII, and LOWER() splits the same way. A customer typing
            // "куртка" would then find "Куртка" in production and not in tests.
            // Normalizing once at import makes both behave identically — and
            // makes the query one LIKE instead of three.
            $table->text('search_text')->nullable();
            $table->string('category_key')->nullable();
            // Columns the owner mapped as "extra": kept verbatim under their own
            // header names so nothing useful in the file is thrown away, without
            // needing a schema change per shop.
            $table->json('extra')->nullable();
            // Preserves file order, so a preview and an unsorted search both read
            // back the way the owner's spreadsheet looked.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // The three filters search_products actually applies, each led by the
            // tenant scope so a query can never plan its way past it.
            $table->index(['agent_id', 'price']);
            $table->index(['agent_id', 'category_key']);
            $table->index(['agent_id', 'stock']);
            $table->index(['agent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_products');
    }
};
