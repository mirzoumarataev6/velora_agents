<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Whether the agent offers the create_lead tool to the model. On by
            // default so existing agents start capturing orders immediately.
            $table->boolean('capture_leads')->default(true);

            // Owner's personal Telegram, linked so new leads ping them directly.
            // The chat id is where notifications are sent; the link code is a
            // short-lived nonce consumed by the /start deep-link handshake.
            $table->string('notify_telegram_chat_id')->nullable();
            $table->string('notify_telegram_username')->nullable();
            $table->string('notify_telegram_link_code')->nullable()->unique();
            $table->timestamp('notify_telegram_linked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'capture_leads',
                'notify_telegram_chat_id',
                'notify_telegram_username',
                'notify_telegram_link_code',
                'notify_telegram_linked_at',
            ]);
        });
    }
};
