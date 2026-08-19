<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'system', 'operator']);
            $table->longText('content');
            $table->string('external_message_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('external_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
