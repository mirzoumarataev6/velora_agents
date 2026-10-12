<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyTeamMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_while_rows_are_missing_a_team_id(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $this->artisan('team:verify-migration')
            ->expectsOutputToContain('agents: 1 row(s) with no team_id')
            ->assertExitCode(1);
    }

    public function test_it_passes_once_the_backfill_has_run(): void
    {
        $user = User::factory()->create();
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
}
