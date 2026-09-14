<?php

namespace Tests\Feature\Channels;

use App\Models\Channel;
use App\Services\Channels\OutsideMessagingWindowException;
use App\Services\Channels\WhatsAppDriver;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WhatsAppDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function driver(): WhatsAppDriver
    {
        return app(WhatsAppDriver::class);
    }

    // --- Photo handling: two-step media download + vision confidence ---

    public function test_confident_image_message_downloads_media_and_includes_vision_description(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromImage')->once()->andReturn(['text' => 'Nike Air Max, black', 'input_tokens' => 5, 'output_tokens' => 3]);
        });

        Http::fake([
            'graph.facebook.com/*/media123' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/media123'], 200),
            'lookaside.fbsbx.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $channel = Channel::factory()->whatsapp()->create();
        $payload = [
            'entry' => [['changes' => [['value' => [
                'contacts' => [['profile' => ['name' => 'Ivan']]],
                'messages' => [[
                    'from' => '15550001234',
                    'id' => 'wamid.IMG1',
                    'type' => 'image',
                    'image' => ['id' => 'media123', 'mime_type' => 'image/jpeg'],
                ]],
            ]]]]],
        ];

        $incoming = $this->driver()->parseIncomingMessage($payload, $channel);

        $this->assertNotNull($incoming);
        $this->assertSame('photo', $incoming->type);
        $this->assertStringContainsString('Nike Air Max, black', $incoming->text);
    }

    public function test_unclear_image_message_is_flagged_photo_unclear(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromImage')->once()->andReturn(['text' => 'UNKNOWN', 'input_tokens' => 5, 'output_tokens' => 1]);
        });

        Http::fake([
            'graph.facebook.com/*/media456' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/media456'], 200),
            'lookaside.fbsbx.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $channel = Channel::factory()->whatsapp()->create();
        $payload = [
            'entry' => [['changes' => [['value' => [
                'messages' => [[
                    'from' => '15550001234',
                    'id' => 'wamid.IMG2',
                    'type' => 'image',
                    'image' => ['id' => 'media456', 'mime_type' => 'image/jpeg'],
                ]],
            ]]]]],
        ];

        $incoming = $this->driver()->parseIncomingMessage($payload, $channel);

        $this->assertSame('photo_unclear', $incoming->type);
    }

    public function test_caption_takes_priority_and_is_always_included_even_when_vision_is_unclear(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromImage')->once()->andReturn(['text' => 'UNKNOWN', 'input_tokens' => 5, 'output_tokens' => 1]);
        });

        Http::fake([
            'graph.facebook.com/*/media789' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/media789'], 200),
            'lookaside.fbsbx.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $channel = Channel::factory()->whatsapp()->create();
        $payload = [
            'entry' => [['changes' => [['value' => [
                'messages' => [[
                    'from' => '15550001234',
                    'id' => 'wamid.IMG3',
                    'type' => 'image',
                    'image' => ['id' => 'media789', 'mime_type' => 'image/jpeg', 'caption' => 'Есть такое платье?'],
                ]],
            ]]]]],
        ];

        $incoming = $this->driver()->parseIncomingMessage($payload, $channel);

        $this->assertSame('photo', $incoming->type); // caption present -> never "unclear", even if vision failed
        $this->assertStringContainsString('Есть такое платье?', $incoming->text);
    }

    // --- 24-hour customer service window ---

    /**
     * Outside the window the driver REFUSES rather than returning quietly. It
     * used to return, so the caller recorded a Message row and reported
     * success — a transcript showing the operator a reply the customer never
     * got. Nothing is sent either way; the difference is whether anyone finds
     * out.
     */
    public function test_send_message_is_refused_outside_the_24_hour_customer_service_window(): void
    {
        Http::fake();
        $channel = Channel::factory()->whatsapp()->create();
        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '15550001234',
            'status' => 'ai',
        ]);
        $message = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Здравствуйте',
            'external_message_id' => 'wamid.OLD',
        ]);
        // created_at isn't mass-assignable (Eloquent stamps it automatically on
        // create()); force it back 25h to simulate a stale conversation.
        $message->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->expectException(OutsideMessagingWindowException::class);

        try {
            $this->driver()->sendMessage($channel, '15550001234', 'Это сообщение не должно уйти.');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_send_message_succeeds_within_the_24_hour_customer_service_window(): void
    {
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        $channel = Channel::factory()->whatsapp()->create();
        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '15550001234',
            'status' => 'ai',
        ]);
        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Здравствуйте',
            'external_message_id' => 'wamid.RECENT',
            'created_at' => now()->subHours(2),
        ]);

        $this->driver()->sendMessage($channel, '15550001234', 'Добрый день!');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages') && $request['text']['body'] === 'Добрый день!');
    }

    // --- connect() ---

    public function test_connect_validates_credentials_and_returns_account_info(): void
    {
        Http::fake([
            'graph.facebook.com/*/1234567890*' => Http::response(['display_phone_number' => '+1 555-0100', 'verified_name' => 'Test Business'], 200),
        ]);
        $channel = Channel::factory()->whatsapp()->create();

        $info = $this->driver()->connect($channel);

        $this->assertSame('+1 555-0100', $info['display_phone_number']);
        $this->assertSame('Test Business', $info['verified_name']);
        $this->assertStringContainsString('/webhooks/whatsapp/', $info['webhook_url']);
    }

    public function test_connect_throws_when_meta_rejects_credentials(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 401),
        ]);
        $channel = Channel::factory()->whatsapp()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid OAuth access token');

        $this->driver()->connect($channel);
    }
}
