<?php

namespace Tests\Feature\Quality;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Quality\FeedbackCadence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WHEN a customer is asked to rate a reply. These tests pin the rule:
 *
 *     ask  ⟺  turnsSince >= 3  AND  (a lead was captured
 *                                    OR the customer is wrapping up)
 *
 * where turnsSince counts assistant turns since the last one that was
 * prompted, rated, or opened a session with /start.
 */
class FeedbackCadenceTest extends TestCase
{
    use RefreshDatabase;

    protected const QUESTION = 'Сколько стоит доставка?';

    protected const THANKS = 'Фахмо рахмат';

    protected Agent $agent;

    protected Channel $channel;

    /** Flipped by the delivery-failure test; read by the stub in setUp. */
    protected bool $telegramFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->agent = Agent::factory()->for($owner)->create();
        $this->channel = Channel::factory()->for($this->agent)->create([
            'type' => 'telegram',
            'status' => 'connected',
            'credentials' => ['bot_token' => 'BOT:TOKEN'],
            'settings' => ['webhook_secret' => 'secret'],
        ]);

        // A CLOSURE stub reading a mutable flag, rather than one stub per
        // outcome. Http::fake() merges stubs instead of replacing them, so a
        // later "now fail" fake would lose to the success stub registered here.
        Http::fake(fn () => $this->telegramFails
            ? Http::response(['ok' => false], 500)
            : Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200));

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturn([
                'text' => 'Доставка по городу — 1-2 дня.', 'tool_calls' => [],
                'input_tokens' => 10, 'output_tokens' => 5,
            ]);
        });
    }

    // -----------------------------------------------------------------
    // Driving whole turns through the real pipeline
    // -----------------------------------------------------------------

    protected int $updateId = 1000;

    /** One customer message, answered. Returns the assistant turn it produced. */
    protected function turn(string $text): Message
    {
        $this->updateId++;

        try {
            app()->call([new HandleIncomingMessageJob($this->channel, [
                'update_id' => $this->updateId,
                'message' => [
                    'message_id' => $this->updateId,
                    'from' => ['id' => 555, 'first_name' => 'Ivan'],
                    'chat' => ['id' => 555, 'type' => 'private'],
                    'date' => time(),
                    'text' => $text,
                ],
            ]), 'handle']);
        } catch (\Throwable $e) {
            // A delivery failure now propagates (so the queue retries it)
            // instead of being swallowed — expected only in the
            // telegramFails scenario this test suite drives on purpose.
            if (! $this->telegramFails) {
                throw $e;
            }
        }

        return Message::where('role', 'assistant')->latest('id')->firstOrFail();
    }

    /**
     * Which of these customer messages got a reply carrying a rating control,
     * by 1-based turn number. Read from the SENT MESSAGES, not the meta flag.
     *
     * @param  array<int, string>  $texts
     * @return array<int, int>
     */
    protected function promptedTurns(array $texts): array
    {
        $prompted = [];

        foreach ($texts as $index => $text) {
            $before = count(Http::recorded());

            $this->turn($text);

            if ($this->keyboardMessagesSince($before) !== []) {
                $prompted[] = $index + 1;
            }
        }

        return $prompted;
    }

    /** @return array<int, array<string, mixed>> sendMessage bodies carrying a keyboard. */
    protected function keyboardMessagesSince(int $offset): array
    {
        return collect(Http::recorded())
            ->slice($offset)
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]->data())
            ->filter(fn ($body) => isset($body['reply_markup']))
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // End-of-conversation signals
    // -----------------------------------------------------------------

    public function test_a_thanks_message_triggers_the_prompt(): void
    {
        $this->assertSame([4], $this->promptedTurns([
            self::QUESTION, self::QUESTION, self::QUESTION, self::THANKS,
        ]));
    }

    /** No routine interval any more: a conversation still in flow is never interrupted. */
    public function test_a_mid_flow_question_does_not_trigger_the_prompt(): void
    {
        $this->assertSame([], $this->promptedTurns(array_fill(0, 12, self::QUESTION)));
    }

    /** Thanks with a follow-up question attached is still mid-flow. */
    public function test_thanks_followed_by_a_question_does_not_trigger_the_prompt(): void
    {
        $this->assertSame([], $this->promptedTurns([
            self::QUESTION, self::QUESTION, self::QUESTION, 'Спасибо, а в Душанбе доставляете?',
        ]));
    }

    public function test_a_captured_lead_triggers_the_prompt(): void
    {
        $conversation = $this->conversationWithTurns(2);
        $third = $this->appendTurn($conversation, ['lead_captured' => true]);

        $this->assertTrue(app(FeedbackCadence::class)->shouldAsk($conversation, $third));
    }

    public function test_a_price_sent_for_approval_counts_as_a_close(): void
    {
        $conversation = $this->conversationWithTurns(2);
        $third = $this->appendTurn($conversation, ['price_approval_requested' => true]);

        $this->assertTrue(app(FeedbackCadence::class)->shouldAsk($conversation, $third));
    }

    // -----------------------------------------------------------------
    // The floor
    // -----------------------------------------------------------------

    /** Nobody can judge an assistant on its opening lines — not even a thankful customer. */
    public function test_the_floor_still_blocks_turns_one_and_two(): void
    {
        $this->assertSame([3], $this->promptedTurns([self::THANKS, self::THANKS, self::THANKS]));
    }

    /** A rating restarts the clock just as a prompt does. */
    public function test_a_rating_restarts_the_clock(): void
    {
        $cadence = app(FeedbackCadence::class);

        $conversation = $this->conversationWithTurns(4);
        $conversation->messages()->where('role', 'assistant')->orderBy('id')->get()[3]->rate(Message::RATING_UP);

        $this->assertFalse($cadence->shouldAsk($conversation, $this->appendTurn($conversation, [], self::THANKS)));
        $this->assertFalse($cadence->shouldAsk($conversation, $this->appendTurn($conversation, [], self::THANKS)));
        $this->assertTrue($cadence->shouldAsk($conversation, $this->appendTurn($conversation, [], self::THANKS)));
    }

    /** Two prompts are never closer together than the floor. */
    public function test_a_lead_cannot_pull_a_prompt_inside_the_floor(): void
    {
        $cadence = app(FeedbackCadence::class);

        $conversation = $this->conversationWithTurns(5);
        $conversation->messages()->where('role', 'assistant')->orderBy('id')->get()[4]
            ->forceFill(['meta' => ['feedback_requested' => true]])->save();

        $this->assertFalse($cadence->shouldAsk($conversation, $this->appendTurn($conversation, ['lead_captured' => true])));
        $this->assertFalse($cadence->shouldAsk($conversation, $this->appendTurn($conversation, ['lead_captured' => true])));
        $this->assertTrue($cadence->shouldAsk($conversation, $this->appendTurn($conversation, ['lead_captured' => true])));
    }

    /**
     * The Bug-2 regression: Telegram keeps one chat per customer for ever, and
     * turns from days ago counted toward the prompt on a fresh /start session.
     */
    public function test_a_start_command_restarts_the_clock(): void
    {
        $this->promptedTurns(array_fill(0, 6, self::QUESTION));

        $this->assertSame([], $this->promptedTurns(['/start', 'Ассалому алейкум', self::THANKS]));
        $this->assertSame([1], $this->promptedTurns([self::THANKS]));
    }

    // -----------------------------------------------------------------
    // Turns that must never be rated
    // -----------------------------------------------------------------

    public function test_a_failed_turn_is_never_rated(): void
    {
        $conversation = $this->conversationWithTurns(6);
        $seventh = $this->appendTurn($conversation, ['failed' => true], self::THANKS);

        $this->assertFalse(app(FeedbackCadence::class)->shouldAsk($conversation, $seventh));
    }

    public function test_an_escalated_turn_is_never_rated(): void
    {
        $conversation = $this->conversationWithTurns(6);
        $seventh = $this->appendTurn($conversation, ['escalated' => true], self::THANKS);

        $this->assertFalse(app(FeedbackCadence::class)->shouldAsk($conversation, $seventh));
    }

    /** A skipped turn does not spend the slot: the next eligible one is asked. */
    public function test_skipping_an_ineligible_turn_does_not_cost_the_next_one(): void
    {
        $cadence = app(FeedbackCadence::class);

        $conversation = $this->conversationWithTurns(4);

        $this->assertFalse($cadence->shouldAsk($conversation, $this->appendTurn($conversation, ['failed' => true], self::THANKS)));
        $this->assertTrue($cadence->shouldAsk($conversation, $this->appendTurn($conversation, [], self::THANKS)));
    }

    // -----------------------------------------------------------------
    // The explanatory line
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: array<int, string>, 1: string}>
     */
    public static function conversationsByLanguage(): array
    {
        return [
            'russian' => [['У вас есть ноутбуки?', 'Сколько стоит доставка?', 'Спасибо!'], 'ru'],
            'tajik' => [['Шумо ноутбукҳо доред?', 'Нархаш чӣ қадар аст?', 'Раҳмат калон'], 'tg'],
            'english' => [['Do you have laptops?', 'How much is delivery?', 'Thanks!'], 'en'],
        ];
    }

    /** @param  array<int, string>  $texts */
    #[DataProvider('conversationsByLanguage')]
    public function test_the_prompt_explains_why_in_the_customers_language(array $texts, string $locale): void
    {
        $this->turn($texts[0]);
        $this->turn($texts[1]);

        $before = count(Http::recorded());
        $this->turn($texts[2]);

        $prompts = $this->keyboardMessagesSince($before);
        $this->assertCount(1, $prompts);

        $this->assertStringContainsString(__('feedback.prompt', [], $locale), $prompts[0]['text']);

        foreach (array_diff(['ru', 'tg', 'en'], [$locale]) as $other) {
            $this->assertStringNotContainsString(__('feedback.prompt', [], $other), $prompts[0]['text']);
        }
    }

    /** The line is delivery, not part of the reply the owner sees in the inbox. */
    public function test_the_explanatory_line_is_not_stored_on_the_reply(): void
    {
        $this->promptedTurns([self::QUESTION, self::QUESTION, 'Спасибо!']);

        $reply = Message::where('role', 'assistant')->latest('id')->firstOrFail();

        $this->assertTrue($reply->meta['feedback_requested']);
        $this->assertStringNotContainsString(__('feedback.prompt', [], 'ru'), $reply->content);
    }

    // -----------------------------------------------------------------
    // Bookkeeping
    // -----------------------------------------------------------------

    /** A delivery that failed must not spend the next slot on a message nobody saw. */
    public function test_a_failed_delivery_does_not_consume_the_prompt(): void
    {
        $this->telegramFails = true;

        $this->turn(self::QUESTION);
        $this->turn(self::QUESTION);
        $third = $this->turn(self::THANKS);

        $this->assertArrayNotHasKey('feedback_requested', $third->meta);

        $this->telegramFails = false;
        $fourth = $this->turn(self::THANKS);

        $this->assertTrue($fourth->fresh()->meta['feedback_requested']);
    }

    public function test_the_floor_is_configurable(): void
    {
        config()->set('agent.feedback.min_turns_between', 1);

        $this->assertSame([1, 2], $this->promptedTurns([self::THANKS, self::THANKS]));
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** A conversation with $count plain question/answer turns already behind it. */
    protected function conversationWithTurns(int $count): Conversation
    {
        $conversation = $this->agent->conversations()->create([
            'status' => 'ai',
            'channel_id' => $this->channel->id,
            'external_user_id' => 'u'.uniqid(),
        ]);

        for ($i = 0; $i < $count; $i++) {
            $this->appendTurn($conversation);
        }

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function appendTurn(Conversation $conversation, array $meta = [], string $customerText = self::QUESTION): Message
    {
        $conversation->messages()->create(['role' => 'user', 'content' => $customerText]);

        return $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'ответ',
            'meta' => $meta,
        ]);
    }
}
