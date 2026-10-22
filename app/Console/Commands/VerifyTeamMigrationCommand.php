<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyTeamMigrationCommand extends Command
{
    protected $signature = 'team:verify-migration';

    protected $description = 'Verify every tenant row and every user has a team_id / current_team_id before flipping the columns to NOT NULL.';

    private const TABLES = [
        'agents',
        'usage_logs', 'deals', 'bi_sources', 'bi_datasets', 'bi_columns',
        'bi_dashboards', 'bi_widgets', 'bi_connections', 'bi_chat_messages',
        'tasks', 'customers', 'crm_workspaces', 'crm_stages', 'crm_notes',
        'customer_merges', 'deal_activities',
        'knowledge_sources', 'channels', 'knowledge_chunks', 'conversations',
        'leads', 'datasets', 'dataset_products', 'database_connections', 'product_images',
    ];

    public function handle(): int
    {
        $clean = true;

        foreach (self::TABLES as $table) {
            $missing = DB::table($table)->whereNull('team_id')->count();
            $orphaned = DB::table($table)
                ->whereNotNull('team_id')
                ->whereNotIn('team_id', DB::table('teams')->select('id'))
                ->count();

            if ($missing > 0) {
                $clean = false;
                $this->error("{$table}: {$missing} row(s) with no team_id");
            }

            if ($orphaned > 0) {
                $clean = false;
                $this->error("{$table}: {$orphaned} row(s) with a team_id that does not exist in teams");
            }

            if ($missing === 0 && $orphaned === 0) {
                $this->line("{$table}: ok");
            }
        }

        $usersMissing = User::whereNull('current_team_id')->count();

        if ($usersMissing > 0) {
            $clean = false;
            $this->error("users: {$usersMissing} user(s) with no current_team_id");
        } else {
            $this->line('users: ok');
        }

        if ($clean) {
            $this->info('Verification passed: safe to flip team_id to NOT NULL.');
        } else {
            $this->warn('Verification failed: do not flip team_id to NOT NULL yet.');
        }

        return $clean ? self::SUCCESS : self::FAILURE;
    }
}
