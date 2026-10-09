<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dataset_products', function (Blueprint $table) {
            $table->string('image_url', 1024)->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('dataset_products', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
