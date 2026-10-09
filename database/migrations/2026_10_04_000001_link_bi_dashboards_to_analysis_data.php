<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bi_dashboards', function (Blueprint $table) {
            // Keep a generated analysis tied to its source even if its first
            // widget is removed. Null on deletion leaves the owner's notes and
            // layout available instead of silently deleting their analysis.
            $table->foreignId('bi_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bi_dataset_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bi_dashboards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bi_dataset_id');
            $table->dropConstrainedForeignId('bi_source_id');
        });
    }
};
