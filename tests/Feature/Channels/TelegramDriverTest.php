<?php

namespace Tests\Feature\Channels;

use App\Models\Channel;
use App\Services\Channels\TelegramDriver;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeTelegramFileApi(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg']], 200),
        ]);
    }

    protected function photoPayload(int $updateId, ?string $caption = null): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'from' => ['id' => 1, 'first_name' => 'Test'],
                'chat' => ['id' => 1],
                'photo' => [['file_id' => 'abc', 'file_size' => 100]],
                'caption' => $caption,
            ],
        ];
    }

    public function test_send_message_splits_text_longer_than_telegram_limit(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $channel = Channel::factory()->create();
        $longText = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 100); // ~5800 chars
        $this->assertGreaterThan(4096, mb_strlen($longText));

        app(TelegramDriver::class)->sendMessage($channel, '555', $longText);

        $sent = [];
        Http::assertSent(function ($request) use (&$sent) {
            if (str_contains($request->url(), 'sendMessage')) {
                $sent[] = $request['text'];
            }

            return true;
        });

        $this->assertGreaterThanOrEqual(2, count($sent));
        foreach ($sent as $chunk) {
            $this->assertLessThanOrEqual(4096, mb_strlen($chunk));
        }
        // No content lost or duplicated across the split (ignoring the whitespace
        // consumed exactly at each split boundary, e.g. ". " or "\n").
        $normalize = fn (string $s) => preg_replace('/\s+/', '', $s);
        $this->assertSame($normalize($longText), $normalize(implode('', $sent)));
    }

    public function test_send_message_does_not_split_short_text(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $channel = Channel::factory()->create();
        app(TelegramDriver::class)->sendMessage($channel, '555', 'Short reply.');

        Http::assertSentCount(1);
    }

    public function test_confidently_identified_photo_is_parsed_as_photo_type(): void
    {
        $this->fakeTelegramFileApi();
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromImage')->andReturn([
                'text' => 'Red Nike sneakers, size 42', 'input_tokens' => 10, 'output_tokens' => 5,
            ]);
        });

        $channel = Channel::factory()->create();
        $incoming = app(TelegramDriver::class)->parseIncomingMessage($this->photoPayload(1), $channel);

        $this->assertSame('photo', $incoming->type);
        $this->assertStringContainsString('Red Nike sneakers', $incoming->text);
    }

    public function test_unclear_photo_without_caption_is_parsed_as_photo_unclear(): void
    {
        $this->fakeTelegramFileApi();
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromImage')->andReturn([
                'text' => 'UNKNOWN', 'input_tokens' => 10, 'output_tokens' => 2,
            ]);
        });

        $channel = Channel::factory()->create();
        $incoming = app(TelegramDriver::class)->parseIncomingMessage($this->photoPayload(2), $channel);

        $this->assertSame('photo_unclear', $incoming->type);
    }

    public function test_vision_failure_is_treated_as_unclear_not_an_error(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        $channel = Channel::factory()->create();
        $incoming = app(TelegramDriver::class)->parseIncomingMessage($this->photoPayload(3), $channel);

        $this->assertSame('photo_unclear', $incoming->type);
    }

    public function test_photo_with_caption_uses_caption_regardless_of_vision_confidence(): void
    {
        $this->fakeTelegramFileApi();
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('extractFromImage')->andReturn([
                'text' => 'UNKNOWN', 'input_tokens' => 5, 'output_tokens' => 1,
            ]);
        });

        $channel = Channel::factory()->create();
        $incoming = app(TelegramDriver::class)->parseIncomingMessage(
            $this->photoPayload(4, 'Ин ноутбук доред?'),
            $channel
        );

        // A caption means the customer told us what they want — never fall back
        // to "please tell us the product name" when they already did.
        $this->assertSame('photo', $incoming->type);
        $this->assertStringContainsString('Ин ноутбук доред?', $incoming->text);
    }
}
