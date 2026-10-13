<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMerge;
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
 * `unique(team_id, phone_key)` (Stage 2d-3-1) now — the legacy
 * `unique(user_id, phone_key)` it used to sit alongside was dropped in
 * Stage 2d-5a — so the scenario this stage can actually prove is the one
 * Stage 3 brings: two DIFFERENT users' agents sharing one team. Simulated
 * here with `Agent::withoutTeamStamping()`, since nothing before Stage 3
 * lets two real user accounts share a team through the UI yet.
 *
 * The scenario the legacy index used to forbid — one OWNER's own two
 * teams both capturing the same number — is covered by
 * `test_one_owners_second_team_can_now_capture_a_number_its_first_team_already_holds()`
 * below, which used to pin that limitation as TEMPORARY until Stage 2d-5a
 * dropped the index; see that migration and the Stage 2d-5a report.
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
     * Stage 2d-5a dropped the legacy unique(user_id, phone_key) index that
     * used to block this: one owner's own SECOND team capturing a number
     * its FIRST team already holds. This test used to pin the OLD, blocked
     * behaviour (a swallowed insert failure, customer_id left null) as a
     * TEMPORARY limitation; it now asserts the fixed one — team B gets its
     * own customer, independent of team A's.
     */
    public function test_one_owners_second_team_can_now_capture_a_number_its_first_team_already_holds(): void
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
        $customerA = Customer::sole();

        $teamB = Team::factory()->create();
        $owner->forceFill(['current_team_id' => $teamB->id])->save();
        $agentB = Agent::factory()->for($owner)->create();
        $this->assertSame($teamB->id, $agentB->team_id);
        $channelB = Channel::factory()->for($agentB)->whatsapp()->create();

        $conversation = Conversation::factory()->for($agentB)->for($channelB)->create([
            'external_user_id' => '992900112233',
        ]);

        $this->assertSame(2, Customer::count(), 'Team B should now get its own customer for this number, independent of team A\'s.');
        $customerB = $conversation->fresh()->customer;
        $this->assertNotNull($customerB);
        $this->assertNotSame($customerA->id, $customerB->id);
        $this->assertSame($teamB->id, $customerB->team_id);
        $this->assertSame($teamA->id, $customerA->team_id);
    }

    /**
     * Stage 2d-5a: CustomerResolver::undoMerge()'s "has anybody since
     * claimed this number" check now matches by the restored profile's
     * OWN team, not its owner's `user_id` — exactly because the previous
     * test above proves a SECOND team of the same owner can legitimately
     * hold the same phone_key now. Before this fix, restoring a profile
     * in team A would have wrongly seen team B's unrelated customer
     * holding that same number and refused to give team A's profile its
     * phone_key back, even though nothing in team A actually holds it.
     */
    public function test_undo_merge_reclaims_the_phone_key_even_when_a_different_team_holds_the_same_one(): void
    {
        $owner = User::factory()->create();
        $teamA = $owner->current_team_id;

        // $keep has no number of its own, so the merge adopts $absorb's —
        // leaving it free for undo to give back. Same pre-condition
        // CustomerMergeUndoTest's own base case uses for the same reason.
        $keep = Customer::withoutTeamStamping(fn () => Customer::forceCreate([
            'user_id' => $owner->id, 'team_id' => $teamA, 'name' => 'Keep', 'status' => 'lead',
        ]));
        $absorb = Customer::withoutTeamStamping(fn () => Customer::forceCreate([
            'user_id' => $owner->id, 'team_id' => $teamA, 'name' => 'Absorb', 'phone' => '+992900112233', 'phone_key' => '900112233', 'status' => 'lead',
        ]));

        // A DIFFERENT team of the SAME owner, legitimately holding the
        // exact same number — allowed since Stage 2d-5a.
        $teamB = Team::factory()->create()->id;
        Customer::withoutTeamStamping(fn () => Customer::forceCreate([
            'user_id' => $owner->id, 'team_id' => $teamB, 'name' => 'Unrelated (team B)', 'phone' => '+992900112233', 'phone_key' => '900112233', 'status' => 'lead',
        ]));

        $mergedInto = app(CustomerResolver::class)->merge(from: $absorb, into: $keep);
        $this->assertSame('900112233', $mergedInto->phone_key, 'merge() itself should have adopted the number.');

        // The owner has since given $keep a DIFFERENT number (an ordinary
        // edit, unrelated to this merge) — team A's slot for 900112233 is
        // genuinely free again. Without this, $keep itself would still be
        // the one legitimate holder in team A, and undo correctly refusing
        // to also hand it to $absorb would not be this bug at all.
        $mergedInto->forceFill(['phone' => '+992922222222', 'phone_key' => '922222222'])->save();

        $mergeRecord = CustomerMerge::where('into_customer_id', $keep->id)->sole();

        $restored = app(CustomerResolver::class)->undoMerge($mergeRecord);

        $this->assertNotNull($restored);
        $this->assertSame(
            '900112233',
            $restored->fresh()->phone_key,
            'undoMerge() should have reclaimed the number within team A — team B holding the same number must not block it.'
        );
    }
}
