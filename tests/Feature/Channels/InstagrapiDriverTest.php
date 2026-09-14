<?php

namespace Tests\Feature\Channels;

use App\Models\Channel;
use App\Services\Channels\InstagrapiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class InstagrapiDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function driver(): InstagrapiDriver
    {
        return app(InstagrapiDriver::class);
    }

    // --- Sending ---

    public function test_send_message_posts_channel_uuid_user_id_and_text_to_the_local_service(): void
    {
        Http::fake(['127.0.0.1:8001/send' => Http::response(['ok' => true], 200)]);
        $channel = Channel::factory()->instagrapi()->create();

        $this->driver()->sendMessage($channel, '17841400000000123', 'Здравствуйте! Чем помочь?');

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:8001/send'
            && $request->method() === 'POST'
            && $request['channel_uuid'] === $channel->uuid
            && $request['user_id'] === '17841400000000123'
            && $request['text'] === 'Здравствуйте! Чем помочь?');
    }

    public function test_send_message_splits_long_text_into_multiple_calls(): void
    {
        Http::fake(['127.0.0.1:8001/send' => Http::response(['ok' => true], 200)]);
        $channel = Channel::factory()->instagrapi()->create();

        $this->driver()->sendMessage($channel, '123', str_repeat('a', 1500));

        Http::assertSentCount(2);
    }

    public function test_send_message_throws_when_the_service_reports_failure(): void
    {
        Http::fake(['127.0.0.1:8001/send' => Http::response(['ok' => false, 'error' => 'Not connected. Reconnect the channel from the Channels tab.'], 200)]);
        $channel = Channel::factory()->instagrapi()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not connected');

        $this->driver()->sendMessage($channel, '123', 'Hello');
    }

    public function test_send_message_throws_on_http_failure(): void
    {
        Http::fake(['127.0.0.1:8001/send' => Http::response('', 500)]);
        $channel = Channel::factory()->instagrapi()->create();

        $this->expectException(RuntimeException::class);

        $this->driver()->sendMessage($channel, '123', 'Hello');
    }

    // --- Feedback ---

    public function test_does_not_support_message_feedback(): void
    {
        $this->assertFalse($this->driver()->supportsMessageFeedback());
    }

    public function test_send_message_with_feedback_falls_back_to_a_plain_send(): void
    {
        Http::fake(['127.0.0.1:8001/send' => Http::response(['ok' => true], 200)]);
        $channel = Channel::factory()->instagrapi()->create();

        $this->driver()->sendMessageWithFeedback($channel, '123', 'Ответ', 42);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => array_keys($request->data()) === ['channel_uuid', 'user_id', 'text']);
    }

    public function test_supports_voice_messages_is_false(): void
    {
        $this->assertFalse($this->driver()->supportsVoiceMessages());
    }

    // --- Parsing ---

    public function test_parses_the_normalized_payload_from_the_internal_route(): void
    {
        $channel = Channel::factory()->instagrapi()->create();

        $incoming = $this->driver()->parseIncomingMessage([
            'channel_uuid' => $channel->uuid,
            'from_user_id' => '17841400000000123',
            'from_username' => 'ivan_customer',
            'text' => 'Есть размер M?',
            'message_id' => 'MSG-1',
        ], $channel);

        $this->assertNotNull($incoming);
        $this->assertSame('17841400000000123', $incoming->externalUserId);
        $this->assertSame('ivan_customer', $incoming->externalUserName);
        $this->assertSame('Есть размер M?', $incoming->text);
        $this->assertSame('MSG-1', $incoming->externalMessageId);
    }

    public function test_ignores_a_payload_missing_required_fields(): void
    {
        $channel = Channel::factory()->instagrapi()->create();

        $this->assertNull($this->driver()->parseIncomingMessage(['text' => 'hi', 'message_id' => 'm1'], $channel));
        $this->assertNull($this->driver()->parseIncomingMessage(['from_user_id' => '123', 'text' => 'hi'], $channel));
        $this->assertNull($this->driver()->parseIncomingMessage(['from_user_id' => '123', 'message_id' => 'm1', 'text' => ''], $channel));
    }

    // --- Connecting ---

    public function test_connect_pushes_channel_uuid_and_credentials_and_returns_ok(): void
    {
        Http::fake(['127.0.0.1:8001/connect' => Http::response(['ok' => true, 'error_type' => null], 200)]);
        $channel = Channel::factory()->instagrapi()->create();

        $info = $this->driver()->connect($channel);

        $this->assertSame('ok', $info['status_instance']);
        $this->assertSame('test_shop', $info['username']);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:8001/connect'
            && $request['channel_uuid'] === $channel->uuid
            && $request['username'] === 'test_shop'
            && $request['password'] === 'FAKE-PASSWORD-0123456789');
    }

    public function test_connect_throws_a_clear_error_on_a_login_challenge(): void
    {
        Http::fake(['127.0.0.1:8001/connect' => Http::response(['ok' => false, 'error_type' => 'challenge_required'], 200)]);
        $channel = Channel::factory()->instagrapi()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Instagram requires verification — check your email/SMS');

        $this->driver()->connect($channel);
    }

    public function test_connect_throws_a_clear_error_on_bad_credentials(): void
    {
        Http::fake(['127.0.0.1:8001/connect' => Http::response(['ok' => false, 'error_type' => 'bad_credentials'], 200)]);
        $channel = Channel::factory()->instagrapi()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rejected the username or password');

        $this->driver()->connect($channel);
    }

    public function test_connect_throws_when_the_service_is_unreachable(): void
    {
        Http::fake(['127.0.0.1:8001/connect' => fn () => throw new ConnectionException('Connection refused')]);
        $channel = Channel::factory()->instagrapi()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Instagram service is not running. Make sure composer run dev is started.');

        $this->driver()->connect($channel);
    }

    // --- Disconnecting ---

    public function test_disconnect_tells_the_service_to_forget_the_channel_uuid(): void
    {
        Http::fake(['127.0.0.1:8001/disconnect' => Http::response(['ok' => true], 200)]);
        $channel = Channel::factory()->instagrapi()->create();

        $this->driver()->disconnect($channel);

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:8001/disconnect'
            && $request['channel_uuid'] === $channel->uuid);
    }
}
