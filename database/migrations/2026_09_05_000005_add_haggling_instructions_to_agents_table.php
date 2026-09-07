<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's own words about how to negotiate.
 *
 * Free text, because the two structured settings beside it cannot express what
 * a shop actually knows: which products never move on price, what to offer
 * instead of a discount, the phrase the owner wants used, who gets a better
 * deal. It is guidance for the CONVERSATION only — it sits alongside the
 * level's style block in the prompt and is explicitly subordinate to the floor,
 * which is enforced in code rather than by instruction. See HagglingService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->text('haggling_instructions')->nullable()->after('haggle_min_price_percent');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('haggling_instructions');
        });
    }
};
