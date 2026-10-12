<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('role_id')->constrained();
            $table->foreignId('invited_by')->constrained('users');
            $table->string('token')->unique();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            // (team_id, email) uniqueness while pending is enforced in
            // application code, not here — MySQL 8 has no partial/filtered
            // unique index, and a resent invitation after expiry is a new
            // row on purpose.
            $table->index(['team_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
