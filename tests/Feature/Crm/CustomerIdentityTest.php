<?php

namespace Tests\Feature\Crm;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one hard question in the CRM: when are two threads the same person?
 */
class CustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_whatsapp_thread_identifies_the_customer_by_their_number(): void
    {
        $agent = Agent::factory()->create();
        $channel = Channel::factory()->for($agent)->whatsapp()->create();

        $conversation = Conversation::factory()->for($agent)->for($channel)->create([
            'external_user_id' => '992900112233',
            'external_user_name' => 'Азиз',
        ]);

        $customer = $conversation->fresh()->customer;

        $this->assertNotNull($customer);
        $this->assertSame('Азиз', $customer->name);
        $this->assertSame('992900112233', $customer->phone);
        $this->assertSame('whatsapp', $customer->source);
        $this->assertSame($agent->user_id, $customer->user_id);
    }

    /**
     * Telegram hands over an opaque user id, never a phone number, so the
     * profile opens unidentified and waits for one.
     */
    public function test_a_telegram_thread_opens_a_profile_with_no_number(): void
    {
        $agent = Agent::factory()->create();
        $channel = Channel::factory()->for($agent)->create(['type' => 'telegram']);

        $conversation = Conversation::factory()->for($agent)->for($channel)->create([
            'external_user_id' => '558912345',
        ]);

        $customer = $conversation->fresh()->customer;

        $this->assertNotNull($customer);
        $this->assertNull($customer->phone);
        $this->assertNull($customer->phone_key);
        $this->assertTrue($customer->isChatOnly());
    }

    /**
     * The pay-off. Someone writes on WhatsApp, writes again on Telegram, and
     * only when the agent finally captures a number does the system learn the
     * two are one person — at which point both threads belong to one profile.
     */
    public function test_a_captured_phone_number_retroactively_merges_two_channels(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $whatsapp = Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->whatsapp()->create())
            ->create(['external_user_id' => '992900112233']);

        $telegram = Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->create(['type' => 'telegram']))
            ->create(['external_user_id' => '558912345']);

        $this->assertNotSame($whatsapp->fresh()->customer_id, $telegram->fresh()->customer_id);
        $this->assertSame(2, Customer::count());

        // The agent captures the same number out of the Telegram chat.
        Lead::factory()->for($agent)->create([
            'conversation_id' => $telegram->id,
            'phone' => '+992 90 011 22 33',
        ]);

        $this->assertSame(1, Customer::count());

        $customer = Customer::sole();
        $this->assertSame($whatsapp->fresh()->customer_id, $customer->id);
        $this->assertSame($telegram->fresh()->customer_id, $customer->id);
        $this->assertEqualsCanonicalizing(['whatsapp', 'telegram'], $customer->channelTypes());
    }

    /**
     * A number written with a country code and the same number written without
     * one are one customer — that is what the nine-digit key is for.
     */
    public function test_the_same_number_written_two_ways_is_one_customer(): void
    {
        $agent = Agent::factory()->create();

        Lead::factory()->for($agent)->create(['phone' => '+992900112233', 'customer_name' => 'Азиз']);
        Lead::factory()->for($agent)->create(['phone' => '900112233', 'customer_name' => 'Азиз А.']);

        $this->assertSame(1, Customer::count());
        $this->assertSame(2, Customer::sole()->leads()->count());
    }

    /**
     * Deliberate: the only other thing two threads can share is a display name,
     * and merging on it would fuse two strangers into one order history.
     */
    public function test_two_customers_with_the_same_name_are_not_merged(): void
    {
        $agent = Agent::factory()->create();
        $channel = Channel::factory()->for($agent)->create(['type' => 'telegram']);

        Conversation::factory()->for($agent)->for($channel)->create([
            'external_user_id' => '111', 'external_user_name' => 'Азиз',
        ]);
        Conversation::factory()->for($agent)->for($channel)->create([
            'external_user_id' => '222', 'external_user_name' => 'Азиз',
        ]);

        $this->assertSame(2, Customer::count());
    }

    /** The owner testing their own agent is not a customer of themselves. */
    public function test_a_playground_thread_creates_no_customer(): void
    {
        $agent = Agent::factory()->create();

        Conversation::factory()->for($agent)->playground()->create();

        $this->assertSame(0, Customer::count());
    }

    /** A profile that already has a number is never silently reassigned another. */
    public function test_a_second_number_does_not_overwrite_an_identified_customer(): void
    {
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->whatsapp()->create())
            ->create(['external_user_id' => '992900112233']);

        Lead::factory()->for($agent)->create([
            'conversation_id' => $conversation->id,
            'phone' => '+992 91 777 66 55',
        ]);

        $this->assertSame('992900112233', $conversation->fresh()->customer->phone);
    }

    /** One person, two of the owner's agents, one profile. */
    public function test_customers_are_shared_across_the_owners_agents(): void
    {
        $owner = User::factory()->create();
        $first = Agent::factory()->for($owner)->create();
        $second = Agent::factory()->for($owner)->create();

        Conversation::factory()->for($first)
            ->for(Channel::factory()->for($first)->whatsapp()->create())
            ->create(['external_user_id' => '992900112233']);

        Conversation::factory()->for($second)
            ->for(Channel::factory()->for($second)->whatsapp()->create())
            ->create(['external_user_id' => '992900112233']);

        $this->assertSame(1, Customer::where('user_id', $owner->id)->count());
    }

    /** ...but never across two different businesses. */
    public function test_two_owners_never_share_a_customer(): void
    {
        $mine = Agent::factory()->create();
        $theirs = Agent::factory()->create();

        foreach ([$mine, $theirs] as $agent) {
            Conversation::factory()->for($agent)
                ->for(Channel::factory()->for($agent)->whatsapp()->create())
                ->create(['external_user_id' => '992900112233']);
        }

        $this->assertSame(2, Customer::count());
    }
}
