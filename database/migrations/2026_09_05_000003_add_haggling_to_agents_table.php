<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agent price negotiation.
 *
 * Three columns, and the split between them is the whole safety design:
 * `haggling_level` controls STYLE only — how flexible the assistant sounds and
 * how long it holds out — while `haggle_min_price_percent` is the only thing
 * that decides money. Below the floor the assistant never agrees; it escalates
 * to the owner. Turning the level up buys the customer a better conversation,
 * never a better price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Off by default: an owner who has not set a floor has not told us
            // what a sale is worth to them, and guessing that is not ours to do.
            $table->boolean('haggling_enabled')->default(false);
            $table->string('haggling_level', 16)->default('moderate');
            // The floor as a percentage of the LISTED price, so one setting
            // covers a whole catalog. 100 = no discount without approval.
            $table->unsignedTinyInteger('haggle_min_price_percent')->default(100);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['haggling_enabled', 'haggling_level', 'haggle_min_price_percent']);
        });
    }
};
