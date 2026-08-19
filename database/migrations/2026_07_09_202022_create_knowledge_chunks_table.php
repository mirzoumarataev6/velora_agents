<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->longText('content');
            $table->json('embedding')->nullable();
            $table->unsignedInteger('token_count')->default(0);
            $table->unsignedInteger('chunk_index')->default(0);
            $table->timestamps();

            $table->index('agent_id');
            $table->index('knowledge_source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
