<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateUsersToTeamsCommand extends Command
{
    protected $signature = 'team:migrate-users {--dry-run} {--chunk=200}';

    protected $description = 'Backfill a personal team for every user who does not have one yet, top up rows created since, and denormalize team_id onto them.';

    private const DIRECT_TABLES = [
        'usage_logs', 'deals', 'bi_sources', 'bi_datasets', 'bi_columns',
        'bi_dashboards', 'bi_widgets', 'bi_connections', 'bi_chat_messages',
        'tasks', 'customers', 'crm_workspaces', 'crm_stages', 'crm_notes',
        'customer_merges', 'deal_activities',
    ];

    private const AGENT_SCOPED_TABLES = [
        'knowledge_sources', 'channels', 'knowledge_chunks', 'conversations',
        'leads', 'datasets', 'dataset_products', 'database_connections', 'product_images',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');

        if ($dryRun) {
            $pendingCreate = User::whereNull('current_team_id')->count();
            $pendingTopUp = User::whereNotNull('current_team_id')->count();
            $this->info("[dry-run] {$pendingCreate} user(s) have no team yet; {$pendingTopUp} user(s) already have a team. No changes will be written.");
        }

        [$createdUsers, $createdRows] = $this->createPersonalTeams($dryRun, $chunk);
        [$toppedUpUsers, $toppedUpRows] = $this->topUpExistingTeams($dryRun, $chunk);

        if ($dryRun) {
            $this->info("[dry-run] would create {$createdUsers} team(s) backfilling {$createdRows} row(s); would top up {$toppedUpUsers} existing team(s) filling {$toppedUpRows} row(s). Nothing was written.");

            return self::SUCCESS;
        }

        if ($createdUsers === 0 && $toppedUpUsers === 0) {
            $this->info('Every user already has a team, and nothing needs to be filled.');

            return self::SUCCESS;
        }

        if ($createdUsers > 0) {
            $this->info("Migrated {$createdUsers} user(s) to new personal teams ({$createdRows} row(s) backfilled).");
        }

        if ($toppedUpUsers > 0) {
            $this->info("Topped up {$toppedUpUsers} user(s) who already had a team ({$toppedUpRows} row(s) filled).");
        }

        return self::SUCCESS;
    }

    private function createPersonalTeams(bool $dryRun, int $chunk): array
    {
        $createdUsers = 0;
        $createdRows = 0;

        User::whereNull('current_team_id')->orderBy('id')->chunkById($chunk, function ($users) use ($dryRun, &$createdUsers, &$createdRows) {
            foreach ($users as $user) {
                $owned = $this->countMissingRows($user);

                if ($dryRun) {
                    $this->line("  would create a personal team for user #{$user->id} ({$user->email}) and backfill {$owned} row(s)");
                    $createdUsers++;
                    $createdRows += $owned;

                    continue;
                }

                DB::transaction(function () use ($user) {
                    $team = app(CreatesPersonalTeam::class)->create($user);
                    $this->fillMissingRows($user, $team);
                    $user->forceFill(['current_team_id' => $team->id])->save();
                });

                $createdUsers++;
                $createdRows += $owned;
            }
        });

        return [$createdUsers, $createdRows];
    }

    private function topUpExistingTeams(bool $dryRun, int $chunk): array
    {
        $toppedUpUsers = 0;
        $toppedUpRows = 0;

        User::whereNotNull('current_team_id')->orderBy('id')->chunkById($chunk, function ($users) use ($dryRun, &$toppedUpUsers, &$toppedUpRows) {
            foreach ($users as $user) {
                $missing = $this->countMissingRows($user);

                if ($missing === 0) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("  would top up user #{$user->id} ({$user->email}): fill {$missing} row(s) with their existing team #{$user->current_team_id}");
                    $toppedUpUsers++;
                    $toppedUpRows += $missing;

                    continue;
                }

                $team = Team::find($user->current_team_id);

                if ($team === null) {

                    continue;
                }

                DB::transaction(function () use ($user, $team) {
                    $this->fillMissingRows($user, $team);
                });

                $toppedUpUsers++;
                $toppedUpRows += $missing;
            }
        });

        return [$toppedUpUsers, $toppedUpRows];
    }

    private function countMissingRows(User $user): int
    {
        $count = DB::table('agents')->where('user_id', $user->id)->whereNull('team_id')->count();

        foreach (self::DIRECT_TABLES as $table) {
            $count += DB::table($table)->where('user_id', $user->id)->whereNull('team_id')->count();
        }

        foreach (self::AGENT_SCOPED_TABLES as $table) {
            $count += DB::table($table)
                ->join('agents', 'agents.id', '=', "{$table}.agent_id")
                ->where('agents.user_id', $user->id)
                ->whereNull("{$table}.team_id")
                ->count();
        }

        return $count;
    }

    private function fillMissingRows(User $user, Team $team): void
    {
        DB::table('agents')->where('user_id', $user->id)->whereNull('team_id')->update(['team_id' => $team->id]);

        foreach (self::DIRECT_TABLES as $table) {
            DB::table($table)->where('user_id', $user->id)->whereNull('team_id')->update(['team_id' => $team->id]);
        }

        foreach (self::AGENT_SCOPED_TABLES as $table) {
            DB::table($table)
                ->whereIn('agent_id', function ($query) use ($user) {
                    $query->select('id')->from('agents')->where('user_id', $user->id);
                })
                ->whereNull('team_id')
                ->update(['team_id' => $team->id]);
        }
    }
}
