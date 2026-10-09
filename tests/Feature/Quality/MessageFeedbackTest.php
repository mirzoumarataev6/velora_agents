<?php

namespace Tests\Feature\Quality;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Channels\ChannelDriverInterface;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\HasNoMessageFeedback;
use App\Services\Channels\HumanizedReplySender;
use App\Services\Channels\IncomingMessage;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Quality\FeedbackCadence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 👍/👎 on an agent reply: attached where the platform can carry it, recorded
 * once, and visible to the owner in the thread it happened in.
 *
 * The whole round trip lives inside the queued job, so these drive the job and
 * assert on the Telegram calls it makes.
 */
class MessageFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->agent = Agent::factory()->for($this->owner)->create();
        $this->channel = Channel::factory()->for($this->agent)->create([
            'type' => 'telegram',
            'status' => 'connected',
            'credentials' => ['bot_token' => 'BOT:TOKEN'],
            'settings' => ['webhook_secret' => 'secret'],
        ]);

        // These tests are about the CONTROL — how it is attached, recorded and
        // guarded — not about when it is offered. The cadence is stubbed to
        // "every turn" so each test can work on the reply in front of it; when
        // a real customer sees one is FeedbackCadenceTest's subject.
        $this->app->bind(FeedbackCadence::class, fn () => new class(1) extends FeedbackCadence
        {
            public function shouldAsk(Conversation $conversation, Message $reply, ?Message $customerMessage = null): bool
            {
                return true;
            }
        });

        // One catch-all whose result satisfies every Telegram method these
        // tests reach — sendMessage wants a message_id, getMe wants bot info.
        // Deliberately not several ordered patterns: Http::fake() MERGES stubs
        // rather than replacing them, so a later, more specific stub loses to
        // this one anyway and reads as if it were in effect.
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 42, 'username' => 'bot', 'first_name' => 'Bot'],
            ], 200),
        ]);
    }

    protected function mockLlm(string $reply = 'Доставка по городу — 1-2 дня.'): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($reply) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturn([
                'text' => $reply, 'tool_calls' => [], 'input_tokens' => 10, 'output_tokens' => 5,
            ]);
        });
    }

    /** Drive one webhook delivery all the way through the job. */
    protected function deliver(array $payload): void
    {
        app()->call([new HandleIncomingMessageJob($this->channel, $payload), 'handle']);
    }

    protected function customerMessage(int $updateId = 500, string $text = 'Сколько стоит доставка?'): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => 555, 'first_name' => 'Ivan'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'date' => time(),
                'text' => $text,
            ],
        ];
    }

    /** A 👍/👎 tap, as Telegram delivers it. */
    protected function vote(int $messageId, string $direction, int $updateId = 600, int $fromId = 555): array
    {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'CB'.$updateId,
                'from' => ['id' => $fromId, 'first_name' => 'Ivan'],
                'data' => "fb:{$direction}:{$messageId}",
                'message' => [
                    'message_id' => 42,
                    'chat' => ['id' => $fromId, 'type' => 'private'],
                ],
            ],
        ];
    }

    /** The reply the agent actually sent, having answered one question. */
    protected function replyToRate(): Message
    {
        $this->mockLlm();
        $this->deliver($this->customerMessage());

        return Message::where('role', 'assistant')->latest('id')->firstOrFail();
    }

    /**
     * @return array<int, array<string, mixed>> Every payload posted to one Telegram method.
     */
    protected function callsTo(string $method): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), "/{$method}"))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // Attaching the control
    // -----------------------------------------------------------------

    public function test_a_telegram_reply_carries_a_thumbs_up_and_down_keyboard(): void
    {
        $reply = $this->replyToRate();

        $sends = $this->callsTo('sendMessage');
        $withKeyboard = array_values(array_filter($sends, fn ($body) => isset($body['reply_markup'])));

        $this->assertCount(1, $withKeyboard, 'Exactly one message should carry the control.');

        $keyboard = json_decode($withKeyboard[0]['reply_markup'], true)['inline_keyboard'][0];

        $this->assertSame('👍', $keyboard[0]['text']);
        $this->assertSame('👎', $keyboard[1]['text']);

        // The buttons name the reply they are about, which is what makes the
        // vote attributable to one answer rather than to the conversation.
        $this->assertSame("fb:up:{$reply->id}", $keyboard[0]['callback_data']);
        $this->assertSame("fb:down:{$reply->id}", $keyboard[1]['callback_data']);
    }

    /**
     * A long reply is delivered as several human-paced messages. The control
     * belongs on the last one: the answer is not finished before it.
     */
    public function test_only_the_final_message_of_a_split_reply_carries_the_control(): void
    {
        $this->mockLlm(str_repeat('Доставка по городу занимает один или два дня. ', 12));
        $this->deliver($this->customerMessage());

        $sends = $this->callsTo('sendMessage');
        $withKeyboard = array_filter($sends, fn ($body) => isset($body['reply_markup']));

        $this->assertGreaterThan(1, count($sends), 'This reply should have been split.');
        $this->assertCount(1, $withKeyboard);
        $this->assertSame(array_key_last($sends), array_key_first($withKeyboard));
    }

    /**
     * The capability is declared, not assumed. WhatsApp and Instagram can draw
     * buttons but can neither edit a sent message nor attribute a tap to one,
     * so they say so and send the reply plain.
     */
    public function test_channels_that_cannot_carry_a_control_say_so_and_send_the_reply_anyway(): void
    {
        $drivers = app(ChannelDriverManager::class);

        $this->assertTrue($drivers->driver('telegram')->supportsMessageFeedback());
        $this->assertFalse($drivers->driver('whatsapp')->supportsMessageFeedback());
        $this->assertFalse($drivers->driver('instagram')->supportsMessageFeedback());
    }

    /**
     * The graceful skip, asserted on the contract rather than on a real Meta
     * channel: the point is that a driver which cannot carry a control still
     * DELIVERS THE REPLY when asked to send one with a control attached, so
     * nothing upstream has to branch on the channel type.
     */
    public function test_a_channel_without_feedback_support_still_delivers_the_reply(): void
    {
        $driver = new class extends FakeUnsupportedDriver {};

        $message = new Message(['role' => 'assistant', 'content' => 'Ответ клиенту']);
        $message->id = 12345;

        $sent = app(HumanizedReplySender::class)->send(
            $driver,
            $this->channel,
            '79990000000',
            'Ответ клиенту',
            $message,
        );

        $this->assertSame(1, $sent);
        $this->assertSame(['Ответ клиенту'], $driver->sent, 'The reply must go out, control or not.');
    }

    // -----------------------------------------------------------------
    // Recording the vote
    // -----------------------------------------------------------------

    public function test_a_thumbs_down_is_recorded_against_the_reply_it_was_tapped_on(): void
    {
        $reply = $this->replyToRate();

        $this->deliver($this->vote($reply->id, 'down'));

        $this->assertSame(Message::RATING_DOWN, $reply->fresh()->rating);
        $this->assertNotNull($reply->fresh()->rated_at);
    }

    public function test_a_thumbs_up_is_recorded_too(): void
    {
        $reply = $this->replyToRate();

        $this->deliver($this->vote($reply->id, 'up'));

        $this->assertSame(Message::RATING_UP, $reply->fresh()->rating);
    }

    /** A vote is a rating, not a message: it must not become a turn or a reply. */
    public function test_a_vote_creates_no_conversation_turn_and_triggers_no_reply(): void
    {
        $reply = $this->replyToRate();
        $before = Message::count();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200)]);
        $this->deliver($this->vote($reply->id, 'up'));

        $this->assertSame($before, Message::count(), 'A tap must not be stored as a customer message.');
        $this->assertSame([], $this->callsTo('sendMessage'), 'A tap must not produce a reply.');
    }

    // -----------------------------------------------------------------
    // Voting twice
    // -----------------------------------------------------------------

    public function test_the_second_tap_cannot_change_the_first_verdict(): void
    {
        $reply = $this->replyToRate();

        $this->deliver($this->vote($reply->id, 'up', updateId: 601));
        $this->deliver($this->vote($reply->id, 'down', updateId: 602));

        $this->assertSame(
            Message::RATING_UP,
            $reply->fresh()->rating,
            'The first vote is the vote; a second tap must not overwrite it.',
        );
    }

    /** The guard is in the UPDATE, so it holds regardless of who calls it. */
    public function test_rating_a_message_twice_reports_only_the_first_as_recorded(): void
    {
        $reply = $this->replyToRate();

        $this->assertTrue($reply->rate(Message::RATING_UP));
        $this->assertFalse($reply->rate(Message::RATING_DOWN));
        $this->assertSame(Message::RATING_UP, $reply->fresh()->rating);
    }

    public function test_an_unknown_rating_value_is_refused(): void
    {
        $reply = $this->replyToRate();

        $this->assertFalse($reply->rate('thumbs_sideways'));
        $this->assertNull($reply->fresh()->rating);
    }

    /**
     * The customer is told what happened either way — the button is retired on
     * the first tap, and a second one gets an explanation rather than silence.
     */
    public function test_both_taps_are_acknowledged_in_telegram(): void
    {
        $reply = $this->replyToRate();

        $this->deliver($this->vote($reply->id, 'up', updateId: 601));
        $this->deliver($this->vote($reply->id, 'down', updateId: 602));

        $answers = $this->callsTo('answerCallbackQuery');
        $this->assertCount(2, $answers, 'Every tap must be acknowledged or the client spins.');

        $this->assertSame(__('feedback.confirm.thanks', [], 'ru'), $answers[0]['text']);
        $this->assertSame(__('feedback.confirm.already', [], 'ru'), $answers[1]['text']);

        // And the control is taken away, so the reply cannot be voted on again.
        $edits = $this->callsTo('editMessageReplyMarkup');
        $this->assertNotEmpty($edits);
        $this->assertStringNotContainsString('fb:up:', $edits[0]['reply_markup']);
        $this->assertStringNotContainsString('fb:down:', $edits[0]['reply_markup']);
    }

    // -----------------------------------------------------------------
    // Whose vote it is
    // -----------------------------------------------------------------

    /**
     * The message id travels through Telegram inside the callback data, so it
     * arrives from outside and is re-derived rather than trusted: a tap can
     * only rate a reply sent to the person doing the tapping.
     */
    public function test_a_vote_from_a_different_customer_is_discarded(): void
    {
        $reply = $this->replyToRate();

        $this->deliver($this->vote($reply->id, 'down', updateId: 610, fromId: 999));

        $this->assertNull($reply->fresh()->rating);
    }

    public function test_a_vote_for_a_message_that_does_not_exist_is_discarded(): void
    {
        $this->replyToRate();

        $this->deliver($this->vote(999999, 'down', updateId: 611));

        $this->assertSame(0, Message::whereNotNull('rating')->count());
    }

    /** A customer message is not rateable — only the agent's answers are. */
    public function test_a_vote_cannot_be_recorded_against_the_customers_own_message(): void
    {
        $this->replyToRate();
        $customerMessage = Message::where('role', 'user')->latest('id')->firstOrFail();

        $this->deliver($this->vote($customerMessage->id, 'down', updateId: 612));

        $this->assertNull($customerMessage->fresh()->rating);
    }

    // -----------------------------------------------------------------
    // What the owner sees
    // -----------------------------------------------------------------

    public function test_the_rating_is_visible_on_the_message_in_the_inbox(): void
    {
        $reply = $this->replyToRate();
        $this->deliver($this->vote($reply->id, 'down'));

        $conversation = Conversation::latest('id')->firstOrFail();

        $response = $this->actingAs($this->owner)
            ->getJson(route('conversations.show', $conversation))
            ->assertOk();

        $rated = collect($response->json('messages'))->firstWhere('id', $reply->id);

        $this->assertSame(Message::RATING_DOWN, $rated['rating']);
        $this->assertNotNull($rated['rated_at']);

        // An unrated reply reads as unrated, not as neutral.
        $customer = collect($response->json('messages'))->firstWhere('role', 'user');
        $this->assertNull($customer['rating']);
    }

    /**
     * Telegram only delivers the update types the webhook subscribed to, so a
     * missing callback_query here would make the entire feature silently inert
     * in production while every test above still passed.
     */
    public function test_the_webhook_subscribes_to_callback_updates(): void
    {
        app(ChannelDriverManager::class)->for($this->channel)->connect($this->channel);

        $this->assertContains('callback_query', $this->callsTo('setWebhook')[0]['allowed_updates']);
    }
}

/**
 * A stand-in for WhatsApp/Instagram: implements the contract, declares that it
 * cannot carry a 👍/👎 control, and records what it was asked to send.
 *
 * Deliberately uses the real HasNoMessageFeedback trait — the behaviour under
 * test is the trait's, not a re-implementation of it.
 */
abstract class FakeUnsupportedDriver implements ChannelDriverInterface
{
    use HasNoMessageFeedback;

    /** @var string[] */
    public array $sent = [];

    public function sendMessage(Channel $channel, string $externalUserId, string $text): void
    {
        $this->sent[] = $text;
    }

    public function sendImage(Channel $channel, string $externalUserId, string $url, string $caption = ''): void {}

    public function verifyWebhook(Request $request, Channel $channel): ?Response
    {
        return null;
    }

    public function authenticateIncomingRequest(Request $request, Channel $channel): bool
    {
        return true;
    }

    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage
    {
        return null;
    }

    public function splitDeliveries(array $payload): array
    {
        return [$payload];
    }

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void {}

    public function connect(Channel $channel): array
    {
        return [];
    }

    public function disconnect(Channel $channel): void {}
}
