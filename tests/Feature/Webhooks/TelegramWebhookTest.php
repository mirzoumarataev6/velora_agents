<?php

namespace Tests\Feature\Webhooks;

use App\Exceptions\LlmException;
use App\Jobs\HandleIncomingMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function telegramUpdate(int $updateId, string $text, int $userId = 555): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => $userId, 'first_name' => 'Ivan', 'username' => 'ivan123'],
                'chat' => ['id' => $userId, 'type' => 'private'],
                'date' => time(),
                'text' => $text,
            ],
        ];
    }

    protected function postWebhook(Channel $channel, array $payload, ?string $secret = null)
    {
        $secret ??= $channel->settings['webhook_secret'];

        return $this->postJson(
            route('webhooks.telegram', $channel),
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => $secret]
        );
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

    protected function fakeTelegramApi(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    // --- Requirement 2: webhook responds fast, all processing deferred ---

    public function test_webhook_dispatches_job_and_does_not_process_synchronously(): void
    {
        Queue::fake();
        $channel = Channel::factory()->create();

        $response = $this->postWebhook($channel, $this->telegramUpdate(1001, 'Hello'));

        $response->assertOk();
        Queue::assertPushed(HandleIncomingMessageJob::class, fn ($job) => $job->channel->id === $channel->id);

        // Nothing should have been written to the DB — that only happens once the job runs.
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    // --- Requirement 1: various scenarios, job run synchronously (sync queue driver) ---

    public function test_incoming_message_creates_conversation_and_message_and_replies(): void
    {
        $this->mockGemini('Здравствуйте! Да, есть в наличии.');
        $this->fakeTelegramApi();
        $channel = Channel::factory()->create();

        $this->postWebhook($channel, $this->telegramUpdate(2001, 'Есть размер M?'))->assertOk();

        $conversation = Conversation::where('channel_id', $channel->id)->where('external_user_id', '555')->first();
        $this->assertNotNull($conversation);
        $this->assertSame('Ivan', $conversation->external_user_name);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Есть размер M?',
            'external_message_id' => '2001',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Здравствуйте! Да, есть в наличии.',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    public function test_duplicate_update_id_is_ignored(): void
    {
        $this->mockGemini();
        $this->fakeTelegramApi();
        $channel = Channel::factory()->create();

        $this->postWebhook($channel, $this->telegramUpdate(3001, 'Привет'))->assertOk();
        $this->postWebhook($channel, $this->telegramUpdate(3001, 'Привет'))->assertOk();

        $this->assertDatabaseCount('conversations', 1);
        $this->assertSame(1, Message::where('external_message_id', '3001')->count());
        // One user + one assistant message total — the duplicate produced nothing.
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_duplicate_delivery_produces_exactly_one_reply(): void
    {
        // Regression test for a live bug report: a single customer message
        // produced two replies. Root cause analysis found no exact duplicate
        // in production (every external_message_id was unique), but the
        // architecture had a real latent race — a queue worker timeout could
        // kill and retry a slow-but-still-running job (e.g. a photo needing
        // Vision + embedding + generation), and the app-level "already
        // processed?" SELECT check alone doesn't close that window. This
        // proves the DB-level unique constraint does: even if the SAME job
        // is executed twice in a row for the same update (redelivery, or a
        // retry after the first attempt already finished), only one reply
        // is ever sent.
        $this->mockGemini('Единственный ответ.');
        $this->fakeTelegramApi();
        $channel = Channel::factory()->create();
        $payload = $this->telegramUpdate(7001, 'Здравствуйте');

        $job1 = new HandleIncomingMessageJob($channel, $payload);
        app()->call([$job1, 'handle']);

        $job2 = new HandleIncomingMessageJob($channel, $payload);
        app()->call([$job2, 'handle']);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2); // exactly one user + one assistant message
        $this->assertSame(1, Message::where('external_message_id', '7001')->count());

        $sendCalls = 0;
        Http::assertSent(function ($request) use (&$sendCalls) {
            if (str_contains($request->url(), 'sendMessage')) {
                $sendCalls++;
            }

            return true;
        });
        $this->assertSame(1, $sendCalls);
    }

    public function test_messages_table_enforces_unique_external_message_id_per_conversation(): void
    {
        $channel = Channel::factory()->create();
        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '999',
            'status' => 'ai',
        ]);

        $conversation->messages()->create(['role' => 'user', 'content' => 'first', 'external_message_id' => 'dup-1']);

        $this->expectException(UniqueConstraintViolationException::class);
        $conversation->messages()->create(['role' => 'user', 'content' => 'second', 'external_message_id' => 'dup-1']);
    }

    public function test_unknown_channel_uuid_returns_404(): void
    {
        $response = $this->postJson('/webhooks/telegram/00000000-0000-0000-0000-000000000000', $this->telegramUpdate(1, 'hi'));

        $response->assertNotFound();
    }

    public function test_wrong_secret_token_is_rejected(): void
    {
        $channel = Channel::factory()->create();

        $response = $this->postWebhook($channel, $this->telegramUpdate(4001, 'hi'), secret: 'wrong-secret');

        $response->assertForbidden();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_human_takeover_conversation_stores_message_but_does_not_auto_reply(): void
    {
        $this->fakeTelegramApi();
        $channel = Channel::factory()->create();
        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '555',
            'status' => 'human_takeover',
        ]);

        $this->postWebhook($channel, $this->telegramUpdate(5001, 'Кто-нибудь ответит?'))->assertOk();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Кто-нибудь ответит?',
        ]);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
        ]);
        Http::assertNothingSent();
    }

    public function test_paused_agent_does_not_auto_reply(): void
    {
        $this->fakeTelegramApi();
        $agent = Agent::factory()->create(['status' => 'paused']);
        $channel = Channel::factory()->for($agent)->create();

        $this->postWebhook($channel, $this->telegramUpdate(6001, 'Здравствуйте'))->assertOk();

        $conversation = Conversation::where('channel_id', $channel->id)->first();
        $this->assertNotNull($conversation);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'role' => 'user']);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id, 'role' => 'assistant']);
        Http::assertNothingSent();
    }

    public function test_unclear_photo_routes_to_short_clarifying_question_not_full_rag(): void
    {
        $this->fakeTelegramApi();
        $this->mock(LlmProviderInterface::class, function ($mock) {
            // Vision call (inside describePhoto) — unconfident.
            $mock->shouldReceive('extractFromImage')->andReturn(['text' => 'UNKNOWN', 'input_tokens' => 5, 'output_tokens' => 1]);
            // askForProductName's single generate() call — no embed()/search() should ever run for this path.
            $mock->shouldReceive('generate')->once()->andReturn([
                'text' => 'Подскажите, пожалуйста, название товара?', 'input_tokens' => 20, 'output_tokens' => 8, 'finish_reason' => 'STOP',
            ]);
            $mock->shouldNotReceive('embed');
        });

        $channel = Channel::factory()->create();
        $payload = [
            'update_id' => 8001,
            'message' => [
                'from' => ['id' => 555, 'first_name' => 'Ivan'],
                'chat' => ['id' => 555],
                'photo' => [['file_id' => 'abc', 'file_size' => 100]],
            ],
        ];

        $this->postWebhook($channel, $payload)->assertOk();

        $conversation = Conversation::where('channel_id', $channel->id)->first();
        $assistant = Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->first();

        $this->assertNotNull($assistant);
        $this->assertSame('Подскажите, пожалуйста, название товара?', $assistant->content);
        $this->assertTrue($assistant->meta['unclear_photo'] ?? false);
        $this->assertSame([], $assistant->meta['retrieved_chunks']);
    }

    public function test_second_consecutive_gemini_failure_is_suppressed_but_message_is_still_stored(): void
    {
        // Regression test for a live report: 5 consecutive Gemini failures (a
        // daily quota exhaustion) each sent the customer an identical canned
        // fallback within seconds of each other, reading like the bot was
        // stuck in a loop. The first failure should still reply once; every
        // failure after it within the suppression window should stay silent.
        $this->fakeTelegramApi();
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andThrow(new LlmException('quota exceeded'));
        });

        $channel = Channel::factory()->create();

        $this->postWebhook($channel, $this->telegramUpdate(9001, 'Есть платье?'))->assertOk();
        $this->postWebhook($channel, $this->telegramUpdate(9002, 'Ок'))->assertOk();
        $this->postWebhook($channel, $this->telegramUpdate(9003, 'Привет'))->assertOk();

        $conversation = Conversation::where('channel_id', $channel->id)->first();

        // All three customer messages are stored...
        $this->assertSame(3, Message::where('conversation_id', $conversation->id)->where('role', 'user')->count());
        // ...but only the FIRST failure produced a reply.
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->count());

        $sendCalls = 0;
        Http::assertSent(function ($request) use (&$sendCalls) {
            if (str_contains($request->url(), 'sendMessage')) {
                $sendCalls++;
            }

            return true;
        });
        $this->assertSame(1, $sendCalls);
    }
}
