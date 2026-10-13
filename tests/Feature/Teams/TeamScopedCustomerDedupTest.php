<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use App\Services\Crm\CustomerResolver;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 2d-4-3: CustomerResolver's phone-based dedup — findByPhone(),
 * open()'s race fallback, claimPhone() — now matches by the AGENT'S OWN
 * TEAM instead of the conversation owner's `user_id`. `customers` carries
 * `unique(team_id, phone_key)` (Stage 2d-3-1) now, alongside — not instead
 * of — the legacy `unique(user_id, phone_key)` (that one is not dropped
 * until Stage 2d-5), so the scenario this stage can actually prove is the
 * one Stage 3 brings: two DIFFERENT users' agents sharing one team.
 * Simulated here with `Agent::withoutTeamStamping()`, since nothing before
 * Stage 3 lets two real user accounts share a team through the UI yet.
 *
 * The scenario the legacy index still forbids — one OWNER's own two teams
 * both capturing the same number — is deliberately NOT tested here; it
 * stays broken (a swallowed `UniqueConstraintViolationException`, logged
 * by ConversationObserver's own catch-all rather than thrown) until Stage
 * 2d-5 drops that index. See the Stage 2d-4 report for this called out
 * explicitly.
 */
class TeamScopedCustomerDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_users_agent_on_the_same_team_resolves_the_same_phone_to_the_existing_customer(): void
    {
        $owner = User::factory()->createQuietly();
        $team = app(CreatesPersonalTeam::class)->create($owner);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $agent = Agent::factory()->for($owner)->create();
        $this->assertSame($team->id, $agent->team_id);
        $channel = Channel::factory()->for($agent)->whatsapp()->create();

        Conversation::factory()->for($agent)->for($channel)->create([
            'external_user_id' => '992900112233',
            'external_user_name' => 'Азиз',
        ]);

        $this->assertSame(1, Customer::count());
        $existing = Customer::sole();

        // A second, unrelated owner's agent — forced onto the SAME team,
        // simulating the teammate Stage 3 will let two real accounts share.
        // Given its own team first (satisfying agents.team_id NOT NULL via
        // the ordinary StampsTeam resolution), then moved onto the shared
        // team afterward.
        $teammate = User::factory()->createQuietly();
        $teammateOwnTeam = app(CreatesPersonalTeam::class)->create($teammate);
        $teammate->forceFill(['current_team_id' => $teammateOwnTeam->id])->save();
        $teammateAgent = Agent::factory()->for($teammate)->create();
        $teammateAgent->forceFill(['team_id' => $team->id])->save();
        $teammateChannel = Channel::factory()->for($teammateAgent)->whatsapp()->create();

        $secondConversation = Conversation::factory()->for($teammateAgent)->for($teammateChannel)->create([
            'external_user_id' => '992900112233',
            'external_user_name' => 'Азиз (second thread)',
        ]);

        $this->assertSame(1, Customer::count(), 'A second, team-mate-owned thread with the same number opened a duplicate customer instead of reusing the team-mate\'s.');
        $this->assertSame($existing->id, Customer::sole()->id);
        $this->assertSame($owner->id, Customer::sole()->user_id, 'The customer should still belong to the owner who was contacted first.');
        // The real proof the dedup itself (not just a lucky insert failure)
        // is what attached this thread: user_id-scoped findByPhone() would
        // never have matched the team-mate's own user_id against this
        // customer, so the only way this thread ends up linked at all is
        // the team-scoped lookup finding it.
        $this->assertSame($existing->id, $secondConversation->fresh()->customer_id, 'The team-mate\'s thread was never actually linked to the existing customer.');
    }

    public function test_the_same_team_deduping_two_channels_still_works(): void
    {
        $owner = User::factory()->createQuietly();
        $team = app(CreatesPersonalTeam::class)->create($owner);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $agent = Agent::factory()->for($owner)->create();
        $whatsapp = Channel::factory()->for($agent)->whatsapp()->create();
        $greenapi = Channel::factory()->for($agent)->create(['type' => 'greenapi']);

        Conversation::factory()->for($agent)->for($whatsapp)->create([
            'external_user_id' => '992900112233',
        ]);
        Conversation::factory()->for($agent)->for($greenapi)->create([
            'external_user_id' => '992900112233@c.us',
        ]);

        $key = app(CustomerResolver::class)->phoneKey('992900112233');
        $customers = Customer::where('team_id', $team->id)->where('phone_key', $key)->get();

        $this->assertCount(1, $customers, 'The same team\'s two channels with the same number were not deduped.');
    }

    /**
     * The scenario the legacy unique(user_id, phone_key) index still
     * forbids: one owner's own SECOND team capturing a number its FIRST
     * team already holds. Pinned here, not as a feature, so Stage 2d-5
     * (which drops that index) has a test that flips from red to green —
     * the same TEMPORARY convention TeamAwareCrmDefaultsTest already uses
     * for its own still-blocked case.
     */
    public function test_one_owners_second_team_cannot_yet_capture_a_number_its_first_team_already_holds(): void
    {
        $owner = User::factory()->createQuietly();
        $teamA = app(CreatesPersonalTeam::class)->create($owner);
        $owner->forceFill(['current_team_id' => $teamA->id])->save();
        $agentA = Agent::factory()->for($owner)->create();
        $channelA = Channel::factory()->for($agentA)->whatsapp()->create();

        Conversation::factory()->for($agentA)->for($channelA)->create([
            'external_user_id' => '992900112233',
        ]);
        $this->assertSame(1, Customer::count());

        $teamB = Team::factory()->create();
        $owner->forceFill(['current_team_id' => $teamB->id])->save();
        $agentB = Agent::factory()->for($owner)->create();
        $this->assertSame($teamB->id, $agentB->team_id);
        $channelB = Channel::factory()->for($agentB)->whatsapp()->create();

        // Swallowed by ConversationObserver's own catch-all (it must never
        // cost the business the message that triggered it) — so this does
        // not throw, it simply fails to file a customer for team B's thread.
        $conversation = Conversation::factory()->for($agentB)->for($channelB)->create([
            'external_user_id' => '992900112233',
        ]);

        $this->assertNull($conversation->fresh()->customer_id, 'Team B unexpectedly got its own customer — the legacy unique(user_id, phone_key) index may have been dropped; update this test for Stage 2d-5.');
        $this->assertSame(1, Customer::count());
    }
}
