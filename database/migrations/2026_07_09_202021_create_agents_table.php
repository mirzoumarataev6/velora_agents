<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('avatar')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'paused'])->default('active');
            $table->string('language')->default('auto');
            $table->enum('tone', ['friendly', 'professional', 'casual', 'formal'])->default('friendly');
            $table->json('response_format')->nullable();
            $table->text('custom_system_prompt')->nullable();
            $table->json('behavior')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
