<?php

namespace Tests\Feature\Crm;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 1 item 3 (root cause): a deal used to open with `title` null, and
 * stayed that way until (if ever) an order gave it one — DealBoardTest's own
 * fixtures and real accounts alike show 23 of 71 deals sitting blank. Every
 * list that renders `title` directly (Projects' linked-deal picker, before
 * this phase's fallback label; the CRM board's own `deal.title || '—'`)
 * either showed nothing or a bare dash with no way to tell two blank deals
 * apart. DealStageAutomation::conversationStarted() now seeds the title with
 * the customer's display name — never blank, see Customer::displayName() —
 * and orderPlaced() still swaps it for the real items once an order exists.
 */
class DealTitleDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_newly_opened_deal_is_titled_after_its_customer_not_left_blank(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $channel = Channel::factory()->for($agent)->create();

        $conversation = Conversation::factory()->for($agent)->create([
            'channel_id' => $channel->id,
            'external_user_name' => 'Нигора Каримова',
        ]);

        $deal = Deal::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($deal);
        $customer = Customer::find($deal->customer_id);

        $this->assertNotNull($deal->title);
        $this->assertNotSame('', $deal->title);
        $this->assertSame($customer->displayName(), $deal->title);
    }

    public function test_placing_an_order_still_replaces_the_seeded_placeholder_title(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $channel = Channel::factory()->for($agent)->create();

        $conversation = Conversation::factory()->for($agent)->create([
            'channel_id' => $channel->id,
            'external_user_name' => 'Нигора Каримова',
        ]);

        $deal = Deal::where('conversation_id', $conversation->id)->first();
        $this->assertSame('Нигора Каримова', $deal->title);

        Lead::factory()->create([
            'conversation_id' => $conversation->id,
            'customer_id' => $deal->customer_id,
            'type' => 'order',
            'items' => '2x Чехол для телефона',
        ]);

        $this->assertSame('2x Чехол для телефона', $deal->fresh()->title);
    }

    public function test_a_title_the_owner_already_edited_is_never_overwritten_by_a_later_order(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $channel = Channel::factory()->for($agent)->create();

        $conversation = Conversation::factory()->for($agent)->create([
            'channel_id' => $channel->id,
            'external_user_name' => 'Нигора Каримова',
        ]);

        $deal = Deal::where('conversation_id', $conversation->id)->first();
        $deal->forceFill(['title' => 'Custom title the owner typed'])->save();

        Lead::factory()->create([
            'conversation_id' => $conversation->id,
            'customer_id' => $deal->customer_id,
            'type' => 'order',
            'items' => '2x Чехол для телефона',
        ]);

        $this->assertSame('Custom title the owner typed', $deal->fresh()->title);
    }
}
