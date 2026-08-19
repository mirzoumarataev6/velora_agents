<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // How forward-leaning the assistant is about suggesting a next step:
            //   off    — answer only what's asked, no upsell
            //   subtle — one light, relevant suggestion when it genuinely helps (default)
            //   active — actively guide toward an order/booking, still one suggestion max
            $table->enum('proactivity', ['off', 'subtle', 'active'])->default('subtle');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('proactivity');
        });
    }
};
