<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\Crm\CustomerResolver;
use App\Services\Haggling\HagglingService;
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

        // Stage 2d-2 made crm_workspaces.team_id NOT NULL — on SQLite that
        // is actually completed by group (c)'s migration, not group (b)'s
        // (which is a deliberate no-op on this driver; see its own
        // docblock), so that is the file relaxed here. This test exists
        // specifically to prove defaultFor() still adopts a row from
        // before that flip (and from before team_id existed on this table
        // at all) rather than duplicating it.
        (require database_path('migrations/2026_10_18_000003_make_team_id_not_null_on_customers_and_deals.php'))->down();

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

    // TEMPORARY: pins the unique(user_id,name) hard stop; replace in Stage 2d-5a when that index becomes team-scoped.
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

    // --- Background call sites --------------------------------------------

    public function test_conversation_started_files_the_deal_under_team_as_own_workspace_and_initial_stage(): void
    {
        [$owner, $teamA, $teamB] = $this->makeOwnerWithASecondTeam();

        $agent = Agent::factory()->for($owner)->create();
        $this->assertSame($teamA->id, $agent->team_id);
        $channel = Channel::factory()->for($agent)->create();

        $this->switchTo($owner, $teamB);

        // A customer's first message on this thread: the real background
        // chain (ConversationObserver -> CustomerResolver -> DealStageAutomation::conversationStarted()).
        $conversation = Conversation::factory()->for($agent)->create(['channel_id' => $channel->id]);

        $deal = Deal::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($deal, 'DealStageAutomation did not open a deal for this conversation.');

        $workspace = CrmWorkspace::find($deal->crm_workspace_id);
        $stage = CrmStage::find($deal->crm_stage_id);

        $this->assertSame($teamA->id, $workspace->team_id);
        $this->assertSame($teamA->id, $stage->team_id);
        $this->assertNotSame($teamB->id, $workspace->team_id);
        $this->assertNotSame($teamB->id, $stage->team_id);
    }

    public function test_negotiation_opened_advances_the_deal_into_team_as_own_intermediate_stage(): void
    {
        [$owner, $teamA, $teamB] = $this->makeOwnerWithASecondTeam();

        $agent = Agent::factory()->for($owner)->create();
        $channel = Channel::factory()->for($agent)->create();
        $conversation = Conversation::factory()->for($agent)->create(['channel_id' => $channel->id]);

        $deal = Deal::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($deal);
        $initialStageId = $deal->crm_stage_id;

        // Team B also has its own default pipeline (e.g. seeded for some
        // other agent of this same owner). Without the fix,
        // CrmStage::intermediatesFor($user) is user_id-scoped only, so it
        // would see BOTH teams' intermediate stages (2, not 1) and the
        // ">1 means ambiguous" guard would abort the signal entirely —
        // this is what makes the divergence observable, not just a
        // different (but still singular) answer.
        CrmStage::defaultsFor($owner, $teamB);

        $this->switchTo($owner, $teamB);

        // A haggle-type lead on the same thread: LeadObserver ->
        // DealStageAutomation::negotiationOpened() -> CrmStage::intermediatesFor().
        Lead::factory()->for($agent)->create([
            'conversation_id' => $conversation->id,
            'type' => HagglingService::TYPE,
            'phone' => null,
        ]);

        $stage = CrmStage::find($deal->fresh()->crm_stage_id);

        $this->assertNotSame($initialStageId, $stage->id, 'The deal was never advanced out of its initial stage.');
        $this->assertNull($stage->role, 'Expected the intermediate (role-less) stage.');
        $this->assertSame($teamA->id, $stage->team_id);
        $this->assertNotSame($teamB->id, $stage->team_id);
    }

    public function test_resolve_for_lead_files_the_customer_under_team_as_own_workspace(): void
    {
        [$owner, $teamA, $teamB] = $this->makeOwnerWithASecondTeam();

        $agent = Agent::factory()->for($owner)->create();
        $this->assertSame($teamA->id, $agent->team_id);

        $this->switchTo($owner, $teamB);

        // A lead with no conversation and a phone number: LeadObserver's
        // resolve() reaches resolveForLead()'s forceCreate branch, the
        // real background chain, not a shortcut.
        $lead = Lead::factory()->for($agent)->create(['conversation_id' => null, 'phone' => '+992900112233']);

        $customer = Customer::where('phone_key', app(CustomerResolver::class)->phoneKey($lead->phone))->first();
        $this->assertNotNull($customer, 'resolveForLead() did not file a customer for this lead.');

        $workspace = CrmWorkspace::find($customer->crm_workspace_id);
        $this->assertSame($teamA->id, $workspace->team_id);
        $this->assertNotSame($teamB->id, $workspace->team_id);
    }
}
