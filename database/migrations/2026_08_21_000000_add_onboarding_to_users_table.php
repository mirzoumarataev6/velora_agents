<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Stamped by the request that RENDERS the welcome modal, not by a
            // click on it — so closing the tab, reloading, or a dropped fetch
            // can never bring it back a second time.
            $table->timestamp('welcomed_at')->nullable()->after('lead_notifications_enabled');

            // The one onboarding step no existing table can answer. The other
            // three are read live off agents/knowledge_sources/channels, which
            // is why there is no column for them here.
            $table->timestamp('playground_tried_at')->nullable()->after('welcomed_at');

            // Skipped by hand. Separate from completed_at because "I don't want
            // this" and "I finished it" are different answers and only the
            // second one earns the congratulations panel.
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('playground_tried_at');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_dismissed_at');
        });

        // Everyone who already has an account has already been through setup in
        // whatever state it exists, so shipping this must not greet them as new
        // arrivals. Only rows that exist at migration time are backfilled;
        // anyone registering afterwards gets the real first-run experience.
        DB::table('users')->update([
            'welcomed_at' => now(),
            'onboarding_dismissed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'welcomed_at',
                'playground_tried_at',
                'onboarding_dismissed_at',
                'onboarding_completed_at',
            ]);
        });
    }
};
