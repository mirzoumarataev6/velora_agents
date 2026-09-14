<?php

namespace Tests\Feature\Webhooks;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A 👍/👎 tap end to end: a real webhook POST, carrying the callback_query
 * exactly as Telegram shapes it, through the controller, the queued job and
 * the recorder, to the rating row and Telegram's acknowledgement calls.
 *
 * The callback_data is taken from the keyboard the pipeline actually sent,
 * not constructed by the test, so the message id genuinely round-trips.
 */
class TelegramFeedbackCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected const SECRET = 'webhook-secret-123';

    protected const STORED_URL = 'https://example.test/webhooks/telegram/abc';

    protected Channel $channel;

    protected int $updateId = 900;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = Agent::factory()->for(User::factory())->create();
        $this->channel = Channel::factory()->for($agent)->create([
            'type' => 'telegram',
            'status' => 'connected',
            'credentials' => ['bot_token' => 'BOT:TOKEN'],
            'settings' => [
                'webhook_secret' => self::SECRET,
                'webhook_url' => self::STORED_URL,
                'bot_username' => 'PixelShop_khujand_bot',
            ],
        ]);

        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 42, 'allowed_updates' => ['message', 'callback_query']],
        ], 200)]);

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturn([
                'text' => 'Пожалуйста! Обращайтесь.', 'tool_calls' => [], 'input_tokens' => 10, 'output_tokens' => 5,
            ]);
        });
    }

    protected function postUpdate(array $update, string $secret = self::SECRET)
    {
        return $this->postJson(
            route('webhooks.telegram', $this->channel),
            $update,
            ['X-Telegram-Bot-Api-Secret-Token' => $secret],
        );
    }

    protected function customerSays(string $text): void
    {
        $this->updateId++;

        $this->postUpdate([
            'update_id' => $this->updateId,
            'message' => [
                'message_id' => $this->updateId,
                'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Ivan', 'language_code' => 'ru'],
                'chat' => ['id' => 555, 'first_name' => 'Ivan', 'type' => 'private'],
                'date' => 1757570000,
                'text' => $text,
            ],
        ])->assertOk();
    }

    /** @return array<int, array<string, mixed>> */
    protected function callsTo(string $method): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), "/{$method}"))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    /** A callback_query in the exact shape Telegram delivers it. */
    protected function tap(string $callbackData, string $messageText): array
    {
        $this->updateId++;

        return [
            'update_id' => $this->updateId,
            'callback_query' => [
                'id' => '4382bfdwdsb323b2d9',
                'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Ivan', 'language_code' => 'ru'],
                'message' => [
                    'message_id' => 42,
                    'from' => ['id' => 7000000001, 'is_bot' => true, 'first_name' => 'Pixel', 'username' => 'PixelShop_khujand_bot'],
                    'chat' => ['id' => 555, 'first_name' => 'Ivan', 'type' => 'private'],
                    'date' => 1757570001,
                    'text' => $messageText,
                    'reply_markup' => ['inline_keyboard' => [[
                        ['text' => '👍', 'callback_data' => str_replace(':down:', ':up:', $callbackData)],
                        ['text' => '👎', 'callback_data' => str_replace(':up:', ':down:', $callbackData)],
                    ]]],
                ],
                'chat_instance' => '-5319174261236410582',
                'data' => $callbackData,
            ],
        ];
    }

    public function test_a_tap_on_a_real_prompt_is_recorded_and_acknowledged(): void
    {
        $this->customerSays('У вас есть ноутбуки?');
        $this->customerSays('Сколько стоит доставка?');
        $this->customerSays('Спасибо!');

        $prompt = collect($this->callsTo('sendMessage'))->first(fn ($body) => isset($body['reply_markup']));
        $this->assertNotNull($prompt, 'The closing turn should have carried the keyboard.');

        $thumbsDown = json_decode($prompt['reply_markup'], true)['inline_keyboard'][0][1]['callback_data'];
        $reply = Message::where('role', 'assistant')->latest('id')->firstOrFail();
        $this->assertSame("fb:down:{$reply->id}", $thumbsDown);

        $this->postUpdate($this->tap($thumbsDown, $prompt['text']))->assertOk();

        $this->assertSame(Message::RATING_DOWN, $reply->fresh()->rating);

        $answer = $this->callsTo('answerCallbackQuery');
        $this->assertCount(1, $answer);
        $this->assertSame('4382bfdwdsb323b2d9', $answer[0]['callback_query_id']);
        $this->assertSame(__('feedback.confirm.thanks', [], 'ru'), $answer[0]['text']);

        $edit = $this->callsTo('editMessageReplyMarkup');
        $this->assertCount(1, $edit);
        $this->assertSame('555', (string) $edit[0]['chat_id']);
        $this->assertSame('42', (string) $edit[0]['message_id']);
    }

    public function test_a_tap_without_the_webhook_secret_is_rejected_before_routing(): void
    {
        $this->customerSays('Сколько стоит доставка?');
        $reply = Message::where('role', 'assistant')->latest('id')->firstOrFail();

        $this->postUpdate($this->tap("fb:up:{$reply->id}", 'x'), 'wrong-secret')->assertForbidden();

        $this->assertNull($reply->fresh()->rating);
        $this->assertSame([], $this->callsTo('answerCallbackQuery'));
    }

    /**
     * The live-bot fix: a bot connected before callback_query was subscribed
     * keeps receiving only messages until its webhook is re-registered.
     */
    public function test_sync_webhooks_resubscribes_with_callback_query_keeping_url_and_secret(): void
    {
        $this->artisan('telegram:sync-webhooks')->assertSuccessful();

        $set = $this->callsTo('setWebhook');
        $this->assertCount(1, $set);
        $this->assertSame(self::STORED_URL, $set[0]['url']);
        $this->assertSame(self::SECRET, $set[0]['secret_token']);
        $this->assertContains('callback_query', $set[0]['allowed_updates']);
    }
}
