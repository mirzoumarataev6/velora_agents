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

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function whatsAppTextPayload(string $messageId, string $text, string $from = '15550001234', string $name = 'Ivan'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550009999', 'phone_number_id' => '1234567890'],
                        'contacts' => [['profile' => ['name' => $name], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $messageId,
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    protected function whatsAppStatusPayload(): array
    {
        // A delivery-receipt update — has no "messages" key, must be ignored.
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '1234567890'],
                        'statuses' => [['id' => 'wamid.STATUS', 'status' => 'delivered', 'timestamp' => (string) time()]],
                    ],
                    'field' => 'messages',
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
            'graph.facebook.com/*/messages' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT1']]], 200),
        ]);
    }

    // --- GET verification handshake ---

    public function test_get_handshake_echoes_challenge_when_token_matches(): void
    {
        $channel = Channel::factory()->whatsapp()->create();
        $verifyToken = $channel->credentials['verify_token'];

        $response = $this->get(route('webhooks.whatsapp.verify', $channel).'?hub.mode=subscribe&hub.verify_token='.$verifyToken.'&hub.challenge=CHALLENGE_ACCEPTED_123');

        $response->assertOk();
        $this->assertSame('CHALLENGE_ACCEPTED_123', $response->getContent());
    }

    public function test_get_handshake_rejects_wrong_verify_token(): void
    {
        $channel = Channel::factory()->whatsapp()->create();

        $response = $this->get(route('webhooks.whatsapp.verify', $channel).'?hub.mode=subscribe&hub.verify_token=totally-wrong&hub.challenge=SHOULD_NOT_BE_ECHOED');

        $response->assertForbidden();
        $this->assertNotSame('SHOULD_NOT_BE_ECHOED', $response->getContent());
    }

    public function test_get_handshake_on_unknown_channel_returns_404(): void
    {
        $response = $this->get('/webhooks/whatsapp/00000000-0000-0000-0000-000000000000?hub.mode=subscribe&hub.verify_token=x&hub.challenge=y');

        $response->assertNotFound();
    }

    // --- Fast response / async processing ---

    public function test_webhook_dispatches_job_and_does_not_process_synchronously(): void
    {
        Queue::fake();
        $channel = Channel::factory()->whatsapp()->create();

        $response = $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $this->whatsAppTextPayload('wamid.1001', 'Hello'));

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
        $channel = Channel::factory()->whatsapp()->create();

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $this->whatsAppTextPayload('wamid.2001', 'Есть размер M?'))->assertOk();

        $conversation = Conversation::where('channel_id', $channel->id)->where('external_user_id', '15550001234')->first();
        $this->assertNotNull($conversation);
        $this->assertSame('Ivan', $conversation->external_user_name);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Есть размер M?',
            'external_message_id' => 'wamid.2001',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Здравствуйте! Да, есть в наличии.',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages') && str_contains($request->url(), '1234567890'));
    }

    public function test_status_update_payload_is_ignored(): void
    {
        $channel = Channel::factory()->whatsapp()->create();

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $this->whatsAppStatusPayload())->assertOk();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_duplicate_message_id_is_deduped(): void
    {
        $this->mockGemini();
        $this->fakeMetaApi();
        $channel = Channel::factory()->whatsapp()->create();
        $payload = $this->whatsAppTextPayload('wamid.3001', 'Привет');

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $payload)->assertOk();
        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $payload)->assertOk();

        $this->assertDatabaseCount('conversations', 1);
        $this->assertSame(1, Message::where('external_message_id', 'wamid.3001')->count());
        $this->assertDatabaseCount('messages', 2); // one user + one assistant only
    }

    public function test_duplicate_delivery_via_direct_job_replay_produces_exactly_one_reply(): void
    {
        // Same race-condition regression coverage as TelegramWebhookTest: proves
        // the DB unique constraint (not just the fast-path SELECT) is what
        // guarantees at-most-one reply even if the job runs twice for one update.
        $this->mockGemini('Единственный ответ.');
        $this->fakeMetaApi();
        $channel = Channel::factory()->whatsapp()->create();
        $payload = $this->whatsAppTextPayload('wamid.7001', 'Здравствуйте');

        app()->call([new HandleIncomingMessageJob($channel, $payload), 'handle']);
        app()->call([new HandleIncomingMessageJob($channel, $payload), 'handle']);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);

        $sendCalls = 0;
        Http::assertSent(function ($request) use (&$sendCalls) {
            if (str_contains($request->url(), '/messages') && str_contains($request->url(), '1234567890')) {
                $sendCalls++;
            }

            return true;
        });
        $this->assertSame(1, $sendCalls);
    }

    public function test_unknown_channel_uuid_returns_404(): void
    {
        $response = $this->postJson('/webhooks/whatsapp/00000000-0000-0000-0000-000000000000', $this->whatsAppTextPayload('wamid.1', 'hi'));

        $response->assertNotFound();
    }

    public function test_channel_type_mismatch_returns_404(): void
    {
        $channel = Channel::factory()->create(); // type: telegram

        $response = $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $this->whatsAppTextPayload('wamid.1', 'hi'));

        $response->assertNotFound();
    }

    public function test_human_takeover_conversation_stores_message_but_does_not_auto_reply(): void
    {
        $this->fakeMetaApi();
        $channel = Channel::factory()->whatsapp()->create();
        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '15550001234',
            'status' => 'human_takeover',
        ]);

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $this->whatsAppTextPayload('wamid.5001', 'Кто-нибудь ответит?'))->assertOk();

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
        $channel = Channel::factory()->whatsapp()->for($agent)->create();

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $this->whatsAppTextPayload('wamid.6001', 'Здравствуйте'))->assertOk();

        $conversation = Conversation::where('channel_id', $channel->id)->first();
        $this->assertNotNull($conversation);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'role' => 'user']);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id, 'role' => 'assistant']);
        Http::assertNothingSent();
    }
}
