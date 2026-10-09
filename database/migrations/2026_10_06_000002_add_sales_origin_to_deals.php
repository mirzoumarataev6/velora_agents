<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('deals', 'sales_origin')) {
            Schema::table('deals', function (Blueprint $table) {
                // A sale's origin is independent of the messaging channel: a
                // customer can negotiate on WhatsApp and still buy in-store.
                $table->string('sales_origin', 20)->default('social');
                $table->index(['user_id', 'sales_origin', 'deal_date']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('deals', 'sales_origin')) {
            Schema::table('deals', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'sales_origin', 'deal_date']);
                $table->dropColumn('sales_origin');
            });
        }
    }
};
