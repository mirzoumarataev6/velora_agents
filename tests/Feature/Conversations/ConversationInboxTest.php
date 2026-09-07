<?php

namespace Tests\Feature\Conversations;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The inbox aggregates across every agent the owner has — which makes the
 * scoping question ("whose conversations am I looking at?") the single most
 * important thing to get right here.
 */
class ConversationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->agent = Agent::factory()->for($this->owner)->create(['name' => 'Shop bot']);
    }

    /**
     * An agent has at most one channel per type (a unique constraint), so
     * conversations for the same agent share its channel — exactly as they do
     * in production, where every Telegram customer arrives through one bot.
     */
    protected function conversationFor(?Agent $agent = null, array $attributes = []): Conversation
    {
        $agent ??= $this->agent;
        $channel = $agent->channels()->where('type', 'telegram')->first()
            ?? Channel::factory()->for($agent)->create();

        return Conversation::factory()->for($agent)->for($channel)->create($attributes);
    }

    public function test_the_inbox_page_renders_for_a_signed_in_owner(): void
    {
        $response = $this->actingAs($this->owner)->get('/conversations');

        $response->assertOk();
        $response->assertSee('conversationsInbox', false);
        // The page's own namespace ships for every locale, so the instant
        // locale switch has strings to swap to.
        $response->assertSee('"ru":{"nav"', false);
        $response->assertSee('conversations', false);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/conversations')->assertRedirect();
        $this->getJson('/conversations/list')->assertUnauthorized();
    }

    public function test_the_list_only_returns_conversations_from_the_owners_own_agents(): void
    {
        $mine = $this->conversationFor(null, ['external_user_name' => 'My customer']);

        $stranger = User::factory()->create();
        $strangerAgent = Agent::factory()->for($stranger)->create();
        $this->conversationFor($strangerAgent, ['external_user_name' => 'Someone elses customer']);

        $response = $this->actingAs($this->owner)->getJson('/conversations/list');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mine->id);
        $response->assertDontSee('Someone elses customer');
    }

    public function test_another_owners_conversation_cannot_be_opened_replied_to_or_reassigned(): void
    {
        $stranger = User::factory()->create();
        $strangerAgent = Agent::factory()->for($stranger)->create();
        $theirs = $this->conversationFor($strangerAgent);

        $this->actingAs($this->owner)->getJson("/conversations/{$theirs->id}")->assertForbidden();
        $this->actingAs($this->owner)->postJson("/conversations/{$theirs->id}/reply", ['content' => 'hi'])->assertForbidden();
        $this->actingAs($this->owner)->patchJson("/conversations/{$theirs->id}/status", ['status' => 'closed'])->assertForbidden();
    }

    public function test_conversations_from_several_agents_appear_together_newest_first(): void
    {
        $second = Agent::factory()->for($this->owner)->create(['name' => 'Clinic bot']);

        $older = $this->conversationFor(null, ['last_message_at' => now()->subHours(2)]);
        $newer = $this->conversationFor($second, ['last_message_at' => now()]);

        $response = $this->actingAs($this->owner)->getJson('/conversations/list');

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $newer->id);
        $response->assertJsonPath('data.0.agent.name', 'Clinic bot');
        $response->assertJsonPath('data.1.id', $older->id);
    }

    public function test_playground_threads_are_excluded_because_they_have_no_customer(): void
    {
        Conversation::factory()->for($this->agent)->playground()->create();

        $this->actingAs($this->owner)->getJson('/conversations/list')->assertJsonCount(0, 'data');
    }

    public function test_a_row_carries_the_preview_channel_agent_and_lead_flag(): void
    {
        $conversation = $this->conversationFor(null, ['external_user_name' => 'Ivan']);
        Message::factory()->for($conversation)->create(['content' => 'Do you have size M?']);
        Message::factory()->for($conversation)->fromAgent()->create(['content' => 'Yes, in stock!']);
        Lead::factory()->for($this->agent)->create(['conversation_id' => $conversation->id]);

        $response = $this->actingAs($this->owner)->getJson('/conversations/list');

        $response->assertJsonPath('data.0.customer', 'Ivan');
        $response->assertJsonPath('data.0.channel', 'telegram');
        $response->assertJsonPath('data.0.agent.name', 'Shop bot');
        $response->assertJsonPath('data.0.preview', 'Yes, in stock!');
        $response->assertJsonPath('data.0.preview_role', 'assistant');
        $response->assertJsonPath('data.0.leads_count', 1);
    }

    public function test_a_customer_message_makes_a_conversation_unread_until_it_is_opened(): void
    {
        $conversation = $this->conversationFor();
        Message::factory()->for($conversation)->create(['content' => 'Hello?']);

        $this->actingAs($this->owner)->getJson('/conversations/list')
            ->assertJsonPath('data.0.unread', true)
            ->assertJsonPath('unread_total', 1);

        $this->actingAs($this->owner)->getJson("/conversations/{$conversation->id}")->assertOk();

        $this->actingAs($this->owner)->getJson('/conversations/list')
            ->assertJsonPath('data.0.unread', false)
            ->assertJsonPath('unread_total', 0);
    }

    public function test_the_agents_own_reply_does_not_make_a_thread_unread(): void
    {
        $conversation = $this->conversationFor(null, ['operator_read_at' => now()]);
        Message::factory()->for($conversation)->fromAgent()->create();

        $this->actingAs($this->owner)->getJson('/conversations/list')
            ->assertJsonPath('data.0.unread', false);
    }

    public function test_filters_narrow_the_list_by_status_agent_and_channel(): void
    {
        $second = Agent::factory()->for($this->owner)->create();

        $needsHuman = $this->conversationFor(null, ['status' => 'human_takeover']);
        $withLead = $this->conversationFor($second);
        Lead::factory()->for($second)->create(['conversation_id' => $withLead->id]);

        $whatsappChannel = Channel::factory()->for($this->agent)->whatsapp()->create();
        $onWhatsApp = Conversation::factory()->for($this->agent)->for($whatsappChannel)->create();

        $this->actingAs($this->owner)->getJson('/conversations/list?status=needs_human')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $needsHuman->id);

        $this->actingAs($this->owner)->getJson('/conversations/list?status=has_lead')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $withLead->id);

        $this->actingAs($this->owner)->getJson("/conversations/list?agent={$second->id}")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $withLead->id);

        $this->actingAs($this->owner)->getJson('/conversations/list?channel=whatsapp')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $onWhatsApp->id);
    }

    public function test_closed_conversations_are_hidden_unless_asked_for(): void
    {
        $open = $this->conversationFor();
        $closed = $this->conversationFor(null, ['status' => 'closed']);

        $this->actingAs($this->owner)->getJson('/conversations/list')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);

        $this->actingAs($this->owner)->getJson('/conversations/list?status=closed')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $closed->id);

        // "All" sits beside its own "Closed" chip, so it means all ACTIVE
        // threads — closed ones stay archived until asked for by name.
        $this->actingAs($this->owner)->getJson('/conversations/list?status=all')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
    }

    public function test_search_matches_customers_and_message_text(): void
    {
        $byName = $this->conversationFor(null, ['external_user_name' => 'Дилшод']);
        $byMessage = $this->conversationFor(null, ['external_user_name' => 'Someone']);
        Message::factory()->for($byMessage)->create(['content' => 'Сколько стоит доставка велосипеда?']);

        $this->actingAs($this->owner)->getJson('/conversations/list?q='.urlencode('Дилшод'))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $byName->id);

        $this->actingAs($this->owner)->getJson('/conversations/list?q='.urlencode('велосипед'))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $byMessage->id);

        $this->actingAs($this->owner)->getJson('/conversations/list?q=nothingmatches')->assertJsonCount(0, 'data');
    }

    public function test_a_date_range_filters_by_last_activity(): void
    {
        $old = $this->conversationFor(null, ['last_message_at' => now()->subDays(10)]);
        $recent = $this->conversationFor(null, ['last_message_at' => now()]);

        $from = now()->subDays(2)->toDateString();

        $this->actingAs($this->owner)->getJson("/conversations/list?from={$from}")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $recent->id);

        $to = now()->subDays(5)->toDateString();

        $this->actingAs($this->owner)->getJson("/conversations/list?to={$to}")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    }

    public function test_the_thread_returns_the_full_transcript_with_leads_and_knowledge_used(): void
    {
        $conversation = $this->conversationFor();
        Message::factory()->for($conversation)->create(['content' => 'Do you deliver?']);
        Message::factory()->for($conversation)->fromAgent()->create([
            'content' => 'Yes, free over 300.',
            'meta' => ['retrieved_chunks' => [[
                'id' => 1,
                'source_name' => 'Delivery terms',
                'content' => 'Delivery is free for orders over 300 somoni.',
                'score' => 0.4812,
                'confident' => true,
            ]]],
        ]);
        Lead::factory()->for($this->agent)->create(['conversation_id' => $conversation->id, 'customer_name' => 'Ivan']);

        $response = $this->actingAs($this->owner)->getJson("/conversations/{$conversation->id}");

        $response->assertOk();
        $response->assertJsonCount(2, 'messages');
        $response->assertJsonPath('messages.0.role', 'user');
        $response->assertJsonPath('messages.1.chunks.0.source', 'Delivery terms');
        $response->assertJsonPath('messages.1.chunks.0.score', 0.481);
        $response->assertJsonPath('leads.0.customer_name', 'Ivan');
        $response->assertJsonPath('conversation.can_reply', true);
    }

    public function test_pagination_reports_its_pages(): void
    {
        $channel = Channel::factory()->for($this->agent)->create();
        Conversation::factory()->count(30)->for($this->agent)->for($channel)->create();

        $first = $this->actingAs($this->owner)->getJson('/conversations/list');
        $first->assertJsonCount(25, 'data');
        $first->assertJsonPath('last_page', 2);
        $first->assertJsonPath('total', 30);

        $this->actingAs($this->owner)->getJson('/conversations/list?page=2')->assertJsonCount(5, 'data');
    }

    // -----------------------------------------------------------------
    // The 👎 filter
    // -----------------------------------------------------------------

    /**
     * The destination of the dashboard's quality card. Without it that card's
     * "все негативные оценки" button would land on an unfiltered inbox and the
     * owner would be left hunting.
     */
    public function test_the_rated_down_filter_returns_only_conversations_holding_a_disliked_reply(): void
    {
        $bad = $this->conversationFor(null, ['external_user_name' => 'Unhappy']);
        $bad->messages()->create(['role' => 'assistant', 'content' => 'Неверный ответ'])
            ->forceFill(['rating' => Message::RATING_DOWN, 'rated_at' => now()])->save();

        $good = $this->conversationFor(null, ['external_user_name' => 'Happy']);
        $good->messages()->create(['role' => 'assistant', 'content' => 'Верный ответ'])
            ->forceFill(['rating' => Message::RATING_UP, 'rated_at' => now()])->save();

        $this->conversationFor(null, ['external_user_name' => 'Unrated']);

        $names = collect($this->actingAs($this->owner)
            ->getJson('/conversations/list?status=rated_down')
            ->assertOk()
            ->json('data'))
            ->pluck('customer');

        $this->assertCount(1, $names);
        $this->assertSame('Unhappy', $names->first());
    }

    /**
     * The one place this filter departs from the others, deliberately: every
     * other slice hides closed threads, and a wrong answer does not stop being
     * a wrong answer because the thread was archived. Hiding it would also make
     * this list disagree with the count on the card that linked here, which
     * reads as a bug rather than as a policy.
     */
    public function test_a_disliked_reply_in_a_closed_conversation_is_still_listed(): void
    {
        $closed = $this->conversationFor(null, ['external_user_name' => 'Closed', 'status' => 'closed']);
        $closed->messages()->create(['role' => 'assistant', 'content' => 'Неверный ответ'])
            ->forceFill(['rating' => Message::RATING_DOWN, 'rated_at' => now()])->save();

        $this->actingAs($this->owner)
            ->getJson('/conversations/list?status=rated_down')
            ->assertOk()
            ->assertJsonPath('data.0.customer', 'Closed');

        // The default slice still hides it, as it always did.
        $this->actingAs($this->owner)
            ->getJson('/conversations/list?status=all')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_owners_disliked_replies_are_never_listed(): void
    {
        $stranger = User::factory()->create();
        $strangerAgent = Agent::factory()->for($stranger)->create();
        $theirs = $this->conversationFor($strangerAgent, ['external_user_name' => 'Not mine']);
        $theirs->messages()->create(['role' => 'assistant', 'content' => 'Неверный ответ'])
            ->forceFill(['rating' => Message::RATING_DOWN, 'rated_at' => now()])->save();

        $this->actingAs($this->owner)
            ->getJson('/conversations/list?status=rated_down')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** The chip has to exist in the UI, or the filter is unreachable by hand. */
    public function test_the_inbox_offers_a_rated_down_chip(): void
    {
        $this->actingAs($this->owner)
            ->get('/conversations')
            ->assertOk()
            ->assertSee(__('conversations.filters.rated_down'), false);
    }
}
