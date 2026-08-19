<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Whether a long reply is delivered the way a person types in a
            // messenger — split into 2-3 consecutive messages with a typing
            // pause between them — instead of one wall of text. Short replies
            // are never split. See config('agent.humanize') for the tuning.
            $table->boolean('humanize_replies')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('humanize_replies');
        });
    }
};
