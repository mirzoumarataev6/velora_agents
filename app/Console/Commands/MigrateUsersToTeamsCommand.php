<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent backfill, safe to run repeatedly on a populated database —
 * never inline in `php artisan migrate` — per the Phase-1 plan's data
 * migration strategy. Two passes, each guarded by `whereNull('team_id')` so
 * neither can ever overwrite a row that already carries a team:
 *
 * 1. A user with no team yet gets a personal Team (as Owner), and every row
 *    they own gets that team's id denormalized onto it.
 * 2. A user who already has a team gets topped up: until a later stage
 *    wires every write path to stamp `team_id` itself, a row created after
 *    a user's first migration (a new conversation, deal, task, ...) is
 *    still born with `team_id = NULL`. Skipping such users entirely — as
 *    an earlier version of this command did, by only ever looking at
 *    `current_team_id IS NULL` — was a dead end: `team:verify-migration`
 *    would keep finding NULLs that this command could never go back and
 *    fill, right before the NOT NULL flip. This pass closes that gap by
 *    re-scanning every already-migrated user for rows still missing a
 *    team_id and filling them with their existing team.
 */
class MigrateUsersToTeamsCommand extends Command
{
    protected $signature = 'team:migrate-users {--dry-run} {--chunk=200}';

    protected $description = 'Backfill a personal team for every user who does not have one yet, top up rows created since, and denormalize team_id onto them.';

    /** Tables scoped today by a direct user_id column. */
    private const DIRECT_TABLES = [
        'usage_logs', 'deals', 'bi_sources', 'bi_datasets', 'bi_columns',
        'bi_dashboards', 'bi_widgets', 'bi_connections', 'bi_chat_messages',
        'tasks', 'customers', 'crm_workspaces', 'crm_stages', 'crm_notes',
        'customer_merges', 'deal_activities',
    ];

    /** Tables scoped only by agent_id, backfilled by joining through agents.user_id. */
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

    /** @return array{0: int, 1: int} [users created, rows backfilled] */
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

    /** @return array{0: int, 1: int} [users topped up, rows filled] */
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
                    // An orphaned current_team_id — nothing sane to fill
                    // with. team:verify-migration surfaces this separately.
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

    /** Rows this user owns that do not yet carry ANY team_id — never rows that carry a different one. */
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

    /**
     * Fills `team_id` only where it is currently NULL — used both to
     * backfill a brand-new team and to top up an existing one, so neither
     * path can ever clobber a row that already carries a (possibly
     * different) team.
     */
    private function fillMissingRows(User $user, Team $team): void
    {
        DB::table('agents')->where('user_id', $user->id)->whereNull('team_id')->update(['team_id' => $team->id]);

        foreach (self::DIRECT_TABLES as $table) {
            DB::table($table)->where('user_id', $user->id)->whereNull('team_id')->update(['team_id' => $team->id]);
        }

        // Not a join()->update() on purpose: SQLite's query builder grammar
        // (which the test suite runs on, per tests/TestCase.php) does not
        // support joined updates the way MySQL does. A whereIn subquery
        // compiles identically on both.
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
