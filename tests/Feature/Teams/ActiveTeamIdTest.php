<?php

namespace Tests\Feature\Teams;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 2d-4-1: `User::activeTeamId()` — `current_team_id`, but only once
 * confirmed to still be a team this user is actually a member of. The six
 * CRM `scopeOwnedBy()`s (tested model-by-model in TeamScopedCrmTest) all
 * read this instead of `user_id`, so a wrong answer here would silently
 * leak or hide an entire account's CRM book.
 *
 * `createQuietly()` throughout, same reasoning as every other Stage 2 team
 * test: a normal `create()` gives the user an unrelated personal team via
 * UserObserver, which would be a second, untested team sitting alongside
 * the one each test deliberately builds.
 */
class ActiveTeamIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_to_current_team_id_when_the_user_is_a_member(): void
    {
        $owner = User::factory()->createQuietly();
        $team = app(CreatesPersonalTeam::class)->create($owner);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $this->assertSame($team->id, $owner->activeTeamId());
    }

    public function test_null_when_current_team_id_is_null(): void
    {
        $owner = User::factory()->createQuietly(['current_team_id' => null]);

        $this->assertNull($owner->activeTeamId());
    }

    /**
     * The exact leak this method exists to stop: a `current_team_id` that
     * still points at a real team row, but one this user was removed from
     * (or never belonged to) must not resolve to it.
     */
    public function test_null_when_current_team_id_points_at_a_team_the_user_is_not_a_member_of(): void
    {
        $owner = User::factory()->createQuietly();
        $otherTeam = Team::factory()->create();
        $owner->forceFill(['current_team_id' => $otherTeam->id])->save();

        $this->assertNull($owner->activeTeamId());
    }

    /**
     * membershipIn() is memoized per User instance (HasTeamsTest already
     * pins this for the trait itself) — this just confirms activeTeamId()
     * rides that same cache rather than issuing its own separate query
     * every time it's asked, which every scopeOwnedBy() call in a request
     * does.
     */
    public function test_repeated_calls_do_not_issue_additional_membership_queries(): void
    {
        $owner = User::factory()->createQuietly();
        $team = app(CreatesPersonalTeam::class)->create($owner);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $owner->activeTeamId();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $owner->activeTeamId();
        $owner->activeTeamId();

        $this->assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }
}
