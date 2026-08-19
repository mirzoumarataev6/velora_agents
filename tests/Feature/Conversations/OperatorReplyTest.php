<?php

namespace Tests\Feature\Conversations;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Human takeover: the owner steps in and answers the customer personally
 * through the same channel the agent uses.
 */
class OperatorReplyTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected Channel $channel;

    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->agent = Agent::factory()->for($this->owner)->create();
        $this->channel = Channel::factory()->for($this->agent)->create();
        $this->conversation = Conversation::factory()
            ->for($this->agent)
            ->for($this->channel)
            ->create(['external_user_id' => '555', 'status' => 'ai']);
    }

    protected function fakeTelegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    public function test_an_operator_reply_is_delivered_to_the_customer_on_their_channel(): void
    {
        $this->fakeTelegram();

        $response = $this->actingAs($this->owner)
            ->postJson("/conversations/{$this->conversation->id}/reply", ['content' => 'Здравствуйте, это владелец!']);

        $response->assertCreated();
        $response->assertJsonPath('message.role', 'operator');
        $response->assertJsonPath('message.content', 'Здравствуйте, это владелец!');

        // It actually went out to Telegram, addressed to that customer.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] === '555'
                && $request['text'] === 'Здравствуйте, это владелец!';
        });

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'role' => 'operator',
            'content' => 'Здравствуйте, это владелец!',
        ]);
    }

    public function test_replying_takes_the_conversation_over_so_the_agent_stops_answering(): void
    {
        $this->fakeTelegram();

        $this->actingAs($this->owner)
            ->postJson("/conversations/{$this->conversation->id}/reply", ['content' => 'I will handle this'])
            ->assertCreated()
            ->assertJsonPath('status', 'human_takeover');

        $this->assertSame('human_takeover', $this->conversation->fresh()->status);
    }

    public function test_nothing_is_recorded_when_delivery_fails(): void
    {
        // A transcript that shows a reply the customer never got is worse than
        // no reply: the owner would stop waiting for one.
        Http::fake(['api.telegram.org/*' => Http::response('upstream exploded', 500)]);

        $this->actingAs($this->owner)
            ->postJson("/conversations/{$this->conversation->id}/reply", ['content' => 'Did this arrive?'])
            ->assertStatus(502);

        $this->assertDatabaseMissing('messages', ['conversation_id' => $this->conversation->id, 'role' => 'operator']);
        $this->assertSame('ai', $this->conversation->fresh()->status);
    }

    public function test_a_conversation_without_a_channel_cannot_be_replied_to(): void
    {
        Http::fake();
        $playground = Conversation::factory()->for($this->agent)->playground()->create();

        $this->actingAs($this->owner)
            ->postJson("/conversations/{$playground->id}/reply", ['content' => 'hello'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_reply_content_is_validated(): void
    {
        Http::fake();

        $this->actingAs($this->owner)
            ->postJson("/conversations/{$this->conversation->id}/reply", ['content' => ''])
            ->assertStatus(422);

        $this->actingAs($this->owner)
            ->postJson("/conversations/{$this->conversation->id}/reply", ['content' => str_repeat('a', 4001)])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_the_owner_can_take_over_hand_back_and_close(): void
    {
        $this->actingAs($this->owner)
            ->patchJson("/conversations/{$this->conversation->id}/status", ['status' => 'human_takeover'])
            ->assertOk()->assertJsonPath('status', 'human_takeover');

        $this->actingAs($this->owner)
            ->patchJson("/conversations/{$this->conversation->id}/status", ['status' => 'ai'])
            ->assertOk()->assertJsonPath('status', 'ai');

        $this->actingAs($this->owner)
            ->patchJson("/conversations/{$this->conversation->id}/status", ['status' => 'closed'])
            ->assertOk();

        $this->assertSame('closed', $this->conversation->fresh()->status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->patchJson("/conversations/{$this->conversation->id}/status", ['status' => 'deleted'])
            ->assertStatus(422);
    }

    public function test_handing_back_lets_the_agent_reply_again(): void
    {
        // The takeover flag is the same one HandleIncomingMessageJob checks, so
        // handing back has to actually restore automatic replies.
        $this->conversation->update(['status' => 'human_takeover']);

        $this->actingAs($this->owner)
            ->patchJson("/conversations/{$this->conversation->id}/status", ['status' => 'ai'])
            ->assertOk();

        $this->assertSame('ai', $this->conversation->fresh()->status);
    }

    public function test_an_operator_reply_marks_the_thread_read_and_bumps_its_activity(): void
    {
        $this->fakeTelegram();
        Message::factory()->for($this->conversation)->create(['content' => 'Are you there?']);

        $this->actingAs($this->owner)
            ->postJson("/conversations/{$this->conversation->id}/reply", ['content' => 'Yes!'])
            ->assertCreated();

        $fresh = $this->conversation->fresh();
        $this->assertNotNull($fresh->operator_read_at);
        $this->assertNotNull($fresh->last_message_at);

        $this->actingAs($this->owner)->getJson('/conversations/list')
            ->assertJsonPath('data.0.unread', false)
            ->assertJsonPath('data.0.preview', 'Yes!');
    }
}
