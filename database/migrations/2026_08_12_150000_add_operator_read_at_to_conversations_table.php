<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // When the owner last read this conversation in the inbox. A
            // conversation counts as unread when a customer message arrived
            // after this moment — which is why it can't be derived from
            // last_message_at alone: the agent's own replies move that too.
            $table->timestamp('operator_read_at')->nullable()->after('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('operator_read_at');
        });
    }
};
