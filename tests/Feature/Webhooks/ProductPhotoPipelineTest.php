<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\Message;
use App\Models\ProductImage;
use App\Services\Channels\WikimediaImageSearch;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The agent's photo pipeline with the owner's own uploaded photos in play —
 * the trusted source checked before the catalog's image_url column, the
 * knowledge base, or Wikimedia. See ImageReplyService/PhotoReplyService.
 */
class ProductPhotoPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function agentWithProduct(bool $internetImageSearch): Agent
    {
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => $internetImageSearch]]);
        $dataset = Dataset::factory()->for($agent)->create(['row_count' => 1]);
        DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $dataset->id,
            'name' => 'OMEN 16', 'category' => 'Ноутбук (игровой)', 'price' => 16999,
        ]);

        return $agent;
    }

    protected function channelFor(Agent $agent): Channel
    {
        return Channel::factory()->for($agent)->create([
            'type' => 'telegram', 'status' => 'connected',
            'credentials' => ['bot_token' => 'FAKE-TOKEN'], 'settings' => ['webhook_secret' => 'sekret'],
        ]);
    }

    protected function update(int $updateId, string $text, int $userId = 777): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => $userId, 'first_name' => 'Customer'],
                'chat' => ['id' => $userId, 'type' => 'private'],
                'date' => time(),
                'text' => $text,
            ],
        ];
    }

    protected function fakeTelegramApi(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    protected function mockLlm(string $reply): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($reply) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturn([
                'text' => $reply, 'input_tokens' => 10, 'output_tokens' => 5,
                'finish_reason' => 'STOP', 'tool_calls' => [],
            ]);
        });
    }

    protected function jobDependencies(): array
    {
        $parameters = (new \ReflectionMethod(HandleIncomingMessageJob::class, 'handle'))->getParameters();

        return array_map(fn (\ReflectionParameter $p) => app($p->getType()?->getName()), $parameters);
    }

    public function test_a_product_with_an_uploaded_photo_is_sent_without_attribution_and_without_a_wikimedia_call(): void
    {
        // Storage::fake() does not carry the disk's own 'url' config forward
        // (see FilesystemManager::buildDiskConfiguration) — a faked disk's
        // url() is relative by default regardless of APP_URL. Passing it
        // back explicitly is what makes this test able to actually exercise
        // (and tell apart) ProductImage::url()'s APP_URL-based absolute
        // behavior from previewUrl()'s always-relative one.
        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);
        Storage::disk('public')->put('product-images/1/omen.jpg', 'fixture');
        $this->fakeTelegramApi();
        $this->mockLlm('Вот фото.');
        $this->mock(WikimediaImageSearch::class)->shouldNotReceive('search');

        // Wikimedia OFF: proves the owned photo wins on its own, not merely
        // because the opt-in fallback happened to also be disabled.
        $agent = $this->agentWithProduct(internetImageSearch: false);
        $product = DatasetProduct::where('agent_id', $agent->id)->sole();
        ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $product->id,
            'path' => 'product-images/1/omen.jpg', 'mime_type' => 'image/jpeg', 'size' => 7, 'position' => 0,
        ]);
        $channel = $this->channelFor($agent);

        (new HandleIncomingMessageJob($channel, $this->update(1, 'Покажи фото ноутбука OMEN 16')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('sent', $reply->meta['photo_status']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendPhoto')
            && str_contains($request['photo'], 'product-images/1/omen.jpg')
            // Absolute — ProductImage::url(), not previewUrl(): Telegram has
            // no origin of its own to resolve a relative URL against.
            && str_starts_with((string) $request['photo'], rtrim(config('app.url'), '/').'/storage/')
            // No licence/attribution text — only Wikimedia results ever carry one.
            && ! str_contains((string) ($request['caption'] ?? ''), 'CC BY'));
    }

    public function test_a_product_without_a_photo_and_wikimedia_off_gives_an_honest_reply_and_sends_no_web_image(): void
    {
        $this->fakeTelegramApi();
        $this->mockLlm('Вот фото.');
        $this->mock(WikimediaImageSearch::class)->shouldNotReceive('search');

        $agent = $this->agentWithProduct(internetImageSearch: false);
        $channel = $this->channelFor($agent);

        (new HandleIncomingMessageJob($channel, $this->update(2, 'Покажи фото ноутбука OMEN 16')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('missing', $reply->meta['photo_status']);
        $this->assertStringContainsString('OMEN 16', $reply->content);
        // Honest, and offers the thing it CAN still do.
        $this->assertStringContainsString('Пока нет фото', $reply->content);
        $this->assertStringContainsString('характеристики и цену', $reply->content);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sendPhoto'));
    }

    public function test_a_product_without_a_photo_and_wikimedia_on_falls_back_to_wikimedia_exactly_as_before(): void
    {
        $this->fakeTelegramApi();
        $this->mockLlm('Вот фото.');
        $this->mock(WikimediaImageSearch::class)
            ->shouldReceive('search')->once()->with('OMEN 16', 1, 'laptop')->andReturn([
                ['url' => 'https://thumb.wikimedia.org/hp-omen-16.jpg', 'caption' => 'CC BY-SA'],
            ]);

        $agent = $this->agentWithProduct(internetImageSearch: true);
        $channel = $this->channelFor($agent);

        (new HandleIncomingMessageJob($channel, $this->update(3, 'Покажи фото ноутбука OMEN 16')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('sent', $reply->meta['photo_status']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendPhoto')
            && $request['photo'] === 'https://thumb.wikimedia.org/hp-omen-16.jpg');
    }
}
