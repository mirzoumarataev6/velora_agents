<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('is_personal')->default(false);
        });

        // Before an explicit team-creation flow existed, every unshared team
        // was an automatic personal namespace. Teams already joined by someone
        // else or holding invitations are kept visible as collaborative teams.
        $unshared = DB::table('teams')
            ->whereNotNull('created_by')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('team_user')
                    ->whereColumn('team_user.team_id', 'teams.id')
                    ->whereColumn('team_user.user_id', 'teams.created_by');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('team_user')
                    ->whereColumn('team_user.team_id', 'teams.id')
                    ->whereColumn('team_user.user_id', '!=', 'teams.created_by');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('invitations')
                    ->whereColumn('invitations.team_id', 'teams.id');
            });

        // Membership can have been removed after a teammate created rows.
        // Calling that team "personal" would hide their work and let the
        // creator's account deletion cascade it away, so inspect attribution
        // as well as the current member list.
        foreach ([
            'agents', 'usage_logs', 'deals', 'bi_sources', 'bi_datasets',
            'bi_columns', 'bi_dashboards', 'bi_widgets', 'bi_connections',
            'bi_chat_messages', 'deal_activities', 'tasks', 'customers',
            'crm_workspaces', 'crm_stages', 'crm_notes', 'customer_merges',
        ] as $table) {
            $unshared->whereNotExists(function ($query) use ($table) {
                $query->selectRaw('1')->from($table)
                    ->whereColumn($table.'.team_id', 'teams.id')
                    ->whereColumn($table.'.user_id', '!=', 'teams.created_by');
            });
        }

        $unshared->update(['is_personal' => true]);
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('is_personal');
        });
    }
};
