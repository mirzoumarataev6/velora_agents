<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price-approval request is a lead, not a new species.
 *
 * It arrives the same way (the model calls a tool mid-conversation), it is
 * owned by the same agent, it points at the same conversation, it rides the
 * same notification fan-out and the same unread bell, and the owner works it in
 * the same list. Giving it its own table would have duplicated all of that and
 * split the owner's inbox in two.
 *
 * So `type` says which kind of row this is, `status` gains one state for the
 * one thing an order never needs — waiting on the owner — and the three numbers
 * the decision actually turns on get real columns rather than being buried in
 * the `details` blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('type', 20)->default('order')->after('conversation_id');
            $table->decimal('requested_price', 12, 2)->nullable()->after('items');
            $table->decimal('listed_price', 12, 2)->nullable()->after('requested_price');
            // What the owner actually granted. Distinct from requested_price:
            // approving at a counter-offer is a normal outcome.
            $table->decimal('approved_price', 12, 2)->nullable()->after('listed_price');
            $table->text('negotiation_summary')->nullable()->after('approved_price');

            $table->index(['agent_id', 'type']);
        });

        // Widened from enum('new','in_progress','done') to make room for
        // 'awaiting_price_approval'. A string rather than a bigger enum: the
        // next state to be added should not need a table rebuild on MySQL.
        Schema::table('leads', function (Blueprint $table) {
            $table->string('status', 32)->default('new')->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['agent_id', 'type']);
            $table->dropColumn(['type', 'requested_price', 'listed_price', 'approved_price', 'negotiation_summary']);
        });
    }
};
