<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_user_id')->nullable();
            $table->string('external_user_name')->nullable();
            $table->enum('status', ['ai', 'human_takeover', 'closed'])->default('ai');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index('agent_id');
            $table->index(['channel_id', 'external_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
