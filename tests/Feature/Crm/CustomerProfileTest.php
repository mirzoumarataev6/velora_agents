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

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The whole reason the profile exists: one story, both apps, in order.
     */
    public function test_the_profile_shows_the_transcript_of_every_channel_together(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $whatsapp = Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->whatsapp()->create())
            ->create(['external_user_id' => '992900112233']);
        $whatsapp->messages()->create(['role' => 'user', 'content' => 'Пишу из ватсапа']);

        $telegram = Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->create(['type' => 'telegram']))
            ->create(['external_user_id' => '558912345']);
        $telegram->messages()->create(['role' => 'user', 'content' => 'А это телеграм']);

        // One number, captured in the Telegram chat, makes them one person.
        Lead::factory()->for($agent)->create(['conversation_id' => $telegram->id, 'phone' => '+992900112233']);

        $customer = Customer::sole();

        $response = $this->actingAs($owner)->get(route('crm.customers.show', $customer));

        $response->assertOk();
        $response->assertSee('Пишу из ватсапа');
        $response->assertSee('А это телеграм');
    }

    public function test_the_cross_channel_message_history_is_paginated_without_dropping_older_messages(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->whatsapp()->create())->create();
        $customer = $conversation->customer;

        for ($i = 0; $i < 35; $i++) {
            $conversation->messages()->create(['role' => 'user', 'content' => "Message {$i}"]);
        }

        $this->actingAs($owner)->get(route('crm.customers.show', $customer))
            ->assertOk()->assertSee('Message 34')->assertDontSee('Message 0')
            ->assertSee('timeline_page=2');

        $this->actingAs($owner)->get(route('crm.customers.show', [$customer, 'timeline_page' => 2]))
            ->assertOk()->assertSee('Message 0');
    }

    public function test_another_owners_customer_is_not_reachable(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('crm.customers.show', $customer))
            ->assertForbidden();
    }

    public function test_the_owner_can_edit_the_status_tags_and_note(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();

        $this->actingAs($owner)->patch(route('crm.customers.update', $customer), [
            'name' => 'Азиз',
            'status' => 'customer',
            'tags' => 'опт, постоянный, опт',
            'note' => 'Возит в Худжанд',
        ])->assertRedirect();

        $customer->refresh();

        $this->assertSame('Азиз', $customer->name);
        $this->assertSame('customer', $customer->status);
        // De-duplicated, in the order they were typed.
        $this->assertSame(['опт', 'постоянный'], $customer->tags);
        $this->assertSame('Возит в Худжанд', $customer->note);
    }

    /**
     * The deal panel's status quick-select sends only `status` — this is the
     * fix that makes that safe: a partial save must not read the other three
     * fields' absence as "clear them", which an earlier version of this
     * endpoint (built for the full profile form, which always sent all four)
     * would have done.
     */
    public function test_a_status_only_update_leaves_the_name_tags_and_note_alone(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create([
            'name' => 'Азиз', 'tags' => ['опт'], 'note' => 'Возит в Худжанд',
        ]);

        $this->actingAs($owner)->patchJson(route('crm.customers.update', $customer), [
            'status' => 'customer',
        ])->assertOk();

        $customer->refresh();

        $this->assertSame('customer', $customer->status);
        $this->assertSame('Азиз', $customer->name);
        $this->assertSame(['опт'], $customer->tags);
        $this->assertSame('Возит в Худжанд', $customer->note);
    }

    /**
     * The escape hatch for two profiles the data could never have matched:
     * both threads end up on the surviving profile.
     */
    public function test_the_owner_can_merge_two_profiles_by_hand(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $channel = Channel::factory()->for($agent)->create(['type' => 'telegram']);

        $first = Conversation::factory()->for($agent)->for($channel)->create(['external_user_id' => '111']);
        $second = Conversation::factory()->for($agent)->for($channel)->create(['external_user_id' => '222']);

        $keep = $first->fresh()->customer;
        $absorb = $second->fresh()->customer;

        $this->actingAs($owner)
            ->post(route('crm.customers.merge', $keep), ['customer_id' => $absorb->id])
            ->assertRedirect(route('crm.customers.show', $keep));

        $this->assertSame(1, Customer::count());
        $this->assertSame($keep->id, $first->fresh()->customer_id);
        $this->assertSame($keep->id, $second->fresh()->customer_id);
    }

    public function test_a_merge_cannot_reach_another_owners_customer(): void
    {
        $owner = User::factory()->create();
        $mine = Customer::factory()->for($owner)->create();
        $theirs = Customer::factory()->create();

        $this->actingAs($owner)
            ->post(route('crm.customers.merge', $mine), ['customer_id' => $theirs->id])
            ->assertSessionHasErrors('customer_id');

        $this->assertDatabaseHas('customers', ['id' => $theirs->id]);
    }

    public function test_the_customer_pages_require_authentication(): void
    {
        $customer = Customer::factory()->create();

        $this->get(route('crm.customers.show', $customer))->assertRedirect();
    }
}
