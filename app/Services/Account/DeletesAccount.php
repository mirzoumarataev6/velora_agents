<?php

namespace App\Services\Account;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Invitation;
use App\Models\Team;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeletesAccount
{
    public const ATTRIBUTED_TEAM_TABLES = [
        'agents', 'usage_logs', 'deals', 'bi_sources', 'bi_datasets',
        'bi_columns', 'bi_dashboards', 'bi_widgets', 'bi_connections',
        'bi_chat_messages', 'deal_activities', 'tasks', 'customers', 'crm_workspaces',
        'crm_stages', 'crm_notes', 'customer_merges',
    ];

    public const HANDLED_RESTRICT_FKS = [
        [
            'table' => 'bi_sources',
            'columns' => ['bi_connection_id'],
            'foreign_table' => 'bi_connections',
            'resolution' => 'resolved elsewhere: UserObserver::deleting() already deletes every BiSource (not SoftDeletes) before $user->delete()\'s own DELETE statement',
        ],

        [
            'table' => 'deals',
            'columns' => ['crm_stage_id', 'team_id'],
            'foreign_table' => 'crm_stages',
            'resolution' => 'explicit: Deal::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'deals',
            'columns' => ['crm_workspace_id', 'team_id'],
            'foreign_table' => 'crm_workspaces',
            'resolution' => 'explicit: Deal::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'customers',
            'columns' => ['crm_workspace_id', 'team_id'],
            'foreign_table' => 'crm_workspaces',
            'resolution' => 'explicit: Customer::forceDelete() before $user->delete()',
        ],
    ];

    public const KNOWN_DIAMOND_FKS = [

        [
            'table' => 'deals',
            'columns' => ['crm_stage_id', 'team_id'],
            'foreign_table' => 'crm_stages',
            'resolution' => 'see HANDLED_RESTRICT_FKS: explicit, Deal::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'deals',
            'columns' => ['crm_workspace_id', 'team_id'],
            'foreign_table' => 'crm_workspaces',
            'resolution' => 'see HANDLED_RESTRICT_FKS: explicit, Deal::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'customers',
            'columns' => ['crm_workspace_id', 'team_id'],
            'foreign_table' => 'crm_workspaces',
            'resolution' => 'see HANDLED_RESTRICT_FKS: explicit, Customer::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'bi_sources',
            'columns' => ['bi_connection_id'],
            'foreign_table' => 'bi_connections',
            'resolution' => 'see HANDLED_RESTRICT_FKS: resolved elsewhere, UserObserver deletes every BiSource standalone first',
        ],

        [
            'table' => 'deals',
            'columns' => ['agent_id'],
            'foreign_table' => 'agents',
            'resolution' => 'resolved elsewhere: Deal::forceDelete() runs before $user->delete(); the row is gone before agents cascade-deletes',
        ],
        [
            'table' => 'deals',
            'columns' => ['conversation_id'],
            'foreign_table' => 'conversations',
            'resolution' => 'resolved elsewhere: Deal::forceDelete() runs before $user->delete(); the row is gone before conversations cascade-deletes',
        ],
        [
            'table' => 'deals',
            'columns' => ['lead_id'],
            'foreign_table' => 'leads',
            'resolution' => 'resolved elsewhere: Deal::forceDelete() runs before $user->delete(); the row is gone before leads cascade-deletes',
        ],
        [
            'table' => 'customer_merges',
            'columns' => ['from_customer_id'],
            'foreign_table' => 'customers',
            'resolution' => 'resolved elsewhere: Customer::forceDelete() runs as its own standalone statement before $user->delete(); the SET NULL it triggers fires while users still exists',
        ],
        [
            'table' => 'customer_merges',
            'columns' => ['into_customer_id'],
            'foreign_table' => 'customers',
            'resolution' => 'resolved elsewhere: Customer::forceDelete() runs as its own standalone statement before $user->delete(); the SET NULL it triggers fires while users still exists',
        ],
        [
            'table' => 'bi_dashboards',
            'columns' => ['bi_dataset_id'],
            'foreign_table' => 'bi_datasets',
            'resolution' => 'resolved elsewhere: BiDatasetObserver deletes the dataset as its own standalone statement (via UserObserver -> BiSourceObserver) before $user->delete()',
        ],
        [
            'table' => 'bi_dashboards',
            'columns' => ['bi_source_id'],
            'foreign_table' => 'bi_sources',
            'resolution' => 'resolved elsewhere: UserObserver deletes every BiSource as its own standalone statement before $user->delete()',
        ],

        [
            'table' => 'usage_logs',
            'columns' => ['agent_id'],
            'foreign_table' => 'agents',
            'resolution' => 'explicit: UsageLog::where(user_id)->delete() before $user->delete()',
        ],
    ];

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {

            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($this->hasExplicitTeamDependants($user)) {
                throw new SharedTeamAccountDeletionException;
            }

            $personalTeamIds = Team::where('created_by', $user->id)
                ->where('is_personal', true)->pluck('id');

            Deal::where('user_id', $user->id)->forceDelete();
            Customer::where('user_id', $user->id)->forceDelete();

            UsageLog::where('user_id', $user->id)->delete();

            $user->delete();

            Team::whereIn('id', $personalTeamIds)->delete();
        });
    }

    private function hasExplicitTeamDependants(User $user): bool
    {

        if (Team::where('created_by', $user->id)->where('is_personal', false)->exists()
            || Invitation::where('invited_by', $user->id)->exists()) {
            return true;
        }

        if (DB::table('project_boards')->join('teams', 'teams.id', '=', 'project_boards.team_id')
            ->where('project_boards.created_by', $user->id)
            ->where('teams.is_personal', false)->exists()) {
            return true;
        }

        foreach (self::ATTRIBUTED_TEAM_TABLES as $table) {
            if (DB::table($table)
                ->join('teams', 'teams.id', '=', $table.'.team_id')
                ->where($table.'.user_id', $user->id)
                ->where('teams.is_personal', false)
                ->exists()) {
                return true;
            }
        }

        return false;
    }
}
