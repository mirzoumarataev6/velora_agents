<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // When the conversation was escalated to a human, and why. Separate
            // from `status` on purpose: status says who is answering right now
            // (and the owner can hand back to the agent), while these say the
            // system detected something the owner should look at. Keeping them
            // apart is what lets the bell show "escalations you haven't read"
            // without being confused by a manual takeover.
            $table->timestamp('needs_human_at')->nullable()->after('operator_read_at');
            // Which trigger fired: frustration | explicit_request | fallback.
            $table->string('needs_human_signal')->nullable()->after('needs_human_at');
            // One line, in the customer's own language, for the owner's alert.
            $table->string('needs_human_summary', 500)->nullable()->after('needs_human_signal');

            // The bell polls "escalated and not yet read" on every tick.
            $table->index('needs_human_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['needs_human_at']);
            $table->dropColumn(['needs_human_at', 'needs_human_signal', 'needs_human_summary']);
        });
    }
};
