<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InstagramWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function instagramTextPayload(string $mid, string $text, string $senderId = '778899'): array
    {
        return [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'IG_PAGE_ID',
                'time' => time(),
                'messaging' => [[
                    'sender' => ['id' => $senderId],
                    'recipient' => ['id' => 'IG_BUSINESS_ID'],
                    'timestamp' => time() * 1000,
                    'message' => ['mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];
    }

    protected function instagramEchoPayload(string $mid): array
    {
        // The business's own outgoing message, echoed back by Meta — must be ignored.
        return [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'IG_PAGE_ID',
                'messaging' => [[
                    'sender' => ['id' => 'IG_BUSINESS_ID'],
                    'recipient' => ['id' => '778899'],
                    'message' => ['mid' => $mid, 'text' => 'Спасибо за обращение!', 'is_echo' => true],
                ]],
            ]],
        ];
    }

    protected function instagramReadReceiptPayload(): array
    {
        // Has no "message" key — must be ignored.
        return [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'IG_PAGE_ID',
                'messaging' => [[
                    'sender' => ['id' => '778899'],
                    'recipient' => ['id' => 'IG_BUSINESS_ID'],
                    'read' => ['mid' => 'mid.READ'],
                ]],
            ]],
        ];
    }

    protected function mockGemini(string $replyText = 'Здравствуйте! Чем помочь?'): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($replyText) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn([
                'text' => $replyText, 'input_tokens' => 10, 'output_tokens' => 5, 'finish_reason' => 'STOP',
            ]);
        });
    }

    protected function fakeMetaApi(): void
    {
        Http::fake([
            'graph.facebook.com/*/messages' => Http::response(['recipient_id' => '778899', 'message_id' => 'mid.OUT1'], 200),
        ]);
    }

    // --- GET verification handshake ---

    public function test_get_handshake_echoes_challenge_when_token_matches(): void
    {
        $channel = Channel::factory()->instagram()->create();
        $verifyToken = $channel->credentials['verify_token'];

        $response = $this->get(route('webhooks.instagram.verify', $channel).'?hub.mode=subscribe&hub.verify_token='.$verifyToken.'&hub.challenge=IG_CHALLENGE_456');

        $response->assertOk();
        $this->assertSame('IG_CHALLENGE_456', $response->getContent());
    }

    public function test_get_handshake_rejects_wrong_verify_token(): void
    {
        $channel = Channel::factory()->instagram()->create();

        $response = $this->get(route('webhooks.instagram.verify', $channel).'?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=SHOULD_NOT_BE_ECHOED');

        $response->assertForbidden();
        $this->assertNotSame('SHOULD_NOT_BE_ECHOED', $response->getContent());
    }

    public function test_get_handshake_on_unknown_channel_returns_404(): void
    {
        $response = $this->get('/webhooks/instagram/00000000-0000-0000-0000-000000000000?hub.mode=subscribe&hub.verify_token=x&hub.challenge=y');

        $response->assertNotFound();
    }

    // --- Fast response / async processing ---

    public function test_webhook_dispatches_job_and_does_not_process_synchronously(): void
    {
        Queue::fake();
        $channel = Channel::factory()->instagram()->create();

        $response = $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), $this->instagramTextPayload('mid.1001', 'Hello'));

        $response->assertOk();
        Queue::assertPushed(HandleIncomingMessageJob::class, fn ($job) => $job->channel->id === $channel->id);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    // --- Message handling ---

    public function test_incoming_text_message_creates_conversation_and_message_and_replies(): void
    {
        $this->mockGemini('Здравствуйте! Да, есть в наличии.');
        $this->fakeMetaApi();
        $channel = Channel::factory()->instagram()->create();

        $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), $this->instagramTextPayload('mid.2001', 'Есть размер M?'))->assertOk();

        $conversation = Conversation::where('channel_id', $channel->id)->where('external_user_id', '778899')->first();
        $this->assertNotNull($conversation);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Есть размер M?',
            'external_message_id' => 'mid.2001',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Здравствуйте! Да, есть в наличии.',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages') && str_contains($request->url(), '9876543210'));
    }

    public function test_echo_of_own_outgoing_message_is_ignored(): void
    {
        $channel = Channel::factory()->instagram()->create();

        $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), $this->instagramEchoPayload('mid.ECHO1'))->assertOk();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_read_receipt_payload_is_ignored(): void
    {
        $channel = Channel::factory()->instagram()->create();

        $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), $this->instagramReadReceiptPayload())->assertOk();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_duplicate_message_id_is_deduped(): void
    {
        $this->mockGemini();
        $this->fakeMetaApi();
        $channel = Channel::factory()->instagram()->create();
        $payload = $this->instagramTextPayload('mid.3001', 'Привет');

        $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), $payload)->assertOk();
        $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), $payload)->assertOk();

        $this->assertDatabaseCount('conversations', 1);
        $this->assertSame(1, Message::where('external_message_id', 'mid.3001')->count());
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_duplicate_delivery_via_direct_job_replay_produces_exactly_one_reply(): void
    {
        $this->mockGemini('Единственный ответ.');
        $this->fakeMetaApi();
        $channel = Channel::factory()->instagram()->create();
        $payload = $this->instagramTextPayload('mid.7001', 'Здравствуйте');

        app()->call([new HandleIncomingMessageJob($channel, $payload), 'handle']);
        app()->call([new HandleIncomingMessageJob($channel, $payload), 'handle']);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);

        // Excludes sendTypingIndicator, which legitimately posts to the same
        // /messages endpoint with a sender_action payload instead of a text one.
        $sendCalls = 0;
        Http::assertSent(function ($request) use (&$sendCalls) {
            if (str_contains($request->url(), '/messages') && str_contains($request->url(), '9876543210') && data_get($request->data(), 'message.text')) {
                $sendCalls++;
            }

            return true;
        });
        $this->assertSame(1, $sendCalls);
    }

    public function test_unknown_channel_uuid_returns_404(): void
    {
        $response = $this->postJson('/webhooks/instagram/00000000-0000-0000-0000-000000000000', $this->instagramTextPayload('mid.1', 'hi'));

        $response->assertNotFound();
    }

    public function test_channel_type_mismatch_returns_404(): void
    {
        $channel = Channel::factory()->create(); // type: telegram

        $response = $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), $this->instagramTextPayload('mid.1', 'hi'));

        $response->assertNotFound();
    }

    public function test_human_takeover_conversation_stores_message_but_does_not_auto_reply(): void
    {
        $this->fakeMetaApi();
        $channel = Channel::factory()->instagram()->create();
        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '778899',
            'status' => 'human_takeover',
        ]);

        $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), $this->instagramTextPayload('mid.5001', 'Кто-нибудь ответит?'))->assertOk();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Кто-нибудь ответит?',
        ]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id, 'role' => 'assistant']);
        Http::assertNothingSent();
    }

    public function test_paused_agent_does_not_auto_reply(): void
    {
        $this->fakeMetaApi();
        $agent = Agent::factory()->create(['status' => 'paused']);
        $channel = Channel::factory()->instagram()->for($agent)->create();

        $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), $this->instagramTextPayload('mid.6001', 'Здравствуйте'))->assertOk();

        $conversation = Conversation::where('channel_id', $channel->id)->first();
        $this->assertNotNull($conversation);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'role' => 'user']);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id, 'role' => 'assistant']);
        Http::assertNothingSent();
    }
}
