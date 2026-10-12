<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // NULL = one of the 4 global system-role templates (seeded once,
            // in a later migration), never assigned to a team_user row
            // directly. NOT NULL = the real, per-team row every team gets its
            // own copy of at creation, so editing one team's Admin role can
            // never touch another team's.
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            // Exactly one true row per team (and one NULL-team template row
            // named 'owner'); immutable and undeletable — enforced in the
            // Stage-2 Policy, not by a DB constraint.
            $table->boolean('is_owner')->default(false);
            $table->timestamps();

            $table->unique(['team_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
