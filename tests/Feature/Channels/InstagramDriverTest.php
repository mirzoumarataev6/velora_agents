<?php

namespace Tests\Feature\Channels;

use App\Models\Channel;
use App\Services\Channels\InstagramDriver;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class InstagramDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function driver(): InstagramDriver
    {
        return app(InstagramDriver::class);
    }

    public function test_confident_image_attachment_downloads_and_includes_vision_description(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromImage')->once()->andReturn(['text' => 'Samsung Galaxy S23 box', 'input_tokens' => 5, 'output_tokens' => 3]);
        });

        Http::fake([
            'cdn.fbsbx.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $channel = Channel::factory()->instagram()->create();
        $payload = [
            'entry' => [['messaging' => [[
                'sender' => ['id' => '778899'],
                'message' => [
                    'mid' => 'mid.IMG1',
                    'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.fbsbx.com/photo.jpg']]],
                ],
            ]]]],
        ];

        $incoming = $this->driver()->parseIncomingMessage($payload, $channel);

        $this->assertSame('photo', $incoming->type);
        $this->assertStringContainsString('Samsung Galaxy S23 box', $incoming->text);
    }

    public function test_unclear_image_attachment_is_flagged_photo_unclear(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromImage')->once()->andReturn(['text' => 'UNKNOWN', 'input_tokens' => 5, 'output_tokens' => 1]);
        });

        Http::fake(['cdn.fbsbx.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $channel = Channel::factory()->instagram()->create();
        $payload = [
            'entry' => [['messaging' => [[
                'sender' => ['id' => '778899'],
                'message' => [
                    'mid' => 'mid.IMG2',
                    'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.fbsbx.com/photo2.jpg']]],
                ],
            ]]]],
        ];

        $incoming = $this->driver()->parseIncomingMessage($payload, $channel);

        $this->assertSame('photo_unclear', $incoming->type);
    }

    public function test_caption_alongside_attachment_takes_priority_over_unclear_vision(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromImage')->once()->andReturn(['text' => 'UNKNOWN', 'input_tokens' => 5, 'output_tokens' => 1]);
        });

        Http::fake(['cdn.fbsbx.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $channel = Channel::factory()->instagram()->create();
        $payload = [
            'entry' => [['messaging' => [[
                'sender' => ['id' => '778899'],
                'message' => [
                    'mid' => 'mid.IMG3',
                    'text' => 'Есть такое платье?',
                    'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.fbsbx.com/photo3.jpg']]],
                ],
            ]]]],
        ];

        $incoming = $this->driver()->parseIncomingMessage($payload, $channel);

        $this->assertSame('photo', $incoming->type);
        $this->assertStringContainsString('Есть такое платье?', $incoming->text);
    }

    public function test_echo_message_is_ignored(): void
    {
        $channel = Channel::factory()->instagram()->create();
        $payload = [
            'entry' => [['messaging' => [[
                'sender' => ['id' => 'IG_BUSINESS_ID'],
                'message' => ['mid' => 'mid.ECHO', 'text' => 'Our own reply', 'is_echo' => true],
            ]]]],
        ];

        $this->assertNull($this->driver()->parseIncomingMessage($payload, $channel));
    }

    public function test_non_message_event_is_ignored(): void
    {
        $channel = Channel::factory()->instagram()->create();
        $payload = [
            'entry' => [['messaging' => [[
                'sender' => ['id' => '778899'],
                'read' => ['mid' => 'mid.READ'],
            ]]]],
        ];

        $this->assertNull($this->driver()->parseIncomingMessage($payload, $channel));
    }

    public function test_connect_validates_credentials_and_returns_account_info(): void
    {
        Http::fake([
            'graph.facebook.com/*/9876543210*' => Http::response(['username' => 'test_ig_business', 'name' => 'Test Business'], 200),
        ]);
        $channel = Channel::factory()->instagram()->create();

        $info = $this->driver()->connect($channel);

        $this->assertSame('test_ig_business', $info['ig_username']);
        $this->assertSame('Test Business', $info['ig_name']);
        $this->assertStringContainsString('/webhooks/instagram/', $info['webhook_url']);
    }

    public function test_connect_throws_when_meta_rejects_credentials(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 401),
        ]);
        $channel = Channel::factory()->instagram()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid OAuth access token');

        $this->driver()->connect($channel);
    }

    public function test_send_message_posts_to_messages_endpoint(): void
    {
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'mid.OUT'], 200)]);
        $channel = Channel::factory()->instagram()->create();

        $this->driver()->sendMessage($channel, '778899', 'Добрый день!');

        Http::assertSent(fn ($request) => str_contains($request->url(), '9876543210/messages')
            && $request['recipient']['id'] === '778899'
            && $request['message']['text'] === 'Добрый день!');
    }

    public function test_connect_routes_legacy_eaa_token_to_graph_facebook_com(): void
    {
        Http::fake([
            'graph.facebook.com/*/9876543210*' => Http::response(['username' => 'test_ig_business', 'name' => 'Test Business'], 200),
        ]);
        $channel = Channel::factory()->instagram()->create([
            'credentials' => [
                'ig_business_account_id' => '9876543210',
                'page_access_token' => 'EAAGfakelegacytoken',
                'verify_token' => 'verify-token',
            ],
        ]);

        $info = $this->driver()->connect($channel);

        $this->assertSame('test_ig_business', $info['ig_username']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_connect_routes_igaa_token_to_graph_instagram_com(): void
    {
        Http::fake([
            'graph.instagram.com/*/9876543210*' => Http::response(['username' => 'test_ig_business', 'name' => 'Test Business'], 200),
        ]);
        $channel = Channel::factory()->instagram()->create([
            'credentials' => [
                'ig_business_account_id' => '9876543210',
                'page_access_token' => 'IGAAfakenewtoken',
                'verify_token' => 'verify-token',
            ],
        ]);

        $info = $this->driver()->connect($channel);

        $this->assertSame('test_ig_business', $info['ig_username']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.instagram.com'));
    }

    public function test_connect_with_igaa_token_against_graph_facebook_com_fails_as_before_the_fix(): void
    {
        // Regression guard: an IGAA token must never be sent to graph.facebook.com —
        // if it is, Meta rejects it with "Cannot parse access token" even though the
        // token itself is valid. This fakes ONLY graph.instagram.com so the test fails
        // loudly (unmatched fake / connection exception) if routing regresses back to
        // graph.facebook.com for IGAA tokens.
        Http::fake([
            'graph.instagram.com/*/9876543210*' => Http::response(['username' => 'test_ig_business', 'name' => 'Test Business'], 200),
        ]);
        $channel = Channel::factory()->instagram()->create([
            'credentials' => [
                'ig_business_account_id' => '9876543210',
                'page_access_token' => 'IGAAfakenewtoken',
                'verify_token' => 'verify-token',
            ],
        ]);

        $info = $this->driver()->connect($channel);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
        $this->assertSame('test_ig_business', $info['ig_username']);
    }

    public function test_send_message_routes_igaa_token_to_graph_instagram_com(): void
    {
        Http::fake(['graph.instagram.com/*/messages' => Http::response(['message_id' => 'mid.OUT'], 200)]);
        $channel = Channel::factory()->instagram()->create([
            'credentials' => [
                'ig_business_account_id' => '9876543210',
                'page_access_token' => 'IGAAfakenewtoken',
                'verify_token' => 'verify-token',
            ],
        ]);

        $this->driver()->sendMessage($channel, '778899', 'Добрый день!');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.instagram.com')
            && str_contains($request->url(), '9876543210/messages')
            && $request['recipient']['id'] === '778899'
            && $request['message']['text'] === 'Добрый день!');
    }
}
