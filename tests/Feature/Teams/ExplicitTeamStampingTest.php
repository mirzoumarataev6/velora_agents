<?php

namespace Tests\Feature\Teams;

use App\Jobs\Bi\NormalizeSourceJob;
use App\Models\Agent;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\Bi\Ingestion\ExternalDatasetImporter;
use App\Services\Crm\CustomerResolver;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\Support\BuildsBiFixtures;
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
    use BuildsBiFixtures, RefreshDatabase;

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

    public function test_a_normalized_bi_source_stamps_its_dataset_with_the_sources_team_not_current_team_id_at_job_time(): void
    {
        Storage::fake('local');

        [$owner, $teamA, $teamB] = $this->makeOwnerWithASecondTeam();

        // Uploaded while current_team_id still correctly points at team A.
        $source = $this->csvSource($owner, [
            ['name', 'price'],
            ['Widget', '10'],
        ]);
        $this->assertSame($teamA->id, $source->team_id);

        // The switch: NormalizeSourceJob is deliberately queued rather than
        // run inline (a large upload takes minutes to read) — this
        // simulates the owner switching teams in that window, before the
        // job that actually creates the BiDataset ever runs.
        $this->switchTo($owner, $teamB);

        NormalizeSourceJob::dispatch($source);

        $dataset = BiDataset::where('bi_source_id', $source->id)->first();
        $this->assertNotNull($dataset, 'NormalizeSourceJob did not produce a dataset.');

        $this->assertSame($teamA->id, $dataset->team_id);
        $this->assertNotSame($teamB->id, $dataset->team_id);
    }

    public function test_an_external_dataset_import_stamps_the_dataset_with_the_sources_team_not_current_team_id(): void
    {
        [$owner, $teamA, $teamB] = $this->makeOwnerWithASecondTeam();

        // The source already belongs to team A — built directly rather than
        // through a live customer connection, since createDataset() is the
        // unit under test here, not the describeTable() round trip.
        $source = BiSource::factory()->create(['user_id' => $owner->id, 'team_id' => $teamA->id]);

        // This path runs synchronously within one request in production, so
        // current_team_id cannot really drift mid-call the way it can for
        // the queued NormalizeSourceJob above — but the fix must still read
        // the team from $source, not re-derive it, so this proves the unit
        // itself is correct regardless of what current_team_id says.
        $this->switchTo($owner, $teamB);

        $importer = app(ExternalDatasetImporter::class);
        $method = new ReflectionMethod($importer, 'createDataset');
        $method->setAccessible(true);

        $dataset = $method->invoke($importer, $source, 'orders', [
            'columns' => [['name' => 'id', 'type' => 'int', 'numeric' => true]],
            'sample' => [],
        ]);

        $this->assertSame($teamA->id, $dataset->team_id);
        $this->assertNotSame($teamB->id, $dataset->team_id);

        $column = DB::table('bi_columns')->where('bi_dataset_id', $dataset->id)->first();
        $this->assertNotNull($column);
        $this->assertSame($teamA->id, $column->team_id);
    }

    public function test_a_lead_resolved_against_a_customer_stamps_the_customer_with_the_leads_agents_team_not_current_team_id(): void
    {
        [$owner, $teamA, $teamB] = $this->makeOwnerWithASecondTeam();

        // Built while current_team_id still correctly points at team A.
        $agent = Agent::factory()->for($owner)->create();
        $this->assertSame($teamA->id, $agent->team_id);

        // The switch: current_team_id now points at team B, while the
        // agent above still belongs to team A.
        $this->switchTo($owner, $teamB);

        // A lead with no conversation and a phone number: LeadObserver's
        // resolve() reaches resolveForLead()'s forceCreate branch, the
        // real background chain (no existing conversation/customer to
        // short-circuit it), not a shortcut.
        $lead = Lead::factory()->for($agent)->create(['conversation_id' => null, 'phone' => '+992900112233']);

        $customer = Customer::where('user_id', $owner->id)->where('phone_key', app(CustomerResolver::class)->phoneKey($lead->phone))->first();
        $this->assertNotNull($customer, 'resolveForLead() did not file a customer for this lead.');

        $this->assertSame($teamA->id, $customer->team_id);
        $this->assertNotSame($teamB->id, $customer->team_id);
    }

    public function test_a_conversations_first_message_stamps_the_customer_with_the_conversations_agent_team_not_current_team_id(): void
    {
        [$owner, $teamA, $teamB] = $this->makeOwnerWithASecondTeam();

        $agent = Agent::factory()->for($owner)->create();
        $this->assertSame($teamA->id, $agent->team_id);
        $channel = Channel::factory()->for($agent)->create();

        $this->switchTo($owner, $teamB);

        // A customer's first message on this thread: ConversationObserver
        // resolves (and here, opens) a customer via CustomerResolver::open().
        $conversation = Conversation::factory()->for($agent)->create(['channel_id' => $channel->id]);

        $customer = Customer::where('id', $conversation->fresh()->customer_id)->first();
        $this->assertNotNull($customer, 'resolveForConversation() did not file a customer for this conversation.');

        $this->assertSame($teamA->id, $customer->team_id);
        $this->assertNotSame($teamB->id, $customer->team_id);
    }
}
