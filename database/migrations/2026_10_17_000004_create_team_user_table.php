<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // A role can't be deleted while a member still holds it — force
            // reassignment first, rather than silently leaving a member with
            // no permissions.
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
    }
};
