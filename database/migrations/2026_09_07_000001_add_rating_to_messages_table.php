<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's verdict on one reply.
 *
 * A column rather than another key in `meta`, for two reasons: it is written
 * by a different actor at a different time than everything else in that blob
 * (the customer, minutes later, from a webhook), and the dashboard groups and
 * counts by it — which a JSON extract can do but not with an index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // 'thumbs_up' | 'thumbs_down'. NULL is the honest majority: most
            // replies are never rated, and that is not the same as neutral.
            $table->string('rating', 16)->nullable()->after('meta');

            // When the customer voted, as opposed to when the reply was sent.
            // The gap between the two is the only evidence of whether a vote
            // was a reflex or a considered one.
            $table->timestamp('rated_at')->nullable()->after('rating');

            // The dashboard's rating widgets all filter on "rated at all", and
            // rated rows are a small minority of a large table.
            $table->index(['rating']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['rating']);
            $table->dropColumn(['rating', 'rated_at']);
        });
    }
};
