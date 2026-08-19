<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL treats NULL as distinct in unique indexes (NULL is never equal to
     * another NULL), so these only enforce uniqueness for rows that actually
     * have a channel_id / external_message_id — playground and operator rows
     * (which use NULL) are unaffected. This is what makes duplicate webhook
     * deliveries (or a job retried after a queue-worker timeout) a safe no-op
     * at the database level instead of producing a second reply.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unique(['channel_id', 'external_user_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unique(['conversation_id', 'external_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['channel_id', 'external_user_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['conversation_id', 'external_message_id']);
        });
    }
};
