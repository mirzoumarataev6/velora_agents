<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin dashboard's own small preview, generated at upload time and
 * stored alongside the original — see ProductImageThumbnailer and
 * ProductImage::previewUrl(). Nullable: a row from before this column
 * existed simply has none, and previewUrl() falls back to the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('thumbnail_path', 512)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('thumbnail_path');
        });
    }
};
