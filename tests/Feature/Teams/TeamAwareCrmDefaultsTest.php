<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 2d-1: CrmWorkspace::defaultFor() and CrmStage::initialFor() /
 * defaultsFor() / wonFor() / lostFor() / intermediatesFor() grow an
 * optional `Team|int|null $team` parameter, additive only — `null` stays
 * byte-for-byte today's single-team behaviour. The background write paths
 * that used to resolve these through `$customer->user`/`$lead->agent?->user_id`
 * (and so, indirectly, through `current_team_id`) now pass the team
 * already in scope instead, the same pattern Stage 2c established for
 * `team_id` stamping itself.
 *
 * Users are built with `createQuietly()`, same reasoning as every other
 * Stage 2 team test: a normal `create()` gives every user an unrelated
 * personal team via `UserObserver`, which would be a second, untested team
 * sitting alongside the one each test deliberately builds.
 */
class TeamAwareCrmDefaultsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team, 2: Team}
     */
    private function makeOwnerWithASecondTeam(): array
    {
        $owner = User::factory()->createQuietly(['name' => 'Owner Olga']);
        $teamA = app(CreatesPersonalTeam::class)->create($owner);
        $owner->forceFill(['current_team_id' => $teamA->id])->save();

        $teamB = Team::factory()->create(['name' => 'Team B']);

        return [$owner, $teamA, $teamB];
    }

    private function switchTo(User $owner, Team $team): void
    {
        $owner->forceFill(['current_team_id' => $team->id])->save();
    }

    // --- CrmWorkspace::defaultFor() ---------------------------------------

    public function test_default_for_with_a_null_team_behaves_exactly_like_today(): void
    {
        [$owner, $teamA] = $this->makeOwnerWithASecondTeam();

        $workspace = CrmWorkspace::defaultFor($owner);

        $this->assertSame('__main__', $workspace->name);
        $this->assertSame($teamA->id, $workspace->team_id);
        $this->assertSame(1, CrmWorkspace::where('user_id', $owner->id)->count());

        // Idempotent, exactly as the plain firstOrCreate always was.
        $again = CrmWorkspace::defaultFor($owner);
        $this->assertSame($workspace->id, $again->id);
        $this->assertSame(1, CrmWorkspace::where('user_id', $owner->id)->count());
    }

    public function test_a_legacy_main_workspace_with_null_team_id_is_adopted_not_duplicated(): void
    {
        [$owner, $teamA] = $this->makeOwnerWithASecondTeam();

        // A workspace from before team_id existed on this table at all —
        // built with withoutTeamStamping() so it stays legacy (team_id
        // NULL) despite the owner already having a current team.
        $legacy = CrmWorkspace::withoutTeamStamping(function () use ($owner) {
            $workspace = new CrmWorkspace;
            $workspace->forceFill(['user_id' => $owner->id, 'name' => '__main__', 'is_default' => true])->save();

            return $workspace;
        });
        $this->assertNull($legacy->team_id);

        $workspace = CrmWorkspace::defaultFor($owner, $teamA);

        $this->assertSame($legacy->id, $workspace->id, 'A second row was created instead of adopting the legacy one.');
        $this->assertSame($teamA->id, $workspace->fresh()->team_id);
        $this->assertSame(1, CrmWorkspace::where('user_id', $owner->id)->count());
    }

    public function test_a_main_workspace_owned_by_another_team_is_never_adopted(): void
    {
        [$owner, $teamA, $teamB] = $this->makeOwnerWithASecondTeam();

        // Already correctly stamped with team A — the ordinary, expected
        // shape for a single-team account.
        $workspaceA = CrmWorkspace::defaultFor($owner, $teamA);
        $this->assertSame($teamA->id, $workspaceA->team_id);

        // crm_workspaces still carries unique(user_id, name) — not yet
        // unique(user_id, team_id, name) (Stage 2d-5a) — so there is no
        // second __main__ slot for team B to be given. Asking for one must
        // never silently hand back team A's row relabelled; what actually
        // happens today is the hard constraint itself refusing the insert.
        $this->expectException(UniqueConstraintViolationException::class);

        CrmWorkspace::defaultFor($owner, $teamB);
    }

    // --- CrmStage::defaultsFor() et al. -----------------------------------

    public function test_defaults_for_with_a_null_team_behaves_exactly_like_today(): void
    {
        [$owner, $teamA] = $this->makeOwnerWithASecondTeam();

        $stages = CrmStage::defaultsFor($owner);

        $this->assertCount(4, $stages);
        $this->assertTrue($stages->every(fn (CrmStage $stage) => $stage->team_id === $teamA->id));
        $this->assertSame($teamA->id, CrmStage::initialFor($owner)->team_id);

        // Idempotent, exactly as the plain ownedBy()->ordered()->get() always was.
        $this->assertCount(4, CrmStage::defaultsFor($owner));
        $this->assertSame(4, CrmStage::where('user_id', $owner->id)->count());
    }

    public function test_default_stages_for_a_second_team_of_the_same_user_do_not_trip_the_role_uniqueness_check(): void
    {
        [$owner, $teamA, $teamB] = $this->makeOwnerWithASecondTeam();

        // Team A's stages: seeded through the null-team path, exactly like
        // every single-team account today — team_id still ends up team A
        // via StampsTeam's own current_team_id resolution.
        $stagesA = CrmStage::defaultsFor($owner);
        $this->assertSame($teamA->id, $stagesA->firstWhere('role', 'initial')->team_id);

        // Team B's stages: seeded explicitly for team B. Before this
        // change, CrmStage::booted()'s role-uniqueness check was scoped by
        // user_id alone, so creating a second 'initial' role for the same
        // user_id — even under a different team — would throw
        // crm.stages.role_taken. It must not, here.
        $stagesB = CrmStage::defaultsFor($owner, $teamB);

        $this->assertCount(4, $stagesB);
        $this->assertTrue($stagesB->every(fn (CrmStage $stage) => $stage->team_id === $teamB->id));

        $initialA = $stagesA->firstWhere('role', 'initial');
        $initialB = $stagesB->firstWhere('role', 'initial');
        $this->assertNotSame($initialA->id, $initialB->id);

        $this->assertSame(8, CrmStage::where('user_id', $owner->id)->count());
    }
}
