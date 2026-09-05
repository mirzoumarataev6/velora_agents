<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_the_owners_leads(): void
    {
        $owner = User::factory()->create();
        $ownAgent = Agent::factory()->for($owner)->create();
        Lead::factory()->for($ownAgent)->create(['customer_name' => 'Моя заявка']);

        $stranger = Agent::factory()->create();
        Lead::factory()->for($stranger)->create(['customer_name' => 'Чужая заявка']);

        $response = $this->actingAs($owner)->get(route('leads.index'));

        $response->assertOk();
        $response->assertSee('Моя заявка');
        $response->assertDontSee('Чужая заявка');
    }

    /**
     * An order taken without a number is still an order — but the owner has to
     * see, before they go looking for a phone number that was never given, that
     * this customer can only be answered in the chat they wrote from.
     */
    public function test_the_chat_only_badge_marks_orders_that_have_no_phone_number(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        Lead::factory()->for($agent)->chatOnly()->create(['customer_name' => 'Без номера']);

        $response = $this->actingAs($owner)->get(route('leads.index'));

        $response->assertOk();
        $response->assertSee('Без номера');
        $response->assertSee(__('leads.index.chat_only'));
        // And it says why, for an owner who has not met the badge before.
        $response->assertSee(__('leads.index.chat_only_hint'), false);
    }

    public function test_the_chat_only_badge_is_absent_when_a_phone_number_was_given(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        Lead::factory()->for($agent)->create([
            'customer_name' => 'С номером',
            'phone' => '+992901234567',
        ]);

        $response = $this->actingAs($owner)->get(route('leads.index'));

        $response->assertOk();
        $response->assertSee('+992901234567');
        $response->assertDontSee(__('leads.index.chat_only'));
    }

    /**
     * Both kinds in one list: the badge has to distinguish them, not decorate
     * every row.
     */
    public function test_only_the_phoneless_order_is_badged_in_a_mixed_list(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        Lead::factory()->for($agent)->create(['customer_name' => 'Звонить можно', 'phone' => '+992905550101']);
        Lead::factory()->for($agent)->chatOnly()->create(['customer_name' => 'Только чат']);

        $html = $this->actingAs($owner)->get(route('leads.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, __('leads.index.chat_only')));
    }

    public function test_visiting_the_index_marks_leads_seen(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $lead = Lead::factory()->for($agent)->create(['seen_at' => null]);

        $this->actingAs($owner)->get(route('leads.index'))->assertOk();

        $this->assertNotNull($lead->fresh()->seen_at);
    }

    public function test_poll_returns_unseen_count_and_recent_scoped_to_owner(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        Lead::factory()->count(2)->for($agent)->create(['seen_at' => null]);
        Lead::factory()->for($agent)->seen()->create();

        // Another owner's unseen lead must not count.
        Lead::factory()->create(['seen_at' => null]);

        $response = $this->actingAs($owner)->getJson(route('leads.poll'));

        $response->assertOk();
        $response->assertJsonPath('count', 2);
        $this->assertCount(3, $response->json('recent'));
    }

    public function test_mark_seen_clears_the_unseen_count(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        Lead::factory()->count(3)->for($agent)->create(['seen_at' => null]);

        $this->actingAs($owner)->postJson(route('leads.seen'))->assertOk()->assertJsonPath('count', 0);

        $this->assertSame(0, $agent->leads()->whereNull('seen_at')->count());
    }

    public function test_owner_can_update_lead_status(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $lead = Lead::factory()->for($agent)->create(['status' => 'new']);

        $this->actingAs($owner)
            ->patchJson(route('leads.update', $lead), ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('status', 'done');

        $this->assertSame('done', $lead->fresh()->status);
    }

    public function test_update_rejects_an_invalid_status(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $lead = Lead::factory()->for($agent)->create();

        $this->actingAs($owner)
            ->patchJson(route('leads.update', $lead), ['status' => 'bogus'])
            ->assertStatus(422);
    }

    public function test_owner_cannot_touch_another_users_lead(): void
    {
        $owner = User::factory()->create();
        $othersLead = Lead::factory()->create();

        $this->actingAs($owner)->patchJson(route('leads.update', $othersLead), ['status' => 'done'])->assertForbidden();
        $this->actingAs($owner)->deleteJson(route('leads.destroy', $othersLead))->assertForbidden();
    }

    public function test_owner_can_delete_their_lead(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $lead = Lead::factory()->for($agent)->create();

        $this->actingAs($owner)->deleteJson(route('leads.destroy', $lead))->assertOk();

        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    }

    public function test_leads_require_authentication(): void
    {
        $this->get(route('leads.index'))->assertRedirect();
    }

    // --- Owner notification linking endpoints ---

    public function test_link_returns_422_without_a_connected_telegram_bot(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->postJson(route('agents.notify.telegram.link', $agent))
            ->assertStatus(422);
    }

    public function test_link_returns_a_deep_link_when_telegram_is_connected(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        Channel::factory()->for($agent)->create();

        $response = $this->actingAs($owner)->postJson(route('agents.notify.telegram.link', $agent));

        $response->assertOk();
        $this->assertStringContainsString('https://t.me/test_bot?start=notify_', $response->json('deep_link'));
    }

    public function test_unlink_clears_the_stored_chat(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $agent->forceFill(['notify_telegram_chat_id' => '777', 'notify_telegram_linked_at' => now()])->save();

        $this->actingAs($owner)
            ->deleteJson(route('agents.notify.telegram.unlink', $agent))
            ->assertOk()
            ->assertJsonPath('linked', false);

        $this->assertNull($agent->fresh()->notify_telegram_chat_id);
    }
}
