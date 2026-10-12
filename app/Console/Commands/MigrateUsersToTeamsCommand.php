<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off, idempotent backfill: every user who doesn't yet have a team gets
 * a personal Team (as Owner), and every row they own gets that team's id
 * denormalized onto it. Intended to run out-of-band on a populated
 * database — never inline in `php artisan migrate` — per the Phase-1 plan's
 * data migration strategy. Safe to re-run: a user with `current_team_id`
 * already set is skipped entirely.
 */
class MigrateUsersToTeamsCommand extends Command
{
    protected $signature = 'team:migrate-users {--dry-run} {--chunk=200}';

    protected $description = 'Backfill a personal team for every user who does not have one yet, and denormalize team_id onto their rows.';

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

        $pending = User::whereNull('current_team_id')->count();

        if ($pending === 0) {
            $this->info('Every user already has a team. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? "[dry-run] {$pending} user(s) have no team yet. No changes will be written."
            : "Migrating {$pending} user(s) to personal teams.");

        $migrated = 0;

        User::whereNull('current_team_id')->orderBy('id')->chunkById($chunk, function ($users) use ($dryRun, &$migrated) {
            foreach ($users as $user) {
                if ($dryRun) {
                    $owned = $this->countOwnedRows($user);
                    $this->line("  would create a personal team for user #{$user->id} ({$user->email}) and backfill {$owned} row(s)");
                    $migrated++;

                    continue;
                }

                DB::transaction(function () use ($user) {
                    $team = app(CreatesPersonalTeam::class)->create($user);
                    $this->backfillTeamId($user, $team);
                    $user->forceFill(['current_team_id' => $team->id])->save();
                });

                $migrated++;
            }
        });

        $this->info($dryRun
            ? "[dry-run] would migrate {$migrated} user(s). Nothing was written."
            : "Migrated {$migrated} user(s).");

        return self::SUCCESS;
    }

    private function countOwnedRows(User $user): int
    {
        $count = DB::table('agents')->where('user_id', $user->id)->count();

        foreach (self::DIRECT_TABLES as $table) {
            $count += DB::table($table)->where('user_id', $user->id)->count();
        }

        foreach (self::AGENT_SCOPED_TABLES as $table) {
            $count += DB::table($table)
                ->join('agents', 'agents.id', '=', "{$table}.agent_id")
                ->where('agents.user_id', $user->id)
                ->count();
        }

        return $count;
    }

    private function backfillTeamId(User $user, Team $team): void
    {
        DB::table('agents')->where('user_id', $user->id)->update(['team_id' => $team->id]);

        foreach (self::DIRECT_TABLES as $table) {
            DB::table($table)->where('user_id', $user->id)->update(['team_id' => $team->id]);
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
                ->update(['team_id' => $team->id]);
        }
    }
}
