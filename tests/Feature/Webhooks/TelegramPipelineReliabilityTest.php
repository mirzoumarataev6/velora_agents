<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use App\Models\Message;
use App\Services\AgentReplyService;
use App\Services\Channels\WikimediaImageSearch;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The failure modes behind the intermittent Telegram outages, each reproduced.
 *
 * Every case here comes from a real exception in the log rather than from a
 * theory about what might go wrong: a schema change that had not been migrated
 * (`Unknown column 'phone'`), a worker still holding a superseded class (`Call
 * to undefined method ...::lastAssistantMessage()`), and the redelivered
 * webhooks Telegram sends when it does not get its 200 in time.
 */
class TelegramPipelineReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function channel(): Channel
    {
        return Channel::factory()->create([
            'type' => 'telegram',
            'status' => 'connected',
            'credentials' => ['bot_token' => 'FAKE-TOKEN'],
            'settings' => ['webhook_secret' => 'sekret'],
        ]);
    }

    protected function update(int $updateId, string $text = 'Есть игровые ноутбуки?', int $userId = 555): array
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

    protected function fakeTelegramApi(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    protected function mockLlm(string $reply = 'Есть OMEN 16 за 14999 сомони.'): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($reply) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturn([
                'text' => $reply, 'input_tokens' => 10, 'output_tokens' => 5,
                'finish_reason' => 'STOP', 'tool_calls' => [],
            ]);
        });
    }

    /** Outbound messages the driver actually sent. */
    protected function sentMessages(): array
    {
        $sent = [];

        Http::assertSent(function ($request) use (&$sent) {
            if (str_contains($request->url(), 'sendMessage')) {
                $sent[] = $request['text'];
            }

            return true;
        });

        return $sent;
    }

    // --- the webhook itself --------------------------------------------------

    /**
     * Telegram redelivers anything it does not see acknowledged quickly, so the
     * handler must do nothing but authenticate and enqueue.
     */
    public function test_the_webhook_acknowledges_immediately_and_does_no_work_itself(): void
    {
        Queue::fake();

        $channel = $this->channel();

        $this->postJson(
            route('webhooks.telegram', $channel),
            $this->update(1),
            ['X-Telegram-Bot-Api-Secret-Token' => 'sekret'],
        )->assertOk();

        Queue::assertPushed(HandleIncomingMessageJob::class);
        // Nothing was written and nothing was generated on the request thread.
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    /**
     * The same update delivered twice must produce one conversation, one stored
     * message and — the part the customer sees — one reply.
     */
    public function test_a_redelivered_update_is_processed_once(): void
    {
        $this->fakeTelegramApi();
        $this->mockLlm();

        $channel = $this->channel();
        $payload = $this->update(4242);

        (new HandleIncomingMessageJob($channel, $payload))->handle(...$this->jobDependencies());
        (new HandleIncomingMessageJob($channel, $payload))->handle(...$this->jobDependencies());

        $this->assertSame(1, Message::where('role', 'user')->count());
        $this->assertSame(1, Message::where('role', 'assistant')->count());
        $this->assertCount(1, $this->sentMessages());
    }

    public function test_photo_request_reports_no_verified_image_without_sending_a_similar_named_person(): void
    {
        $this->fakeTelegramApi();
        $this->mockLlm('К сожалению, я не могу отправить фотографии. Посмотрите на сайте.');
        $this->mock(WikimediaImageSearch::class)
            ->shouldReceive('search')->once()->with('OMEN 16', 1, 'laptop')->andReturn([]);

        $channel = $this->channel();
        $channel->agent->update(['behavior' => ['internet_image_search' => true]]);

        (new HandleIncomingMessageJob($channel, $this->update(6001, 'Покажи фото ноутбука OMEN 16')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('missing', $reply->meta['photo_status']);
        $this->assertStringContainsString('OMEN 16', $reply->content);
        $this->assertStringContainsString('Пока нет фото', $reply->content);
        $this->assertSame([$reply->content], $this->sentMessages());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sendPhoto'));
    }

    public function test_photo_request_reports_success_only_after_telegram_accepts_the_image(): void
    {
        $this->fakeTelegramApi();
        $this->mockLlm('К сожалению, я не могу отправить фотографии.');
        $this->mock(WikimediaImageSearch::class)
            ->shouldReceive('search')->once()->with('OMEN 16', 1, 'laptop')->andReturn([
                ['url' => 'https://thumb.wikimedia.org/hp-omen-16.jpg', 'caption' => 'CC BY-SA'],
            ]);

        $channel = $this->channel();
        $channel->agent->update(['behavior' => ['internet_image_search' => true]]);

        (new HandleIncomingMessageJob($channel, $this->update(6002, 'Покажи фото ноутбука OMEN 16')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('sent', $reply->meta['photo_status']);
        $this->assertSame('Фото OMEN 16 отправлено.', $reply->content);
        $this->assertSame([$reply->content], $this->sentMessages());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendPhoto')
            && $request['photo'] === 'https://thumb.wikimedia.org/hp-omen-16.jpg');
    }

    public function test_internet_outage_is_not_presented_as_no_photo_exists(): void
    {
        $this->fakeTelegramApi();
        $this->mock(LlmProviderInterface::class)->shouldNotReceive('generate');
        $this->mock(WikimediaImageSearch::class)->shouldReceive('search')->once()
            ->andThrow(new RuntimeException('Search unavailable'));
        $channel = $this->channel();
        $channel->agent->update(['behavior' => ['internet_image_search' => true]]);
        (new HandleIncomingMessageJob($channel, $this->update(6040, 'Покажи фото ноутбука OMEN 16')))
            ->handle(...$this->jobDependencies());
        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('failed', $reply->meta['photo_status']);
        $this->assertStringContainsString('Попробуйте ещё раз', $reply->content);
        $this->assertStringNotContainsString('Пока нет фото', $reply->content);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendPhoto'));
    }

    public function test_telegram_rejection_never_produces_a_success_confirmation(): void
    {
        Http::fake([
            'api.telegram.org/*/sendPhoto' => Http::response(['ok' => false, 'description' => 'Bad Request: wrong file'], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        $this->mock(LlmProviderInterface::class)->shouldNotReceive('generate');
        $this->mock(WikimediaImageSearch::class)->shouldReceive('search')->once()->andReturn([
            ['url' => 'https://thumb.wikimedia.org/hp-omen-16.jpg', 'caption' => 'CC BY-SA'],
        ]);
        $channel = $this->channel();
        $channel->agent->update(['behavior' => ['internet_image_search' => true]]);
        (new HandleIncomingMessageJob($channel, $this->update(6041, 'Покажи фото ноутбука OMEN 16')))
            ->handle(...$this->jobDependencies());
        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('failed', $reply->meta['photo_status']);
        $this->assertSame(0, $reply->meta['photo_count']);
        $this->assertStringNotContainsString('отправлено', $reply->content);
    }

    public function test_switching_from_omen_to_unspecified_macbook_asks_which_model(): void
    {
        $this->fakeTelegramApi();
        $this->mockLlm('К сожалению, фото не нашлось.');
        $this->mock(WikimediaImageSearch::class)
            ->shouldReceive('search')->once()->with('OMEN 16', 1, 'laptop')->andReturn([]);

        $channel = $this->channel();
        $channel->agent->update(['behavior' => ['internet_image_search' => true]]);

        (new HandleIncomingMessageJob($channel, $this->update(6003, 'Покажи фото ноутбука OMEN 16')))
            ->handle(...$this->jobDependencies());
        (new HandleIncomingMessageJob($channel, $this->update(6004, 'А тогда покажи как выглядит макбук')))
            ->handle(...$this->jobDependencies());

        $replies = Message::where('role', 'assistant')->orderBy('id')->get();
        $this->assertCount(2, $replies);
        $this->assertSame('missing', $replies[0]->meta['photo_status']);
        $this->assertSame('clarify', $replies[1]->meta['photo_status']);
        $this->assertStringContainsString('Какую именно модель', $replies[1]->content);
        $this->assertStringNotContainsString('этой модели', $replies[1]->content);
        $this->assertSame([$replies[0]->content, $replies[1]->content], $this->sentMessages());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sendPhoto'));
    }

    public function test_photo_conversation_retains_the_list_through_clarification_and_bare_model_answers(): void
    {
        $this->fakeTelegramApi();
        $listing = "Вот модели MacBook:\n"
            ."1. Macbook Neo 13 — 8199 сомони, 256GB.\n"
            ."2. Macbook Neo 13 — 8900 сомони, 512GB.\n"
            ."3. Macbook Air 15 — 11999 сомони.\n"
            ."4. Macbook Air 13.6 — 10499 сомони.\n\n"
            .'К сожалению, я не могу показать фотографии этих моделей. Вы можете увидеть их внешний вид на нашем сайте pixelshop.tj.';
        $this->mock(LlmProviderInterface::class, function ($mock) use ($listing) {
            $mock->shouldReceive('embed')->once()->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->once()->andReturn([
                'text' => $listing, 'input_tokens' => 10, 'output_tokens' => 5,
                'finish_reason' => 'STOP', 'tool_calls' => [],
            ]);
        });
        $this->mock(WikimediaImageSearch::class)->shouldReceive('search')->twice()
            ->with('Macbook Neo 13', 1, 'laptop')->andReturn([
                ['url' => 'https://thumb.wikimedia.org/macbook-neo-13.jpg', 'caption' => 'CC BY-SA'],
            ]);
        $channel = $this->channel();
        $channel->agent->update(['behavior' => ['internet_image_search' => true]]);

        foreach ([
            'Можешь показать макбук который у вас есть',
            'А как они выглядят',
            'фото первого',
            'А как они выглядят',
            'Macbook Neo 13',
        ] as $index => $text) {
            (new HandleIncomingMessageJob($channel, $this->update(7000 + $index, $text)))
                ->handle(...$this->jobDependencies());
        }

        $replies = Message::where('role', 'assistant')->orderBy('id')->get();
        $this->assertCount(5, $replies);
        $this->assertStringNotContainsString('не могу', $replies[0]->content);
        $this->assertStringNotContainsString('pixelshop', $replies[0]->content);
        $this->assertSame('clarify', $replies[1]->meta['photo_status']);
        $this->assertCount(4, $replies[1]->meta['photo_choices']);
        $this->assertStringContainsString('4. Macbook Air 13.6', $replies[1]->content);
        foreach ([2, 4] as $index) {
            $this->assertSame('sent', $replies[$index]->meta['photo_status']);
            $this->assertSame('Macbook Neo 13', $replies[$index]->meta['photo_model']);
            $this->assertSame('Фото Macbook Neo 13 отправлено.', $replies[$index]->content);
        }
        $photos = Http::recorded(fn ($r) => str_contains($r->url(), '/sendPhoto'));
        $this->assertCount(2, $photos);
    }

    // --- retries -------------------------------------------------------------

    /**
     * The bug that made every retry useless.
     *
     * An attempt that stored the customer's message and then failed left the row
     * behind; the dedup check saw it, returned early, and the retry reported
     * success without answering anyone. Reproduced with the real shape of the
     * production failure: the reply generation throws on the first attempt.
     */
    public function test_a_retry_finishes_a_turn_whose_first_attempt_failed_after_storing_the_message(): void
    {
        $this->fakeTelegramApi();
        $this->mockLlm();

        $channel = $this->channel();
        $payload = $this->update(77);

        // First attempt: the message is stored, then generation blows up the
        // way the un-migrated `phone` column did.
        $exploding = $this->mock(AgentReplyService::class, function ($mock) {
            $mock->shouldReceive('shouldSuppressReply')->andReturn(false);
            $mock->shouldReceive('generateReplyFor')->once()
                ->andThrow(new RuntimeException("Unknown column 'phone' in 'field list'"));
        });

        $job = new HandleIncomingMessageJob($channel, $payload);

        try {
            $job->handle(...$this->jobDependencies($exploding));
            $this->fail('the first attempt should have thrown');
        } catch (RuntimeException) {
            // Expected: this is what sends the job back for a retry.
        }

        // The customer's message survived the failure, unanswered.
        $this->assertSame(1, Message::where('role', 'user')->count());
        $this->assertSame(0, Message::where('role', 'assistant')->count());

        // The retry — a fresh instance of the same job, as the queue would run
        // it — must pick the turn up rather than skip it.
        $this->app->forgetInstance(AgentReplyService::class);
        $this->mockLlm();

        $retry = new HandleIncomingMessageJob($channel, $payload);
        // A retry carries the same update_id as the attempt that claimed it, so
        // it is the attempt count that tells the two apart. Without this the job
        // would treat its own retry as a duplicate delivery and stop.
        $retry->setJob($this->queuedAttempt(2));
        $retry->handle(...$this->jobDependencies());

        $this->assertSame(1, Message::where('role', 'user')->count());
        $this->assertSame(1, Message::where('role', 'assistant')->count());
        $this->assertCount(1, $this->sentMessages());
    }

    public function test_the_job_is_configured_to_retry_with_a_growing_backoff(): void
    {
        $job = new HandleIncomingMessageJob($this->channel(), $this->update(1));

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 45], $job->backoff);

        // A job that outlives retry_after is handed to a second worker while the
        // first still holds it, and the customer is answered twice.
        $this->assertGreaterThan(
            $job->timeout + array_sum($job->backoff),
            (int) config('queue.connections.database.retry_after'),
        );
    }

    // --- graceful degradation ------------------------------------------------

    /**
     * A sub-step that throws must cost its own feature, not the answer. This is
     * the lead insert that took whole replies down in production — the model had
     * already written the reply and the owner had already been billed for it.
     */
    public function test_a_failing_order_insert_does_not_cost_the_customer_their_reply(): void
    {
        $this->fakeTelegramApi();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturn(
                [
                    'text' => '', 'input_tokens' => 10, 'output_tokens' => 5, 'finish_reason' => 'tool_calls',
                    'tool_calls' => [['id' => 'c1', 'name' => 'create_lead', 'arguments' => ['phone' => '+992901234567', 'items' => 'OMEN 16']]],
                ],
                ['text' => 'Спасибо! Мы вам позвоним.', 'input_tokens' => 8, 'output_tokens' => 4, 'finish_reason' => 'STOP', 'tool_calls' => []],
            );
        });

        // The leads table loses the column the insert needs, exactly as an
        // unmigrated deploy does.
        Schema::table('leads', fn ($table) => $table->dropColumn('phone'));

        $channel = $this->channel();
        $channel->agent->update(['capture_leads' => true]);

        (new HandleIncomingMessageJob($channel, $this->update(88, 'Беру OMEN 16, мой номер +992901234567')))
            ->handle(...$this->jobDependencies());

        // No order was recorded — that part genuinely failed — but the customer
        // was still answered.
        $this->assertDatabaseCount('leads', 0);
        $this->assertSame(1, Message::where('role', 'assistant')->count());
        $this->assertCount(1, $this->sentMessages());
    }

    // --- the last resort -----------------------------------------------------

    /**
     * Every attempt spent. The customer must be told something rather than left
     * to conclude the business ignored them.
     */
    public function test_a_permanently_failed_job_tells_the_customer_and_the_owner(): void
    {
        $this->fakeTelegramApi();

        $channel = $this->channel();
        // Not a fillable attribute — the linking flow writes it directly.
        $channel->agent->forceFill(['notify_telegram_chat_id' => '999000'])->save();

        (new HandleIncomingMessageJob($channel, $this->update(99)))
            ->failed(new RuntimeException('quota exceeded'));

        $sent = $this->sentMessages();

        $this->assertNotEmpty($sent);
        $this->assertStringContainsString('техническая ошибка', $sent[0]);
        // And the owner is told a customer is sitting unanswered.
        $this->assertStringContainsString('без ответа', implode(' ', $sent));
    }

    public function test_the_apology_is_sent_once_however_many_attempts_failed(): void
    {
        $this->fakeTelegramApi();

        $channel = $this->channel();
        $payload = $this->update(101);

        (new HandleIncomingMessageJob($channel, $payload))->failed(new RuntimeException('boom'));
        (new HandleIncomingMessageJob($channel, $payload))->failed(new RuntimeException('boom'));

        $apologies = array_filter(
            $this->sentMessages(),
            fn (string $text) => str_contains($text, 'техническая ошибка'),
        );

        $this->assertCount(1, $apologies);
    }

    /**
     * If the customer did get answered — a late-succeeding attempt, or a human
     * stepping in — the apology must not follow it.
     */
    public function test_no_apology_is_sent_when_the_customer_was_already_answered(): void
    {
        $this->fakeTelegramApi();
        $this->mockLlm();

        $channel = $this->channel();
        $payload = $this->update(102);

        (new HandleIncomingMessageJob($channel, $payload))->handle(...$this->jobDependencies());
        (new HandleIncomingMessageJob($channel, $payload))->failed(new RuntimeException('boom'));

        $this->assertEmpty(array_filter(
            $this->sentMessages(),
            fn (string $text) => str_contains($text, 'техническая ошибка'),
        ));
    }

    /**
     * A queue job handle reporting a given attempt number, so a retry can be
     * driven the way the worker drives one.
     */
    protected function queuedAttempt(int $attempts): Job
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->andReturn($attempts);
        $job->shouldIgnoreMissing();

        return $job;
    }

    /**
     * The dependencies handle() asks the container for.
     */
    /**
     * Everything the job asks for, resolved from the container — except the one
     * dependency a given test wants to control.
     *
     * Reflection rather than a hand-written list: the list version had to be
     * edited every time the job gained a collaborator, which broke a dozen
     * tests that care about none of them.
     *
     * @return array<int, mixed>
     */
    protected function jobDependencies(?AgentReplyService $replyService = null): array
    {
        $parameters = (new \ReflectionMethod(HandleIncomingMessageJob::class, 'handle'))->getParameters();

        return array_map(function (\ReflectionParameter $parameter) use ($replyService) {
            $type = $parameter->getType()?->getName();

            return $type === AgentReplyService::class && $replyService !== null
                ? $replyService
                : app($type);
        }, $parameters);
    }
}
