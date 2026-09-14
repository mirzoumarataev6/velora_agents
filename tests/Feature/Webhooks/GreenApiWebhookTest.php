<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Llm\LlmProviderInterface;
use Database\Factories\ChannelFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GreenApiWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Green API's documented (flat) incomingMessageReceived notification. */
    protected function payload(string $idMessage = 'BAE5F4886F1A9', string $text = 'Hello', string|int $idInstance = 1101000001, string $type = 'incomingMessageReceived'): array
    {
        return [
            'typeWebhook' => $type,
            'instanceData' => ['idInstance' => $idInstance, 'wid' => '79990000000@c.us', 'typeInstance' => 'whatsapp'],
            'timestamp' => time(),
            'idMessage' => $idMessage,
            'senderData' => ['chatId' => '79001234567@c.us', 'sender' => '79001234567@c.us', 'senderName' => 'Ivan'],
            'messageData' => ['typeMessage' => 'textMessage', 'textMessageData' => ['textMessage' => $text]],
        ];
    }

    protected function postWebhook(Channel $channel, array $payload, ?string $token = ChannelFactory::GREEN_API_WEBHOOK_TOKEN): TestResponse
    {
        return $this->postJson(route('webhooks.greenapi', $channel), $payload, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
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

    public function test_get_returns_200_without_any_handshake(): void
    {
        $channel = Channel::factory()->greenApi()->create();

        $this->get(route('webhooks.greenapi.verify', $channel))->assertOk();
    }

    public function test_incoming_message_dispatches_job_and_returns_immediately(): void
    {
        Queue::fake();
        $channel = Channel::factory()->greenApi()->create();

        $this->postWebhook($channel, $this->payload())->assertOk();

        Queue::assertPushed(HandleIncomingMessageJob::class, fn ($job) => $job->channel->id === $channel->id);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_id_instance_mismatch_returns_403(): void
    {
        Queue::fake();
        $channel = Channel::factory()->greenApi()->create();

        $this->postWebhook($channel, $this->payload(idInstance: 9999999999))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_missing_or_wrong_webhook_token_returns_403_once_webhook_was_auto_registered(): void
    {
        Queue::fake();
        $channel = Channel::factory()->greenApi()->create();

        $this->postWebhook($channel, $this->payload(), token: null)->assertForbidden();
        $this->postWebhook($channel, $this->payload(), token: 'wrong-token')->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_id_instance_alone_is_accepted_when_webhook_was_set_manually(): void
    {
        Queue::fake();
        $channel = Channel::factory()->greenApi()->create([
            'settings' => ['webhook_registered' => false, 'webhook_error' => 'HTTP 500'],
        ]);

        $this->postWebhook($channel, $this->payload(), token: null)->assertOk();

        Queue::assertPushed(HandleIncomingMessageJob::class);
    }

    public function test_non_message_notifications_are_acknowledged_without_a_job(): void
    {
        Queue::fake();
        $channel = Channel::factory()->greenApi()->create();

        $this->postWebhook($channel, $this->payload(type: 'outgoingMessageStatus'))->assertOk();
        $this->postWebhook($channel, $this->payload(type: 'stateInstanceChanged'))->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_channel_type_mismatch_returns_404(): void
    {
        $channel = Channel::factory()->whatsapp()->create();

        $this->postWebhook($channel, $this->payload())->assertNotFound();
        $this->get(route('webhooks.greenapi.verify', $channel))->assertNotFound();
    }

    public function test_incoming_text_message_is_stored_and_answered_via_send_message(): void
    {
        $this->mockGemini('Здравствуйте! Да, есть в наличии.');
        Http::fake(['*/sendMessage/*' => Http::response(['idMessage' => 'OUT-1'], 200)]);
        $channel = Channel::factory()->greenApi()->create();

        $this->postWebhook($channel, $this->payload('BAE5-1', 'Есть размер M?'))->assertOk();

        $conversation = Conversation::where('channel_id', $channel->id)->where('external_user_id', '79001234567')->first();
        $this->assertNotNull($conversation);
        $this->assertSame('Ivan', $conversation->external_user_name);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Есть размер M?',
            'external_message_id' => 'BAE5-1',
        ]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'role' => 'assistant']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/waInstance1101000001/sendMessage/')
            && $request['chatId'] === '79001234567@c.us');
    }

    public function test_duplicate_id_message_is_answered_once(): void
    {
        $this->mockGemini();
        Http::fake(['*/sendMessage/*' => Http::response(['idMessage' => 'OUT-1'], 200)]);
        $channel = Channel::factory()->greenApi()->create();

        $this->postWebhook($channel, $this->payload('BAE5-DUP', 'Привет'))->assertOk();
        $this->postWebhook($channel, $this->payload('BAE5-DUP', 'Привет'))->assertOk();

        $this->assertSame(1, Message::where('external_message_id', 'BAE5-DUP')->count());
        $this->assertDatabaseCount('messages', 2);
    }
}
