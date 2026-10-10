<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per merge, so it can be undone.
 *
 * Only the MOVED record ids are stored, not a snapshot of either profile.
 * `into`'s own fields need no snapshot because undo never touches them (see
 * CustomerResolver::undoMerge() for why reverting a field the merge filled in
 * is deliberately out of scope); `from`'s fields need none either because
 * soft-delete leaves the row itself untouched — restoring it is `restore()`,
 * not a rebuild from a copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // nullOnDelete, not cascade: the merge record (and the undo
            // window) should survive even if one of the two profiles is
            // later force-deleted for real.
            $table->foreignId('from_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('into_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Which rows actually moved, so undo moves back exactly those —
            // and nothing `into` already had of its own before the merge.
            $table->json('moved_conversation_ids')->nullable();
            $table->json('moved_lead_ids')->nullable();
            $table->json('moved_deal_ids')->nullable();
            $table->json('moved_task_ids')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['into_customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_merges');
    }
};
