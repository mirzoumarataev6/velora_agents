<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's own photos for a catalog product — the trusted source the
 * agent's photo pipeline now checks before anything else (the catalog's own
 * `image_url` column, a knowledge-base filename match, or Wikimedia).
 *
 * `agent_id` is denormalized alongside `dataset_product_id`, the same
 * reasoning `dataset_products.agent_id` itself documents: every query here
 * is already scoped by the owning agent, so that scope belongs on the row
 * being read, not reached through a join the caller could forget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_product_id')->constrained()->cascadeOnDelete();
            // Public-disk relative path (see config('agent.product_images.disk'))
            // — never the public URL itself, which Storage::url() derives.
            $table->string('path', 512);
            $table->string('mime_type', 32);
            $table->unsignedInteger('size');
            // Ordering within the product: the smallest position is primary —
            // what the agent sends first and what a future single-photo
            // preview would show.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['dataset_product_id', 'position']);
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
