<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // How the customer actually sent this turn. The content column still
            // holds plain text either way — a voice note is stored as its
            // transcript — so nothing downstream has to special-case reading it.
            // This exists so the inbox can say "they spoke this, they didn't type it".
            $table->string('source_type', 16)->default('text')->after('content');

            // Seconds, for display next to the transcript. Nullable because only
            // voice turns have one.
            $table->unsignedSmallInteger('voice_duration')->nullable()->after('source_type');
        });

        Schema::table('agents', function (Blueprint $table) {
            // Owners who would rather not pay for transcription, or who want
            // customers to type, can switch it off. Default on: a voice note
            // that goes unanswered looks like a broken bot.
            $table->boolean('transcribe_voice')->default(true)->after('humanize_replies');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'voice_duration']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('transcribe_voice');
        });
    }
};
