<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 2c: the four background write paths that used to resolve their
 * `team_id` through `users.current_team_id` now take it from the
 * originating object already in scope instead. Every test here proves the
 * difference matters by deliberately pointing the owner's
 * `current_team_id` at a SECOND team before triggering the real code
 * path — if the fix were reverted, each assertion here would see the
 * second team's id instead of the first team's, which is exactly the bug
 * the switcher would otherwise introduce.
 *
 * Users are built with `createQuietly()`, same reasoning as every other
 * Stage 2 team test: a normal `create()` gives every user an unrelated
 * personal team via `UserObserver`, which would be a second, untested team
 * sitting alongside the one each test deliberately builds.
 */
class ExplicitTeamStampingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An owner whose real team (A) owns everything created in the test, and
     * a second team (B) that only exists to be the wrong answer. Returns
     * before switching: the caller creates whatever team-A-owned fixtures
     * it needs first, then calls `switchTo($owner, $teamB)` to simulate the
     * switcher state "looking at team B" right before triggering the
     * background path under test.
     *
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

    public function test_deal_activity_from_an_automatically_opened_deal_takes_the_deals_team_not_current_team_id(): void
    {
        [$owner, $teamA, $teamB] = $this->makeOwnerWithASecondTeam();

        // Built while current_team_id still correctly points at team A.
        $agent = Agent::factory()->for($owner)->create();
        $this->assertSame($teamA->id, $agent->team_id);
        $channel = Channel::factory()->for($agent)->create();

        // The switch: current_team_id now points at team B, the way the
        // Stage 3 switcher would leave it — while the agent above still
        // belongs to team A.
        $this->switchTo($owner, $teamB);

        // A customer's first message on this thread: ConversationObserver
        // resolves a customer and DealStageAutomation::conversationStarted()
        // opens the first Deal, which DealObserver records as a
        // DealActivity — the real background chain, not a shortcut.
        $conversation = Conversation::factory()->for($agent)->create(['channel_id' => $channel->id]);

        $deal = DB::table('deals')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($deal, 'DealStageAutomation did not open a deal for this conversation.');
        $this->assertSame($teamA->id, $deal->team_id, 'The deal itself should already resolve via agent_id, not current_team_id.');

        $activity = DB::table('deal_activities')->where('deal_id', $deal->id)->where('type', 'created')->first();
        $this->assertNotNull($activity);

        $this->assertSame($teamA->id, $activity->team_id);
        $this->assertNotSame($teamB->id, $activity->team_id);
    }
}
