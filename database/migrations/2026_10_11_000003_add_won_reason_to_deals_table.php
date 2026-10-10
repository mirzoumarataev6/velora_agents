<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The won counterpart to cancelled_reason — optional, unlike its lost sibling,
 * since a sale needs no excuse. Set when a deal is closed (DealBoard::setClosed()),
 * mirroring exactly where cancelled_reason is set (DealBoard::move()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->text('won_reason')->nullable()->after('cancelled_reason');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('won_reason');
        });
    }
};
