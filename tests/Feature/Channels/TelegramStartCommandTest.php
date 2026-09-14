<?php

namespace Tests\Feature\Channels;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * /start is answered with the owner's configured greeting, verbatim, and never
 * by the model — which, handed the literal text "/start", answered a Russian
 * shop's customer in English about "restarting something".
 */
class TelegramStartCommandTest extends TestCase
{
    use RefreshDatabase;

    protected const GREETING = 'Здравствуйте, чем могу вам помочь?';

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200)]);

        // The whole point: no model call of any kind on this path.
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('generate')->never();
            $mock->shouldReceive('embed')->never();
        });
    }

    /** @param  array<string, mixed>  $agentAttributes */
    protected function channelFor(array $agentAttributes): Channel
    {
        $agent = Agent::factory()->for(User::factory())->create($agentAttributes);

        return $this->channel = Channel::factory()->for($agent)->create([
            'type' => 'telegram',
            'status' => 'connected',
            'credentials' => ['bot_token' => 'BOT:TOKEN'],
            'settings' => ['webhook_secret' => 'secret'],
        ]);
    }

    protected function send(string $text, int $updateId = 700): void
    {
        app()->call([new HandleIncomingMessageJob($this->channel, [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => 555, 'first_name' => 'Ivan', 'language_code' => 'en'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'date' => time(),
                'text' => $text,
                'entities' => [['offset' => 0, 'length' => 6, 'type' => 'bot_command']],
            ],
        ]), 'handle']);
    }

    /** @return array<int, array<string, mixed>> */
    protected function sentMessages(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    /** @return array<string, array{0: string}> */
    public static function startCommands(): array
    {
        return [
            'bare' => ['/start'],
            'addressed to the bot' => ['/start@PixelShop_khujand_bot'],
            'deep-link payload' => ['/start promo_summer'],
        ];
    }

    #[DataProvider('startCommands')]
    public function test_start_sends_the_configured_greeting_verbatim_with_no_llm_call(string $command): void
    {
        $this->channelFor(['response_format' => ['greeting_message' => self::GREETING]]);

        $this->send($command);

        $sent = $this->sentMessages();
        $this->assertCount(1, $sent);
        $this->assertSame(self::GREETING, $sent[0]['text']);
        $this->assertArrayNotHasKey('reply_markup', $sent[0], 'A greeting is never rated.');

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame(self::GREETING, $reply->content);
        $this->assertTrue($reply->meta['session_start']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function agentLanguages(): array
    {
        return [
            'russian agent' => ['ru', 'ru'],
            'tajik agent' => ['tj', 'tg'],
            'english agent' => ['en', 'en'],
        ];
    }

    #[DataProvider('agentLanguages')]
    public function test_a_blank_greeting_falls_back_to_a_default_in_the_agents_language(string $language, string $locale): void
    {
        $this->channelFor(['language' => $language, 'response_format' => ['greeting_message' => '  ']]);

        $this->send('/start');

        $this->assertSame(__('replies.greeting', [], $locale), $this->sentMessages()[0]['text']);
    }

    /** "auto" and a brand-new customer: nothing to detect, so the app default. */
    public function test_an_auto_language_agent_with_no_history_uses_the_app_locale(): void
    {
        $this->channelFor(['language' => 'auto', 'response_format' => []]);

        $this->send('/start');

        $this->assertSame(__('replies.greeting', [], config('app.locale')), $this->sentMessages()[0]['text']);
    }

    /** The owner's own link command is still theirs, not a customer greeting. */
    public function test_the_owner_notify_link_is_not_treated_as_a_greeting(): void
    {
        $this->channelFor(['response_format' => ['greeting_message' => self::GREETING]]);

        $this->send('/start notify_ABC123');

        $this->assertNotSame(self::GREETING, $this->sentMessages()[0]['text']);
        $this->assertSame(0, Message::count());
    }
}
