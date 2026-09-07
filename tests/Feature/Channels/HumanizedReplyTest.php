<?php

namespace Tests\Feature\Channels;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Llm\LlmProviderInterface;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * End-to-end coverage for human-like multi-message replies: a long answer
 * arrives as 2-3 messages with a typing indicator between them, a short one
 * arrives as a single message, and either way it stays ONE assistant turn as
 * far as history, dedup and lead capture are concerned.
 */
class HumanizedReplyTest extends TestCase
{
    use RefreshDatabase;

    protected const LONG_REPLY = "Здравствуйте! Да, это платье есть в наличии в размерах S, M и L.\n\n"
        ."Цена — 450 сомони, доставка по Душанбе бесплатная при заказе от 300 сомони, обычно привозим в тот же день.\n\n"
        .'Скажите, какой размер вам подойдёт, и я оформлю заказ.';

    protected function telegramUpdate(int $updateId, string $text = 'Есть платье?'): array
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

    protected function postWebhook(Channel $channel, array $payload)
    {
        return $this->postJson(
            route('webhooks.telegram', $channel),
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => $channel->settings['webhook_secret']]
        );
    }

    protected function mockLlm(string $replyText): void
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

    /**
     * The Telegram API methods called, in order (e.g. ['sendChatAction', 'sendMessage']).
     *
     * @return string[]
     */
    protected function telegramCallSequence(): array
    {
        return Http::recorded()
            ->map(fn ($pair) => (string) str($pair[0]->url())->afterLast('/'))
            ->all();
    }

    /**
     * @return string[]
     */
    protected function sentTexts(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]->data()['text'])
            ->values()
            ->all();
    }

    public function test_long_reply_arrives_as_multiple_messages_with_typing_indicators(): void
    {
        $this->mockLlm(self::LONG_REPLY);
        $this->fakeTelegramApi();
        $channel = Channel::factory()->create();

        $this->postWebhook($channel, $this->telegramUpdate(1001))->assertOk();

        $texts = $this->sentTexts();
        $this->assertCount(3, $texts);

        // Every message is a coherent piece of the reply, nothing lost.
        $normalize = fn (string $s) => preg_replace('/\s+/u', ' ', trim($s));
        $this->assertSame($normalize(self::LONG_REPLY), $normalize(implode(' ', $texts)));

        // A typing indicator precedes each follow-up message (the first one is
        // already covered by the indicator sent before generation started).
        $sequence = $this->telegramCallSequence();
        foreach ([1, 2] as $index) {
            $position = array_keys($sequence, 'sendMessage')[$index];
            $this->assertSame('sendChatAction', $sequence[$position - 1]);
        }
    }

    public function test_pauses_between_messages_are_within_the_configured_range(): void
    {
        $this->mockLlm(self::LONG_REPLY);
        $this->fakeTelegramApi();
        $channel = Channel::factory()->create();

        $this->postWebhook($channel, $this->telegramUpdate(1002))->assertOk();

        Sleep::assertSleptTimes(2); // one pause before each of the 2 follow-ups

        $min = (int) config('agent.humanize.min_delay_ms');
        $max = (int) config('agent.humanize.max_delay_ms');

        Sleep::assertSlept(
            fn (CarbonInterval $slept) => $slept->totalMilliseconds >= $min && $slept->totalMilliseconds <= $max,
            times: 2,
        );
    }

    public function test_short_reply_arrives_as_a_single_message(): void
    {
        $this->mockLlm('Да, есть в наличии!');
        $this->fakeTelegramApi();
        $channel = Channel::factory()->create();

        $this->postWebhook($channel, $this->telegramUpdate(1003))->assertOk();

        $this->assertSame(['Да, есть в наличии!'], $this->sentTexts());
        Sleep::assertNeverSlept();
    }

    /**
     * There is no per-agent off-switch any more — the setting was removed from
     * the form and the behaviour is unconditional. A long reply is split for
     * every agent, however the agent was configured before.
     */
    public function test_a_long_reply_is_split_for_every_agent(): void
    {
        $this->mockLlm(self::LONG_REPLY);
        $this->fakeTelegramApi();
        $agent = Agent::factory()->create();
        // The state an upgraded account is in: an old "off" left in the column
        // that nothing is allowed to read.
        $agent->forceFill(['humanize_replies' => false])->save();
        $channel = Channel::factory()->for($agent)->create();

        $this->postWebhook($channel, $this->telegramUpdate(1004))->assertOk();

        $this->assertGreaterThan(1, count($this->sentTexts()));
        $this->assertSame(self::LONG_REPLY, implode("\n\n", $this->sentTexts()));
    }

    public function test_split_reply_is_stored_as_exactly_one_assistant_message(): void
    {
        $this->mockLlm(self::LONG_REPLY);
        $this->fakeTelegramApi();
        $channel = Channel::factory()->create();

        $this->postWebhook($channel, $this->telegramUpdate(1005))->assertOk();

        $conversation = Conversation::where('channel_id', $channel->id)->firstOrFail();
        $assistantMessages = Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->get();

        $this->assertCount(1, $assistantMessages);
        $this->assertSame(self::LONG_REPLY, $assistantMessages->first()->content);
        $this->assertDatabaseCount('messages', 2); // one user + one assistant turn
    }

    public function test_instagram_delivers_the_split_reply_with_typing_actions(): void
    {
        $this->mockLlm(self::LONG_REPLY);
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.OUT1'], 200)]);
        $channel = Channel::factory()->instagram()->create();

        $this->postJson(route('webhooks.instagram', $channel), [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'IG_PAGE_ID',
                'messaging' => [[
                    'sender' => ['id' => '778899'],
                    'recipient' => ['id' => 'IG_BUSINESS_ID'],
                    'message' => ['mid' => 'mid.3001', 'text' => 'Есть платье?'],
                ]],
            ]],
        ])->assertOk();

        $bodies = Http::recorded()->map(fn ($pair) => $pair[0]->data());

        $texts = $bodies->pluck('message.text')->filter()->values();
        $typing = $bodies->where('sender_action', 'typing_on');

        $this->assertCount(3, $texts);
        $this->assertCount(3, $typing); // one before generation + one per follow-up
        Sleep::assertSleptTimes(2);
    }

    public function test_whatsapp_delivers_the_split_reply_with_pauses(): void
    {
        // WhatsApp Cloud API has no standalone typing action for this flow, so
        // the pause alone carries the human pacing there — the reply must still
        // arrive as separate messages.
        $this->mockLlm(self::LONG_REPLY);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);
        $channel = Channel::factory()->whatsapp()->create();

        $this->postJson(route('webhooks.whatsapp', $channel), [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '1234567890'],
                        'contacts' => [['wa_id' => '992900112233', 'profile' => ['name' => 'Ivan']]],
                        'messages' => [[
                            'from' => '992900112233',
                            'id' => 'wamid.4001',
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => 'Есть платье?'],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();

        $texts = Http::recorded()
            ->map(fn ($pair) => $pair[0]->data()['text']['body'] ?? null)
            ->filter()
            ->values();

        $this->assertCount(3, $texts);
        Sleep::assertSleptTimes(2);
    }

    public function test_duplicate_delivery_of_a_long_reply_still_sends_it_only_once(): void
    {
        // The split messages are one logical reply and must not trip — or slip
        // past — the duplicate guard: a redelivered webhook produces nothing.
        $this->mockLlm(self::LONG_REPLY);
        $this->fakeTelegramApi();
        $channel = Channel::factory()->create();

        $this->postWebhook($channel, $this->telegramUpdate(1006))->assertOk();
        $this->postWebhook($channel, $this->telegramUpdate(1006))->assertOk();

        $this->assertCount(3, $this->sentTexts()); // 3 chunks, not 6
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);
    }
}
