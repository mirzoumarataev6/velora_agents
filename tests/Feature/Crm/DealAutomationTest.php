<?php

namespace Tests\Feature\Crm;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\Haggling\HagglingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every automatic move, and — just as important — every move the system
 * refuses to make up.
 *
 * Two writers only, under the three-stage pipeline: a conversation being
 * created (Новый лид) and a price-approval request appearing (Торг). An
 * order placed and a price agreed still touch the deal — they fill in what
 * is being bought and what it is worth — but neither moves the stage any
 * more, because Оплата now means the money actually arrived, and nothing
 * this system observes can attest to that.
 */
class DealAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(?User $owner = null): Conversation
    {
        $agent = Agent::factory()->for($owner ?? User::factory())->create();

        return Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->create(['type' => 'telegram']))
            ->create();
    }

    public function test_a_new_conversation_opens_a_card_in_the_first_stage(): void
    {
        $conversation = $this->conversation();

        $deal = Deal::sole();

        $this->assertSame('new_lead', $deal->stage);
        $this->assertSame($conversation->id, $deal->conversation_id);
        $this->assertSame($conversation->fresh()->customer_id, $deal->customer_id);
        // Nothing has been moved by hand yet.
        $this->assertNull($deal->stage_set_by_owner_at);
    }

    /** A second thread on the same customer is a second piece of business. */
    public function test_a_conversation_does_not_open_a_second_card_for_itself(): void
    {
        $conversation = $this->conversation();

        $conversation->forceFill(['last_message_at' => now()->addMinute()])->save();

        $this->assertSame(1, Deal::count());
    }

    /**
     * A real exchange used to have its own stage (Переписка). It does not any
     * more — a conversation happening at all is already what Новый лид means
     * now, so messages back and forth leave the deal exactly where it is.
     */
    public function test_messages_back_and_forth_do_not_move_a_deal_anywhere(): void
    {
        $conversation = $this->conversation();

        $conversation->messages()->create(['role' => 'user', 'content' => 'Привет']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Здравствуйте!']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Сколько стоит?']);

        $this->assertSame('new_lead', Deal::sole()->stage);
    }

    /** The price-approval request IS the negotiation, as far as the data goes. */
    public function test_a_price_approval_request_moves_the_card_to_haggling(): void
    {
        $conversation = $this->conversation();

        Lead::factory()->for($conversation->agent)->create([
            'conversation_id' => $conversation->id,
            'type' => HagglingService::TYPE,
            'status' => HagglingService::STATUS_PENDING,
        ]);

        $this->assertSame('haggling', Deal::sole()->stage);
    }

    /**
     * An order no longer moves the stage — placing one is not proof of
     * payment — but it is still the strongest hint yet at what is being
     * bought, so the deal's title still gets filled in from it.
     */
    public function test_a_placed_order_names_the_deal_without_moving_its_stage(): void
    {
        $conversation = $this->conversation();

        Lead::factory()->for($conversation->agent)->create([
            'conversation_id' => $conversation->id,
            'type' => 'order',
            'items' => 'Ковёр 3х4, синий',
        ]);

        $deal = Deal::sole();

        $this->assertSame('new_lead', $deal->stage);
        $this->assertSame('Ковёр 3х4, синий', $deal->title);
    }

    /**
     * Same story for an approved price: it sets the amount, the number the
     * owner is willing to sell at, but does not assert the customer paid it.
     */
    public function test_an_approved_price_sets_the_amount_without_moving_the_stage_past_haggling(): void
    {
        $conversation = $this->conversation();

        $lead = Lead::factory()->for($conversation->agent)->create([
            'conversation_id' => $conversation->id,
            'type' => HagglingService::TYPE,
            'status' => HagglingService::STATUS_PENDING,
            'requested_price' => 1200,
            'listed_price' => 1500,
        ]);

        $this->assertSame('haggling', Deal::sole()->stage);

        $lead->update(['approved_price' => 1300, 'status' => 'in_progress']);

        $deal = Deal::sole();

        $this->assertSame('haggling', $deal->stage);
        $this->assertSame('1300.00', $deal->amount);
    }

    /**
     * The forward-only rule still holds for the one signal that can still
     * move a stage: haggling never regresses a deal already further along
     * than it (nothing is further along than haggling automatically, but a
     * hand-placed Оплата must not be pulled back by a late price request).
     */
    public function test_automation_never_moves_a_card_backwards(): void
    {
        $conversation = $this->conversation();
        $deal = Deal::sole();

        $this->actingAs($conversation->agent->user)
            ->patchJson(route('crm.deals.move', $deal), ['stage' => 'payment'])
            ->assertOk();

        Lead::factory()->for($conversation->agent)->create([
            'conversation_id' => $conversation->id,
            'type' => HagglingService::TYPE,
            'status' => HagglingService::STATUS_PENDING,
        ]);

        $this->assertSame('payment', Deal::sole()->stage);
    }

    /** A finished deal is finished: no signal reopens one. */
    public function test_automation_leaves_cancelled_or_closed_deals_alone(): void
    {
        $conversation = $this->conversation();
        $deal = Deal::where('conversation_id', $conversation->id)->sole();
        $deal->forceFill(['stage' => 'cancelled', 'stage_role' => 'lost'])->save();

        Lead::factory()->for($conversation->agent)->create([
            'conversation_id' => $conversation->id,
            'type' => HagglingService::TYPE,
            'status' => HagglingService::STATUS_PENDING,
        ]);

        $this->assertSame('cancelled', $deal->fresh()->stage);

        $closedConversation = $this->conversation();
        $closedDeal = Deal::where('conversation_id', $closedConversation->id)->sole();
        $closedDeal->forceFill(['stage' => 'payment', 'stage_role' => 'won', 'closed_at' => now()])->save();

        Lead::factory()->for($closedConversation->agent)->create([
            'conversation_id' => $closedConversation->id,
            'type' => HagglingService::TYPE,
            'status' => HagglingService::STATUS_PENDING,
        ]);

        $this->assertSame('payment', $closedDeal->fresh()->stage);
        $this->assertNotNull($closedDeal->fresh()->closed_at);
    }

    /**
     * The honest gap. Оплата is manual-only now — nothing in this system
     * observes an actual payment, so nothing may claim to.
     */
    public function test_nothing_automatically_reaches_the_paid_stage(): void
    {
        $conversation = $this->conversation();

        Lead::factory()->for($conversation->agent)->create([
            'conversation_id' => $conversation->id,
            'type' => 'order',
        ]);

        $lead = Lead::factory()->for($conversation->agent)->create([
            'conversation_id' => $conversation->id,
            'type' => HagglingService::TYPE,
            'status' => HagglingService::STATUS_PENDING,
        ]);
        $lead->update(['approved_price' => 500]);

        // A closed thread is the nearest lookalike to a finished sale, and it
        // deliberately means nothing to the deal.
        $conversation->forceFill(['status' => 'closed'])->save();

        $this->assertSame(0, Deal::where('stage', 'payment')->count());
    }

    /** The owner testing their own agent must not appear in the pipeline. */
    public function test_a_playground_thread_opens_no_card(): void
    {
        $agent = Agent::factory()->create();

        Conversation::factory()->for($agent)->playground()->create();

        $this->assertSame(0, Deal::count());
    }

    /** A lead whose thread was pruned still fills in that customer's open deal. */
    public function test_an_order_without_a_conversation_still_names_the_customers_open_deal(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        // WhatsApp, so the profile is identified by its number from the start
        // and a detached lead carrying that number lands on the same person.
        $conversation = Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->whatsapp()->create())
            ->create(['external_user_id' => '992900112233']);

        $this->assertSame('new_lead', Deal::sole()->stage);

        Lead::factory()->for($agent)->create([
            'conversation_id' => null,
            'phone' => '+992 90 011 22 33',
            'type' => 'order',
            'items' => 'Ковёр',
        ]);

        $deal = Deal::sole();

        $this->assertSame('new_lead', $deal->stage);
        $this->assertSame('Ковёр', $deal->title);
        $this->assertSame($conversation->fresh()->customer_id, $deal->customer_id);
    }
}
