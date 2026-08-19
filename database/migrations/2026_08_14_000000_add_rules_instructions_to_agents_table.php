<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Situational rules the agent must follow ("if the customer asks X,
            // do Y"). Deliberately separate from custom_system_prompt, which is
            // persona — who the agent is and how it sounds. Two different jobs,
            // written differently by the owner, and injected as two distinct
            // sections of the system prompt.
            $table->text('rules_instructions')->nullable()->after('custom_system_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('rules_instructions');
        });
    }
};
