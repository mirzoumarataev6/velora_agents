<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\Message;
use App\Services\Channels\PhotoRequestContext;
use App\Services\Channels\WikimediaImageSearch;
use App\Services\Dataset\ProductSearchService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Replays the reported PixelShop ("Pixel" agent) transcript: a customer
 * asking to see MacBook photos was offered a wrong-brand HP menu, the menu
 * was re-shown after being answered, a photo "sent" confirmation was
 * fabricated in free text, and a wrong-model Wikimedia photo was presented as
 * the right one. Each test here is one reported failure, reproduced against
 * the 9-row catalog the bug report used.
 */
class PixelShopPhotoFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array{0: string, 1: string, 2: int}> name, category, price */
    protected static function catalogRows(): array
    {
        return [
            ['HP 15', 'Ноутбук', 4699],
            ['HP VICTUS', 'Ноутбук (игровой)', 14999],
            ['HP OMEN 16', 'Ноутбук (игровой)', 16999],
            ['Macbook Air 15', 'Ноутбук', 11999],
            ['Macbook Air 13.6', 'Ноутбук', 10499],
            // Same name, two configurations — the exact shape that must be
            // disambiguated rather than silently collapsed to one.
            ['Macbook Neo 13', 'Ноутбук', 8199],
            ['Macbook Neo 13', 'Ноутбук', 8900],
            ['Macbook Pro 14', 'Ноутбук', 17999],
            ['27f LED', 'Монитор', 2199],
        ];
    }

    protected function pixelShop(): Agent
    {
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => true]]);
        $dataset = Dataset::factory()->for($agent)->create(['row_count' => count(self::catalogRows())]);

        foreach (self::catalogRows() as $position => [$name, $category, $price]) {
            DatasetProduct::factory()->create([
                'agent_id' => $agent->id,
                'dataset_id' => $dataset->id,
                'position' => $position,
                'name' => $name,
                'category' => $category,
                'price' => $price,
            ]);
        }

        return $agent;
    }

    protected function channelFor(Agent $agent): Channel
    {
        return Channel::factory()->for($agent)->create([
            'type' => 'telegram',
            'status' => 'connected',
            'credentials' => ['bot_token' => 'FAKE-TOKEN'],
            'settings' => ['webhook_secret' => 'sekret'],
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

    /** Outbound sendMessage texts, in the order Telegram received them. */
    protected function sentMessages(): array
    {
        $sent = [];
        Http::assertSent(function ($request) use (&$sent) {
            if (str_contains($request->url(), 'sendMessage')) {
                $sent[] = $request['text'];
            }

            return true;
        });

        return $sent;
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

    /** A conversation with a pending clarify menu already offered, as PhotoReplyService would have stored it. */
    protected function pendingMenuConversation(Agent $agent, Channel $channel, int $externalUserId): Conversation
    {
        $conversation = $agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => (string) $externalUserId,
            'status' => 'ai',
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => "Какую модель показать?\n1. Macbook Neo 13 — 8199\n2. Macbook Neo 13 — 8900\n3. Macbook Air 15 — 11999",
            'meta' => [
                'photo_status' => 'clarify',
                'photo_choices' => [
                    ['name' => 'Macbook Neo 13', 'price' => '8199'],
                    ['name' => 'Macbook Neo 13', 'price' => '8900'],
                    ['name' => 'Macbook Air 15', 'price' => '11999'],
                ],
                'photo_context' => '',
            ],
        ]);

        return $conversation;
    }

    // --- #1 / #5 / #6: catalog grounding (Cyrillic query, no invented brands) --

    public function test_a_cyrillic_macbook_query_finds_the_real_macbooks_not_nothing(): void
    {
        $agent = $this->pixelShop();

        $found = app(ProductSearchService::class)->search($agent, ['query' => 'макбук', 'limit' => 20]);

        $this->assertGreaterThan(0, $found['count']);
        $names = array_unique(array_column($found['products'], 'name'));
        sort($names);
        $this->assertSame(['Macbook Air 13.6', 'Macbook Air 15', 'Macbook Neo 13', 'Macbook Pro 14'], $names);
    }

    public function test_a_cyrillic_model_query_finds_the_one_matching_hp_product(): void
    {
        $agent = $this->pixelShop();

        $found = app(ProductSearchService::class)->search($agent, ['query' => 'виктус', 'limit' => 20]);

        $this->assertSame(1, $found['count']);
        $this->assertSame('HP VICTUS', $found['products'][0]['name']);
    }

    public function test_browsing_the_whole_catalog_never_surfaces_an_invented_brand(): void
    {
        $agent = $this->pixelShop();

        $found = app(ProductSearchService::class)->search($agent, []);

        $names = implode(' ', array_column($found['products'], 'name'));
        foreach (['Lenovo', 'Acer', 'Asus', 'Legion', 'IdeaPad', 'Aspire', 'VivoBook', 'ROG'] as $invented) {
            $this->assertStringNotContainsStringIgnoringCase($invented, $names);
        }
    }

    // --- #2: pending menu resolution, aliases, ordinals, duplicates ----------

    public function test_an_alias_matching_two_configurations_asks_which_one_instead_of_picking_one(): void
    {
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);
        $conversation = $this->pendingMenuConversation($agent, $channel, 777);
        $user = $conversation->messages()->create(['role' => 'user', 'content' => 'Нео']);

        $resolved = app(PhotoRequestContext::class)->resolve($conversation, $user);

        $this->assertTrue($resolved['requested']);
        $this->assertSame('', $resolved['model']);
        $this->assertCount(2, $resolved['ambiguous']);
        $this->assertSame(['8199', '8900'], array_column($resolved['ambiguous'], 'price'));
    }

    public function test_an_ordinal_resolves_against_the_pending_menu(): void
    {
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);
        $conversation = $this->pendingMenuConversation($agent, $channel, 777);
        $user = $conversation->messages()->create(['role' => 'user', 'content' => '1']);

        $resolved = app(PhotoRequestContext::class)->resolve($conversation, $user);

        $this->assertTrue($resolved['requested']);
        $this->assertSame('Macbook Neo 13', $resolved['model']);
    }

    public function test_all_is_recognised_against_a_pending_menu(): void
    {
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);
        $conversation = $this->pendingMenuConversation($agent, $channel, 777);
        $user = $conversation->messages()->create(['role' => 'user', 'content' => 'Все']);

        $resolved = app(PhotoRequestContext::class)->resolve($conversation, $user);

        $this->assertTrue($resolved['requested']);
        $this->assertTrue($resolved['all']);
        $this->assertCount(3, $resolved['choices']);
    }

    /** "Все" against the pending menu sends every offered option, never a refusal. */
    public function test_all_sends_every_offered_option_rather_than_refusing(): void
    {
        $this->fakeTelegramApi();
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);
        $this->pendingMenuConversation($agent, $channel, 777);
        $this->mock(WikimediaImageSearch::class)->shouldReceive('search')->times(3)->andReturn([
            ['url' => 'https://thumb.wikimedia.org/generic-laptop.jpg', 'caption' => '© Test · CC BY-SA 4.0'],
        ]);

        (new HandleIncomingMessageJob($channel, $this->update(1, 'Все')))->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->latest('id')->first();
        $this->assertSame('sent_all', $reply->meta['photo_status']);
        $this->assertSame(3, $reply->meta['photo_count']);
        $this->assertStringNotContainsString('не могу', $reply->content);
        $photos = Http::recorded(fn ($r) => str_contains($r->url(), '/sendPhoto'));
        $this->assertCount(3, $photos);
    }

    // --- #8: an explicit Cyrillic model name goes straight to its photo ------

    public function test_a_cyrillic_model_name_goes_straight_to_its_photo_with_no_pending_menu(): void
    {
        $this->fakeTelegramApi();
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);
        $this->mock(WikimediaImageSearch::class)->shouldReceive('search')->once()
            ->with('HP VICTUS', 1, 'laptop')->andReturn([
                ['url' => 'https://thumb.wikimedia.org/hp-victus.jpg', 'caption' => '© Test · CC BY-SA 4.0 · https://commons.wikimedia.org/wiki/File:HP_VICTUS.jpg'],
            ]);

        (new HandleIncomingMessageJob($channel, $this->update(1, 'А как выглядит виктус')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('sent', $reply->meta['photo_status']);
        $this->assertSame('HP VICTUS', $reply->meta['photo_model']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendPhoto')
            && $r['photo'] === 'https://thumb.wikimedia.org/hp-victus.jpg');
    }

    // --- #9: a wrong-model Wikimedia result is rejected, not relabelled -------

    public function test_a_wrong_model_wikimedia_result_is_rejected_with_an_honest_fallback(): void
    {
        $this->fakeTelegramApi();
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);
        // A lexical hit for "HP" that is not actually a VICTUS photo —
        // WikimediaImageSearch::titleMatchesModelQuery must reject it.
        Http::fake(['commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [
            ['title' => 'File:HP Laptop 14S.jpg', 'imageinfo' => [[
                'thumburl' => 'https://thumb.wikimedia.org/hp-14s.jpg',
                'extmetadata' => [
                    'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                    'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
                ],
            ]]],
        ]]], 200)]);

        (new HandleIncomingMessageJob($channel, $this->update(1, 'А как выглядит виктус')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('missing', $reply->meta['photo_status']);
        $this->assertSame('HP VICTUS', $reply->meta['photo_model']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendPhoto'));
    }

    // --- #4: a delivery nudge retries, instead of reopening the menu ----------

    public function test_a_delivery_nudge_retries_the_same_model_instead_of_reopening_the_menu(): void
    {
        $this->fakeTelegramApi();
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);
        $search = $this->mock(WikimediaImageSearch::class);
        $search->shouldReceive('search')->once()->with('HP VICTUS', 1, 'laptop')->andReturn([]);
        $search->shouldReceive('search')->once()->with('HP VICTUS', 1, 'laptop')->andReturn([
            ['url' => 'https://thumb.wikimedia.org/hp-victus.jpg', 'caption' => 'CC BY-SA'],
        ]);

        (new HandleIncomingMessageJob($channel, $this->update(1, 'А как выглядит виктус')))
            ->handle(...$this->jobDependencies());
        (new HandleIncomingMessageJob($channel, $this->update(2, 'Алоооо')))
            ->handle(...$this->jobDependencies());

        $replies = Message::where('role', 'assistant')->orderBy('id')->get();
        $this->assertCount(2, $replies);
        $this->assertSame('missing', $replies[0]->meta['photo_status']);
        $this->assertSame('sent', $replies[1]->meta['photo_status']);
        $this->assertSame('HP VICTUS', $replies[1]->meta['photo_model']);
        // Not a re-opened brand/model menu: no numbered list in the retry.
        $this->assertDoesNotMatchRegularExpression('/\d\.\s/', $replies[1]->content);
    }

    // --- #3 / #10: the plain-text LLM path cannot fabricate a delivery -------

    public function test_a_hallucinated_sent_confirmation_from_free_text_is_stripped(): void
    {
        $this->fakeTelegramApi();
        $this->mockLlm('Фото Macbook Neo 13 отправлено.');
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);

        // Nothing about this message is a photo request, so it must go
        // through the ordinary text pipeline — exactly where no image is
        // ever actually sent.
        (new HandleIncomingMessageJob($channel, $this->update(1, 'Хорошо, спасибо')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertStringNotContainsString('отправлено', $reply->content);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendPhoto'));
    }

    public function test_a_hallucinated_wikimedia_attribution_from_free_text_is_stripped(): void
    {
        $this->fakeTelegramApi();
        $this->mockLlm('Вот фото! © MayankMurthy0606, CC BY-SA 4.0, https://commons.wikimedia.org/wiki/File%3AExample.jpg');
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);

        (new HandleIncomingMessageJob($channel, $this->update(1, 'Хорошо, спасибо')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertStringNotContainsString('CC BY-SA', $reply->content);
        $this->assertStringNotContainsString('wikimedia', mb_strtolower($reply->content));
    }

    // --- #10 (real path): attribution rides in the caption, never alone ------

    public function test_a_real_photo_delivery_carries_attribution_in_the_caption_only(): void
    {
        $this->fakeTelegramApi();
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);
        $this->mock(WikimediaImageSearch::class)->shouldReceive('search')->once()
            ->with('HP VICTUS', 1, 'laptop')->andReturn([
                ['url' => 'https://thumb.wikimedia.org/hp-victus.jpg', 'caption' => '© Test photographer · CC BY-SA 4.0 · https://creativecommons.org/licenses/by-sa/4.0/'],
            ]);

        (new HandleIncomingMessageJob($channel, $this->update(1, 'А как выглядит виктус')))
            ->handle(...$this->jobDependencies());

        // Exactly one outbound text message (the "sent" confirmation) and one
        // photo — never a second message carrying the licence separately.
        $this->assertCount(1, $this->sentMessages());
        foreach ($this->sentMessages() as $text) {
            $this->assertStringNotContainsString('CC BY-SA', $text);
        }
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendPhoto')
            && str_contains((string) $r['caption'], 'CC BY-SA 4.0'));
    }

    // --- #3 (real path): "sent" only after the channel actually accepts it ---

    public function test_sent_text_appears_only_after_telegram_accepts_the_photo(): void
    {
        Http::fake([
            'api.telegram.org/*/sendPhoto' => Http::response(['ok' => false, 'description' => 'Bad Request: wrong file'], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);
        $this->mock(WikimediaImageSearch::class)->shouldReceive('search')->once()
            ->with('HP VICTUS', 1, 'laptop')->andReturn([
                ['url' => 'https://thumb.wikimedia.org/hp-victus.jpg', 'caption' => 'CC BY-SA'],
            ]);

        (new HandleIncomingMessageJob($channel, $this->update(1, 'А как выглядит виктус')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('failed', $reply->meta['photo_status']);
        $this->assertStringNotContainsString('отправлено', $reply->content);
        $this->assertStringContainsString('не удалось', $reply->content);
    }

    public function test_internet_outage_during_a_retry_is_reported_honestly(): void
    {
        $this->fakeTelegramApi();
        $agent = $this->pixelShop();
        $channel = $this->channelFor($agent);
        $this->mock(WikimediaImageSearch::class)->shouldReceive('search')->once()
            ->with('HP VICTUS', 1, 'laptop')->andThrow(new RuntimeException('Search unavailable'));

        (new HandleIncomingMessageJob($channel, $this->update(1, 'А как выглядит виктус')))
            ->handle(...$this->jobDependencies());

        $reply = Message::where('role', 'assistant')->sole();
        $this->assertSame('failed', $reply->meta['photo_status']);
        $this->assertStringNotContainsString('отправлено', $reply->content);
    }
}
