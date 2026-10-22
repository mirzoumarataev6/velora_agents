<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Reproduces the WhatsApp (Green API) outage where a customer wrote and got
 * silence: a webhook that is a genuine redelivery of the same message (Green
 * API varies fields like `timestamp` between deliveries, so hashing the
 * whole payload is not a safe dedup key), a conversation left in
 * `human_takeover` from an earlier, unrelated escalation, and a channel send
 * failure that used to be swallowed instead of retried.
 */
class GreenApiReplyReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function channel(): Channel
    {
        return Channel::factory()->greenApi()->create();
    }

    /** Green API's documented (flat) incomingMessageReceived notification. */
    protected function payload(
        string $idMessage = 'BAE5F4886F1A9',
        string $text = 'Здравствуйте',
        int $timestamp = 0,
    ): array {
        return [
            'typeWebhook' => 'incomingMessageReceived',
            'instanceData' => ['idInstance' => 1101000001, 'wid' => '79990000000@c.us', 'typeInstance' => 'whatsapp'],
            'timestamp' => $timestamp ?: time(),
            'idMessage' => $idMessage,
            'senderData' => ['chatId' => '79001234567@c.us', 'sender' => '79001234567@c.us', 'senderName' => 'Ivan'],
            'messageData' => ['typeMessage' => 'textMessage', 'textMessageData' => ['textMessage' => $text]],
        ];
    }

    protected function mockLlm(string $reply = 'Здравствуйте! Чем помочь?'): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($reply) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturn([
                'text' => $reply, 'input_tokens' => 10, 'output_tokens' => 5, 'finish_reason' => 'STOP', 'tool_calls' => [],
            ]);
        });
    }

    /** Outbound texts the driver actually sent via Green API. */
    protected function sentMessages(): array
    {
        $sent = [];

        Http::assertSent(function ($request) use (&$sent) {
            if (str_contains($request->url(), '/sendMessage/')) {
                $sent[] = $request['message'];
            }

            return true;
        });

        return $sent;
    }

    protected function jobDependencies(): array
    {
        $parameters = (new \ReflectionMethod(HandleIncomingMessageJob::class, 'handle'))->getParameters();

        return array_map(fn (\ReflectionParameter $p) => app($p->getType()?->getName()), $parameters);
    }

    protected function queuedAttempt(int $attempts): Job
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->andReturn($attempts);
        $job->shouldIgnoreMissing();

        return $job;
    }

    // --- (a) a first message always gets a reply ---------------------------

    public function test_a_first_message_is_stored_and_answered(): void
    {
        $this->mockLlm('Здравствуйте! Да, есть в наличии.');
        Http::fake(['*/sendMessage/*' => Http::response(['idMessage' => 'OUT-1'], 200)]);

        $channel = $this->channel();

        (new HandleIncomingMessageJob($channel, $this->payload('BAE5-1', 'Есть размер M?')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('sent', $reply->meta['delivery_status']);
        $this->assertSame([$reply->content], $this->sentMessages());
    }

    // --- (b) a message after a completed/escalated conversation reopens it -

    /**
     * The actual incident: the previous conversation ended in human_takeover
     * (triggered, e.g., by an earlier escalation around an order). Weeks
     * later the same customer writes again — they must get a reply, not
     * permanent silence.
     */
    public function test_a_new_message_after_human_takeover_reopens_the_conversation_and_is_answered(): void
    {
        $this->mockLlm('Здравствуйте! Чем можем помочь на этот раз?');
        Http::fake(['*/sendMessage/*' => Http::response(['idMessage' => 'OUT-2'], 200)]);

        $channel = $this->channel();
        $conversation = Conversation::factory()->humanTakeover()->create([
            'agent_id' => $channel->agent_id,
            'channel_id' => $channel->id,
            'external_user_id' => '79001234567',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Спасибо! Мы вам позвоним для подтверждения заказа.',
            'external_message_id' => null,
        ]);

        (new HandleIncomingMessageJob($channel, $this->payload('BAE5-2', 'Здравствуйте')))
            ->handle(...$this->jobDependencies());

        $this->assertSame('ai', $conversation->refresh()->status);
        $this->assertSame(1, Message::where('role', 'assistant')->where('conversation_id', $conversation->id)
            ->where('content', '!=', 'Спасибо! Мы вам позвоним для подтверждения заказа.')->count());
        $this->assertNotEmpty($this->sentMessages());
    }

    public function test_an_agent_paused_by_its_owner_still_does_not_reply(): void
    {
        $this->mockLlm();
        Http::fake(['*/sendMessage/*' => Http::response(['idMessage' => 'OUT-3'], 200)]);

        $channel = $this->channel();
        $channel->agent->update(['status' => 'paused']);

        (new HandleIncomingMessageJob($channel, $this->payload('BAE5-3', 'Здравствуйте')))
            ->handle(...$this->jobDependencies());

        $this->assertSame(0, Message::where('role', 'assistant')->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendMessage/'));
    }

    // --- (c) a genuinely redelivered webhook must not be answered twice ----

    /**
     * Green API varies fields such as `timestamp` between a delivery and its
     * redelivery of the SAME message. Hashing the whole payload as the dedup
     * key (the old behaviour) treats these as different webhooks; dedup must
     * key on `idMessage`, which does not change.
     */
    public function test_a_redelivered_webhook_with_a_different_timestamp_is_answered_once(): void
    {
        $this->mockLlm();
        Http::fake(['*/sendMessage/*' => Http::response(['idMessage' => 'OUT-4'], 200)]);

        $channel = $this->channel();

        (new HandleIncomingMessageJob($channel, $this->payload('BAE5-DUP', 'Привет', timestamp: 1000)))
            ->handle(...$this->jobDependencies());
        (new HandleIncomingMessageJob($channel, $this->payload('BAE5-DUP', 'Привет', timestamp: 1007)))
            ->handle(...$this->jobDependencies());

        $this->assertSame(1, Message::where('role', 'user')->where('external_message_id', 'BAE5-DUP')->count());
        $this->assertSame(1, Message::where('role', 'assistant')->count());
        $this->assertCount(1, $this->sentMessages());
    }

    // --- (d) only genuine incoming-message webhooks are processed ----------

    public function test_a_status_notification_produces_no_message_and_no_send(): void
    {
        $channel = $this->channel();

        $payload = ['typeWebhook' => 'outgoingMessageStatus'] + $this->payload();

        (new HandleIncomingMessageJob($channel, $payload))->handle(...$this->jobDependencies());

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    // --- (e) a Green API delivery failure retries and is marked failed -----

    public function test_a_channel_rejection_is_retried_and_marks_the_message_failed(): void
    {
        $this->mockLlm();
        Http::fakeSequence('*/sendMessage/*')
            ->push(['message' => 'bad request'], 400)
            ->push(['idMessage' => 'OUT-5'], 200);

        $channel = $this->channel();
        $payload = $this->payload('BAE5-5', 'Здравствуйте');

        $job = new HandleIncomingMessageJob($channel, $payload);

        try {
            $job->handle(...$this->jobDependencies());
            $this->fail('a rejected send must propagate so the queue retries it');
        } catch (RuntimeException) {
            // Expected — this is what sends the job back for a retry.
        }

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('failed', $reply->meta['delivery_status']);
        $this->assertNotEmpty($reply->meta['delivery_error']);

        // A retry (same idMessage, higher attempt count) must still try to
        // deliver — the failed row must not look like "already answered".
        $retry = new HandleIncomingMessageJob($channel, $payload);
        $retry->setJob($this->queuedAttempt(2));
        $retry->handle(...$this->jobDependencies());

        $this->assertSame(1, Message::where('role', 'user')->count());
        $this->assertNotEmpty($this->sentMessages());
    }

    public function test_a_permanently_failed_job_apologises_and_alerts_the_owner(): void
    {
        $this->mockLlm();
        Http::fake(['*/sendMessage/*' => Http::response(['message' => 'bad request'], 400)]);

        $channel = $this->channel();
        $channel->agent->update(['notify_telegram_chat_id' => null]);
        $payload = $this->payload('BAE5-6', 'Здравствуйте');

        $job = new HandleIncomingMessageJob($channel, $payload);

        try {
            $job->handle(...$this->jobDependencies());
            $this->fail('the send failure must propagate');
        } catch (RuntimeException $e) {
            // All retries spent — the queue now calls failed() on the same turn.
            Http::fake(['*/sendMessage/*' => Http::response(['idMessage' => 'OUT-6'], 200)]);
            $job->failed($e);
        }

        $this->assertStringContainsString('техническая ошибка', implode(' ', $this->sentMessages()));
    }
}
