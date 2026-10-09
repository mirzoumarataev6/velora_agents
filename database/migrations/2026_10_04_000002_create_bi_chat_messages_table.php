<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_chat_messages', function (Blueprint $table) {
            $table->id();
            // Denormalized owner key: a chat transcript has the same tenant
            // boundary as the board and must never depend on a joining scope.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bi_dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12);
            $table->text('content');
            $table->json('payload')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'bi_dashboard_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_chat_messages');
    }
};
