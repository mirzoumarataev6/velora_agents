<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deal is what the business is trying to close, and it is deliberately not
 * the same row as a lead.
 *
 * A lead is a RECORD of something a customer said — it happened, it does not
 * move. A deal is a POSITION in a pipeline: it slides from stage to stage, it
 * can be won or lost, and one customer can have several over the years while
 * every lead they ever left stays exactly where it was. Reusing `leads` for
 * this would have meant a table that is both an immutable record and a mutable
 * workflow, and the first person to "clean up" old leads would silently delete
 * the pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The deal is FOR somebody. Cascade: a deleted customer profile has
            // no pipeline left to speak of.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Where the conversation about it is happening, when there is one.
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            // The order that opened it, so the board and Заявки stay one fact
            // rather than two copies of it.
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            // No currency column, matching the catalog and Lead::formatPrice:
            // the shop's currency is not modelled, and inventing one would be
            // wrong for most of these businesses.
            $table->string('payment_status', 16)->default('unpaid');

            $table->string('stage', 32)->default('new_lead');
            // Order WITHIN a column, so a drag that only reorders is preserved.
            $table->integer('position')->default(0);
            // Drives "time in current stage" on the card — the number that
            // makes a stalled deal visible.
            $table->timestamp('stage_changed_at')->nullable();
            /**
             * When the OWNER last moved this card by hand.
             *
             * The automation is forward-only and must never override a human
             * decision made after the signal fired: an owner who drags a deal
             * back to Переписка has just said the order is not real yet, and a
             * webhook arriving a second later must not overrule them.
             */
            $table->timestamp('stage_set_by_owner_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'stage', 'position']);
            $table->index('customer_id');
            // How the automation finds the deal a signal belongs to.
            $table->index(['conversation_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
