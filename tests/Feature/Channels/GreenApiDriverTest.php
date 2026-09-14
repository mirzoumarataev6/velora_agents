<?php

namespace Tests\Feature\Channels;

use App\Models\Channel;
use App\Services\Channels\GreenApiDriver;
use App\Services\Channels\OutsideMessagingWindowException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GreenApiDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function driver(): GreenApiDriver
    {
        return app(GreenApiDriver::class);
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    protected function notification(array $messageData, string $chatId = '79001234567@c.us', string $idMessage = 'BAE5F4886F1A9'): array
    {
        return [
            'typeWebhook' => 'incomingMessageReceived',
            'instanceData' => ['idInstance' => 1101000001],
            'idMessage' => $idMessage,
            'senderData' => ['chatId' => $chatId, 'sender' => $chatId, 'senderName' => 'Ivan'],
            'messageData' => $messageData,
        ];
    }

    /** A recent inbound message, so the 24-hour window is open. */
    protected function openWindow(Channel $channel, string $userId = '79001234567'): void
    {
        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => $userId,
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Привет']);
    }

    // --- Parsing ---

    public function test_parses_text_message_from_body_wrapped_shape(): void
    {
        $channel = Channel::factory()->greenApi()->create();
        $payload = [
            'typeWebhook' => 'incomingMessageReceived',
            'instanceData' => ['idInstance' => 1101000001],
            'body' => [
                'idMessage' => 'MSG-1',
                'senderData' => ['chatId' => '79001234567@c.us', 'sender' => '79001234567@c.us'],
                'messageData' => ['typeMessage' => 'textMessage', 'textMessageData' => ['textMessage' => 'Hello']],
            ],
        ];

        $incoming = $this->driver()->parseIncomingMessage($payload, $channel);

        $this->assertNotNull($incoming);
        $this->assertSame('79001234567', $incoming->externalUserId);
        $this->assertSame('Hello', $incoming->text);
        $this->assertSame('MSG-1', $incoming->externalMessageId);
        $this->assertSame('text', $incoming->type);
    }

    public function test_parses_flat_documented_shape_with_sender_name(): void
    {
        $channel = Channel::factory()->greenApi()->create();

        $incoming = $this->driver()->parseIncomingMessage(
            $this->notification(['typeMessage' => 'extendedTextMessage', 'extendedTextMessageData' => ['text' => 'See https://shop.tj']]),
            $channel
        );

        $this->assertSame('See https://shop.tj', $incoming->text);
        $this->assertSame('Ivan', $incoming->externalUserName);
    }

    public function test_ignores_group_chats_and_non_message_notifications(): void
    {
        $channel = Channel::factory()->greenApi()->create();
        $text = ['typeMessage' => 'textMessage', 'textMessageData' => ['textMessage' => 'hi']];

        $this->assertNull($this->driver()->parseIncomingMessage($this->notification($text, '120363000000@g.us'), $channel));
        $this->assertNull($this->driver()->parseIncomingMessage(['typeWebhook' => 'outgoingMessageStatus'] + $this->notification($text), $channel));
        $this->assertSame([], $this->driver()->splitDeliveries(['typeWebhook' => 'stateInstanceChanged']));
    }

    public function test_unsupported_message_type_gets_a_placeholder_instead_of_silence(): void
    {
        $channel = Channel::factory()->greenApi()->create();

        $incoming = $this->driver()->parseIncomingMessage($this->notification(['typeMessage' => 'documentMessage']), $channel);

        $this->assertSame('unsupported', $incoming->type);
        $this->assertStringContainsString('document', $incoming->text);
    }

    public function test_audio_message_is_downloaded_transcribed_and_flagged_as_voice(): void
    {
        config()->set('agent.openai.api_key', 'test-key');
        config()->set('agent.openai.base_url', 'https://api.openai.com/v1');
        config()->set('agent.voice.ffmpeg_path', null);
        Http::fake([
            'sw-media-out.green-api.com/*' => Http::response('FAKE-OGG-BYTES', 200),
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Сколько стоит доставка?'], 200),
        ]);
        $channel = Channel::factory()->greenApi()->create();

        $incoming = $this->driver()->parseIncomingMessage($this->notification([
            'typeMessage' => 'audioMessage',
            'fileMessageData' => [
                'downloadUrl' => 'https://sw-media-out.green-api.com/1101/voice.oga',
                'fileName' => 'voice.oga',
                'mimeType' => 'audio/ogg; codecs=opus',
            ],
        ]), $channel);

        $this->assertSame('voice', $incoming->type);
        $this->assertTrue($incoming->isVoice());
        $this->assertSame('Сколько стоит доставка?', $incoming->text);
    }

    public function test_audio_message_that_cannot_be_downloaded_is_voice_failed(): void
    {
        Http::fake(['sw-media-out.green-api.com/*' => Http::response('', 404)]);
        $channel = Channel::factory()->greenApi()->create();

        $incoming = $this->driver()->parseIncomingMessage($this->notification([
            'typeMessage' => 'audioMessage',
            'fileMessageData' => ['downloadUrl' => 'https://sw-media-out.green-api.com/1101/voice.oga'],
        ]), $channel);

        $this->assertSame('voice_failed', $incoming->type);
    }

    // --- Sending ---

    public function test_send_message_posts_chat_id_with_c_us_suffix(): void
    {
        Http::fake(['*/sendMessage/*' => Http::response(['idMessage' => 'OUT-1'], 200)]);
        $channel = Channel::factory()->greenApi()->create();
        $this->openWindow($channel);

        $this->driver()->sendMessage($channel, '79001234567', 'Здравствуйте!');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://1101.api.greenapi.com/waInstance1101000001/sendMessage/FAKEGREENAPITOKEN0123456789abcdef'
            && $request['chatId'] === '79001234567@c.us'
            && $request['message'] === 'Здравствуйте!');
    }

    public function test_send_message_outside_the_24_hour_window_throws_and_sends_nothing(): void
    {
        Http::fake();
        $channel = Channel::factory()->greenApi()->create();

        $this->expectException(OutsideMessagingWindowException::class);

        try {
            $this->driver()->sendMessage($channel, '79001234567', 'Hello');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_send_message_throws_when_green_api_rejects_it(): void
    {
        Http::fake(['*/sendMessage/*' => Http::response(['message' => 'Instance not authorized'], 466)]);
        $channel = Channel::factory()->greenApi()->create();
        $this->openWindow($channel);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Instance not authorized');

        $this->driver()->sendMessage($channel, '79001234567', 'Hello');
    }

    public function test_does_not_support_message_feedback_and_sends_a_plain_reply_instead(): void
    {
        Http::fake(['*/sendMessage/*' => Http::response(['idMessage' => 'OUT-1'], 200)]);
        $channel = Channel::factory()->greenApi()->create();
        $this->openWindow($channel);

        $this->assertFalse($this->driver()->supportsMessageFeedback());

        $this->driver()->sendMessageWithFeedback($channel, '79001234567', 'Ответ', 42);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => array_keys($request->data()) === ['chatId', 'message']);
    }

    // --- Connecting ---

    public function test_connect_attempts_webhook_registration_via_set_settings(): void
    {
        Http::fake([
            '*/getStateInstance/*' => Http::response(['stateInstance' => 'authorized'], 200),
            '*/setSettings/*' => Http::response(['saveSettings' => true], 200),
        ]);
        $channel = Channel::factory()->greenApi()->create();

        $info = $this->driver()->connect($channel);

        $this->assertTrue($info['webhook_registered']);
        $this->assertNull($info['webhook_error']);
        $this->assertSame('authorized', $info['state_instance']);
        $this->assertSame(route('webhooks.greenapi', $channel), $info['webhook_url']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/waInstance1101000001/setSettings/')
            && $request['webhookUrl'] === route('webhooks.greenapi', $channel)
            && $request['webhookUrlToken'] === $info['webhook_token']
            && $request['incomingWebhook'] === 'yes');
    }

    public function test_connect_reports_but_survives_a_failed_webhook_registration(): void
    {
        Http::fake([
            '*/getStateInstance/*' => Http::response(['stateInstance' => 'authorized'], 200),
            '*/setSettings/*' => Http::response(['message' => 'Server error'], 500),
        ]);
        $channel = Channel::factory()->greenApi()->create();

        $info = $this->driver()->connect($channel);

        $this->assertFalse($info['webhook_registered']);
        $this->assertStringContainsString('500', $info['webhook_error']);
    }

    public function test_connect_rejects_bad_credentials(): void
    {
        Http::fake(['*/getStateInstance/*' => Http::response('Unauthorized', 401)]);
        $channel = Channel::factory()->greenApi()->create();

        $this->expectException(RuntimeException::class);

        $this->driver()->connect($channel);
    }

    public function test_sync_webhooks_command_re_registers_with_the_stored_token(): void
    {
        Http::fake(['*/setSettings/*' => Http::response(['saveSettings' => true], 200)]);
        $channel = Channel::factory()->greenApi()->create(['settings' => ['webhook_registered' => false]]);
        $token = $channel->credentials['webhook_token'];

        $this->artisan('greenapi:sync-webhooks')->assertSuccessful();

        $this->assertTrue($channel->fresh()->settings['webhook_registered']);
        Http::assertSent(fn ($request) => $request['webhookUrlToken'] === $token);
    }
}
