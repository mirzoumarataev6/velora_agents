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
use Tests\TestCase;

/**
 * How OFTEN a customer is asked to rate a reply.
 *
 * The feature shipped asking after every single turn, which made the control
 * furniture rather than a question. These tests pin the rule that replaced it:
 *
 *     ask  ⟺  turnsSince >= 3  AND  (a lead was captured  OR  turnsSince >= 5)
 *
 * where turnsSince counts assistant turns since the last one that was prompted
 * or rated. Everything below is that sentence, case by case.
 */
class FeedbackCadenceTest extends TestCase
{
    use RefreshDatabase;

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
        // later "now fail" fake would lose to the success stub registered here
        // and the test would silently keep passing.
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

    /** One customer message, answered. Returns the assistant turn it produced. */
    protected function turn(int $n): Message
    {
        app()->call([new HandleIncomingMessageJob($this->channel, [
            'update_id' => 1000 + $n,
            'message' => [
                'message_id' => 1000 + $n,
                'from' => ['id' => 555, 'first_name' => 'Ivan'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'date' => time(),
                'text' => "Вопрос номер {$n}?",
            ],
        ]), 'handle']);

        return Message::where('role', 'assistant')->latest('id')->firstOrFail();
    }

    /**
     * Which of the first $count turns carried a rating control, by turn number.
     *
     * Read from the SENT MESSAGES rather than from the meta flag: the flag is
     * this feature's bookkeeping, and a test that trusted it would pass even if
     * no button ever reached Telegram.
     *
     * @return array<int, int>
     */
    protected function promptedTurns(int $count): array
    {
        $prompted = [];

        for ($n = 1; $n <= $count; $n++) {
            // Only the requests THIS turn made. Sliced from an offset rather
            // than cleared by re-faking, so the stub registered in setUp stays
            // the only one in play.
            $before = count(Http::recorded());

            $this->turn($n);

            $carried = collect(Http::recorded())
                ->slice($before)
                ->filter(fn ($pair) => str_contains($pair[0]->url(), '/sendMessage'))
                ->contains(fn ($pair) => isset($pair[0]->data()['reply_markup']));

            if ($carried) {
                $prompted[] = $n;
            }
        }

        return $prompted;
    }

    // -----------------------------------------------------------------
    // The default cadence, end to end
    // -----------------------------------------------------------------

    /**
     * The headline behaviour, and the whole reason for the change: a customer
     * having an ordinary conversation is asked once in five turns, not five
     * times in five turns.
     */
    public function test_an_ordinary_conversation_is_asked_once_every_five_turns(): void
    {
        $this->assertSame([5, 10], $this->promptedTurns(12));
    }

    /** Nobody can judge an assistant on its opening line. */
    public function test_the_opening_turns_are_never_asked(): void
    {
        $this->assertSame([], $this->promptedTurns(4));
    }

    // -----------------------------------------------------------------
    // The floor
    // -----------------------------------------------------------------

    /**
     * "Once a rating is given, don't keep prompting again immediately after."
     *
     * The count restarts on a RATING as well as on a prompt, so answering one
     * buys the same quiet as being asked one.
     */
    public function test_a_rating_restarts_the_clock_just_as_a_prompt_does(): void
    {
        $cadence = app(FeedbackCadence::class);

        $conversation = $this->conversationWithTurns(4);
        $rated = $conversation->messages()->where('role', 'assistant')->orderBy('id')->get()[3];
        $rated->rate(Message::RATING_UP);

        // Turn 5 would have been asked on the routine interval; the vote on
        // turn 4 puts it back inside the floor.
        $fifth = $this->appendTurn($conversation);
        $this->assertFalse($cadence->shouldAsk($conversation, $fifth));

        // And it stays quiet until the interval has run again from the vote.
        foreach ([6, 7, 8] as $ignored) {
            $this->assertFalse($cadence->shouldAsk($conversation, $this->appendTurn($conversation)));
        }

        $this->assertTrue($cadence->shouldAsk($conversation, $this->appendTurn($conversation)));
    }

    /** Two prompts are never closer together than the floor. */
    public function test_a_lead_cannot_pull_a_prompt_inside_the_floor(): void
    {
        $cadence = app(FeedbackCadence::class);

        $conversation = $this->conversationWithTurns(5);
        $conversation->messages()->where('role', 'assistant')->orderBy('id')->get()[4]
            ->forceFill(['meta' => ['feedback_requested' => true]])->save();

        // An order lands on the very next turn — the strongest signal there is,
        // and still not enough to ask again this soon.
        $sixth = $this->appendTurn($conversation, ['lead_captured' => true]);
        $this->assertFalse($cadence->shouldAsk($conversation, $sixth));

        $seventh = $this->appendTurn($conversation, ['lead_captured' => true]);
        $this->assertFalse($cadence->shouldAsk($conversation, $seventh));

        // Three turns on, it may.
        $eighth = $this->appendTurn($conversation, ['lead_captured' => true]);
        $this->assertTrue($cadence->shouldAsk($conversation, $eighth));
    }

    // -----------------------------------------------------------------
    // The lead shortcut
    // -----------------------------------------------------------------

    /**
     * The moment the conversation achieved what it was for: the best point to
     * ask, and a naturally rare one.
     */
    public function test_a_captured_lead_brings_the_prompt_forward(): void
    {
        $cadence = app(FeedbackCadence::class);

        $conversation = $this->conversationWithTurns(2);
        $third = $this->appendTurn($conversation, ['lead_captured' => true]);

        // Turn 3 is short of the routine interval but clear of the floor.
        $this->assertTrue($cadence->shouldAsk($conversation, $third));
    }

    public function test_a_price_sent_for_approval_counts_as_a_close(): void
    {
        $cadence = app(FeedbackCadence::class);

        $conversation = $this->conversationWithTurns(2);
        $third = $this->appendTurn($conversation, ['price_approval_requested' => true]);

        $this->assertTrue($cadence->shouldAsk($conversation, $third));
    }

    // -----------------------------------------------------------------
    // Turns that must never be rated
    // -----------------------------------------------------------------

    /** Asking someone to rate the apology for an outage is the wrong question. */
    public function test_a_failed_turn_is_never_rated(): void
    {
        $conversation = $this->conversationWithTurns(6);
        $seventh = $this->appendTurn($conversation, ['failed' => true]);

        $this->assertFalse(app(FeedbackCadence::class)->shouldAsk($conversation, $seventh));
    }

    /** A handover is on its way to a human; the bot's answer is not the subject. */
    public function test_an_escalated_turn_is_never_rated(): void
    {
        $conversation = $this->conversationWithTurns(6);
        $seventh = $this->appendTurn($conversation, ['escalated' => true]);

        $this->assertFalse(app(FeedbackCadence::class)->shouldAsk($conversation, $seventh));
    }

    /**
     * A skipped turn must not spend the conversation's slot: the next eligible
     * turn is asked instead of waiting out another interval.
     */
    public function test_skipping_an_ineligible_turn_does_not_cost_the_next_one(): void
    {
        $cadence = app(FeedbackCadence::class);

        $conversation = $this->conversationWithTurns(4);
        $fifth = $this->appendTurn($conversation, ['failed' => true]);

        $this->assertFalse($cadence->shouldAsk($conversation, $fifth));
        $this->assertTrue($cadence->shouldAsk($conversation, $this->appendTurn($conversation)));
    }

    // -----------------------------------------------------------------
    // Bookkeeping
    // -----------------------------------------------------------------

    /**
     * A prompt is recorded only once the reply is actually out. A delivery that
     * failed must not spend the next slot on a message nobody saw.
     */
    public function test_a_failed_delivery_does_not_consume_the_prompt(): void
    {
        $this->telegramFails = true;

        for ($n = 1; $n <= 5; $n++) {
            $this->turn($n);
        }

        $fifth = Message::where('role', 'assistant')->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('feedback_requested', $fifth->meta);

        // So the next turn to get through is asked, rather than waiting out
        // another five for a prompt that never landed.
        $this->telegramFails = false;
        $sixth = $this->turn(6);

        $this->assertTrue($sixth->fresh()->meta['feedback_requested']);
    }

    /** The record lives in the transcript, so the cadence is reconstructible. */
    public function test_asking_is_recorded_on_the_message_that_asked(): void
    {
        $this->promptedTurns(5);

        $turns = Message::where('role', 'assistant')->orderBy('id')->get();

        $this->assertTrue($turns[4]->meta['feedback_requested']);
        foreach ([0, 1, 2, 3] as $index) {
            $this->assertArrayNotHasKey('feedback_requested', $turns[$index]->meta);
        }
    }

    /** The thresholds are configuration, and changing them changes the cadence. */
    public function test_the_cadence_is_configurable(): void
    {
        config()->set('agent.feedback.min_turns_between', 2);
        config()->set('agent.feedback.every_n_turns', 2);

        $this->assertSame([2, 4, 6], $this->promptedTurns(7));
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** A conversation with $count plain assistant turns already behind it. */
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
    protected function appendTurn(Conversation $conversation, array $meta = []): Message
    {
        $conversation->messages()->create(['role' => 'user', 'content' => 'вопрос']);

        return $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'ответ',
            'meta' => $meta,
        ]);
    }
}
