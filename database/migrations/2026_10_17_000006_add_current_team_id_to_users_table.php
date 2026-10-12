<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Navigation state only — which team's resources this user sees
            // by default. Never an authorization input: Policies (from Stage
            // 2 onward) derive the relevant team from the model being
            // authorized, not from whichever team happens to be selected.
            $table->foreignId('current_team_id')->nullable()->after('id')->constrained('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_team_id');
        });
    }
};
