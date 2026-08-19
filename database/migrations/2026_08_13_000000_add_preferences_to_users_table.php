<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null means "follow the operating system", which is what the boot
            // script in the dashboard layout already did before the choice was
            // storable — so existing users keep the behaviour they have today.
            $table->string('theme')->nullable()->after('locale');

            // Opt-out, not opt-in: an owner who linked a Telegram chat for lead
            // alerts has already asked for them.
            $table->boolean('lead_notifications_enabled')->default(true)->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['theme', 'lead_notifications_enabled']);
        });
    }
};
