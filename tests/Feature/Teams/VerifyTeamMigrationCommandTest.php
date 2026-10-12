<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerifyTeamMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * createQuietly() rather than create(): this test wants the pre-Stage-2a
     * "no team yet" state, which a normal create() no longer produces now
     * that UserObserver gives every new account a personal team on the spot.
     */
    public function test_it_fails_while_rows_are_missing_a_team_id(): void
    {
        $user = User::factory()->createQuietly();
        Agent::factory()->for($user)->create();

        $this->artisan('team:verify-migration')
            ->expectsOutputToContain('agents: 1 row(s) with no team_id')
            ->assertExitCode(1);
    }

    public function test_it_passes_once_the_backfill_has_run(): void
    {
        $user = User::factory()->createQuietly();
        Agent::factory()->for($user)->create();

        $this->artisan('team:migrate-users')->assertExitCode(0);

        $this->artisan('team:verify-migration')
            ->expectsOutputToContain('Verification passed')
            ->assertExitCode(0);
    }

    public function test_it_passes_trivially_when_there_is_no_data_at_all(): void
    {
        $this->artisan('team:verify-migration')
            ->expectsOutputToContain('Verification passed')
            ->assertExitCode(0);
    }

    /**
     * team_id carries no foreign key constraint yet (see the migration that
     * adds it, deliberately, for the SQLite table-rebuild ordering reason),
     * so a row pointing at a team that no longer exists would otherwise
     * pass silently. This is the check that catches it instead.
     */
    public function test_it_fails_on_a_team_id_that_does_not_exist_in_teams(): void
    {
        $user = User::factory()->createQuietly();
        $agent = Agent::factory()->for($user)->create();

        $this->artisan('team:migrate-users')->assertExitCode(0);

        DB::table('agents')->where('id', $agent->id)->update(['team_id' => 999999]);

        $this->artisan('team:verify-migration')
            ->expectsOutputToContain('agents: 1 row(s) with a team_id that does not exist in teams')
            ->assertExitCode(1);
    }

    public function test_it_passes_again_once_the_orphaned_team_id_is_fixed(): void
    {
        $user = User::factory()->createQuietly();
        $agent = Agent::factory()->for($user)->create();

        $this->artisan('team:migrate-users')->assertExitCode(0);
        $realTeamId = $agent->refresh()->team_id;

        DB::table('agents')->where('id', $agent->id)->update(['team_id' => 999999]);
        $this->artisan('team:verify-migration')->assertExitCode(1);

        DB::table('agents')->where('id', $agent->id)->update(['team_id' => $realTeamId]);

        $this->artisan('team:verify-migration')
            ->expectsOutputToContain('Verification passed')
            ->assertExitCode(0);
    }
}
