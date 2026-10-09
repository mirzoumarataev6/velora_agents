<?php

namespace Tests\Feature\Channels;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\KnowledgeSource;
use App\Services\Channels\ChannelDriverInterface;
use App\Services\Channels\ImageReplyService;
use App\Services\Channels\WikimediaImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageReplyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_owner_upload_is_sent_without_rag_or_internet_and_never_from_another_agent(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('owner-omen.jpg', 'fixture');
        Storage::disk('local')->put('other-omen.jpg', 'fixture');
        KnowledgeSource::factory()->create(['name' => 'HP OMEN 16', 'mime_type' => 'image/jpeg', 'file_path' => 'other-omen.jpg']);
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => true]]);
        $source = KnowledgeSource::factory()->for($agent)->create(['name' => 'HP OMEN16', 'mime_type' => 'image/jpeg', 'file_path' => 'owner-omen.jpg']);
        $channel = Channel::factory()->for($agent)->create(['type' => 'telegram']);
        $this->mock(WikimediaImageSearch::class)->shouldNotReceive('search');
        $driver = $this->mock(ChannelDriverInterface::class);
        $driver->shouldReceive('sendImage')->once()->withArgs(fn ($ch, $id, $url) => parse_url($url, PHP_URL_PATH) === '/media/knowledge/'.$source->id);

        $this->assertSame(1, app(ImageReplyService::class)->sendIfRequested(
            $driver, $channel, $agent, '555', 'Фото OMEN 16', [], [], '', 'OMEN 16',
        ));
    }

    public function test_another_agents_matching_upload_is_not_sent(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('other-omen.jpg', 'fixture');
        KnowledgeSource::factory()->create(['name' => 'HP OMEN 16', 'mime_type' => 'image/jpeg', 'file_path' => 'other-omen.jpg']);
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => false]]);
        $channel = Channel::factory()->for($agent)->create(['type' => 'telegram']);
        $driver = $this->mock(ChannelDriverInterface::class);
        $driver->shouldNotReceive('sendImage');
        $this->assertSame(0, app(ImageReplyService::class)->sendIfRequested(
            $driver, $channel, $agent, '555', 'Фото OMEN 16', [], [], '', 'OMEN 16',
        ));
    }

    public function test_a_generic_product_photo_request_asks_for_the_model_instead_of_sending_random_images(): void
    {
        $this->mock(WikimediaImageSearch::class)->shouldNotReceive('search');
        $driver = $this->mock(ChannelDriverInterface::class);
        $driver->shouldNotReceive('sendImage');
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => true]]);
        $channel = Channel::factory()->for($agent)->create();

        $sent = app(ImageReplyService::class)->sendIfRequested(
            $driver, $channel, $agent, '555', 'Покажи фото ноутбука', [
                'product_queries' => [['query' => 'laptop', 'category' => null]],
            ]
        );

        $this->assertSame(0, $sent);
    }

    public function test_internet_search_is_not_called_when_owner_has_not_opted_in(): void
    {
        $this->mock(WikimediaImageSearch::class)->shouldNotReceive('search');
        $driver = $this->mock(ChannelDriverInterface::class);
        $driver->shouldNotReceive('sendImage');
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => false]]);
        $channel = Channel::factory()->for($agent)->create();

        $sent = app(ImageReplyService::class)->sendIfRequested(
            $driver, $channel, $agent, '555', 'Покажи фото товара', []
        );

        $this->assertSame(0, $sent);
    }

    public function test_an_inbound_customer_photo_is_not_mistaken_for_a_request_to_send_one(): void
    {
        $this->mock(WikimediaImageSearch::class)->shouldNotReceive('search');
        $driver = $this->mock(ChannelDriverInterface::class);
        $driver->shouldNotReceive('sendImage');
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => true]]);
        $channel = Channel::factory()->for($agent)->create();

        $sent = app(ImageReplyService::class)->sendIfRequested(
            $driver, $channel, $agent, '555', '[Customer sent an image. Identified: a laptop]', []
        );

        $this->assertSame(0, $sent);
    }

    public function test_plural_photo_follow_up_does_not_pick_the_first_of_several_models(): void
    {
        $this->mock(WikimediaImageSearch::class)->shouldNotReceive('search');
        $driver = $this->mock(ChannelDriverInterface::class);
        $driver->shouldNotReceive('sendImage');
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => true]]);
        $channel = Channel::factory()->for($agent)->create();

        $sent = app(ImageReplyService::class)->sendIfRequested(
            $driver,
            $channel,
            $agent,
            '555',
            'А можете показать фото как они выглядят',
            [],
            [],
            "1. ASUS ROG STRIX G18 — игровой ноутбук.\n2. Lenovo Legion 5 — альтернатива.",
        );

        $this->assertSame(0, $sent);
        $this->assertSame('', app(ImageReplyService::class)->requestedModel(
            'А можете показать фото как они выглядят',
            "1. ASUS ROG STRIX G18 — игровой ноутбук.\n2. Lenovo Legion 5 — альтернатива.",
        ));
    }

    public function test_a_named_macbook_does_not_reuse_the_previous_omen_model(): void
    {
        $search = $this->mock(WikimediaImageSearch::class);
        $search->shouldReceive('search')->once()->with('MacBook Air 15', 1, 'laptop')->andReturn([]);
        $driver = $this->mock(ChannelDriverInterface::class);
        $driver->shouldNotReceive('sendImage');
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => true]]);
        $channel = Channel::factory()->for($agent)->create();

        $service = app(ImageReplyService::class);
        $this->assertSame('', $service->requestedModel('А тогда покажи, как выглядит макбук', 'Не нашёл фото OMEN 16.'));
        $this->assertSame(0, $service->sendIfRequested(
            $driver, $channel, $agent, '555', 'Покажи фото MacBook Air 15', [], [], 'Не нашёл фото OMEN 16.'
        ));
    }

    public function test_a_precise_internet_result_beats_a_generic_retrieved_image(): void
    {
        $search = $this->mock(WikimediaImageSearch::class);
        $search->shouldReceive('search')->once()->with('OMEN 16', 1, 'laptop')->andReturn([
            ['url' => 'https://thumb.wikimedia.org/omen-16.jpg', 'caption' => 'CC BY-SA'],
        ]);
        $driver = $this->mock(ChannelDriverInterface::class);
        $driver->shouldReceive('sendImage')->once()->withArgs(
            fn ($channel, $externalUserId, $url) => $url === 'https://thumb.wikimedia.org/omen-16.jpg'
        );
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => true]]);
        $channel = Channel::factory()->for($agent)->create(['type' => 'telegram']);
        $source = KnowledgeSource::factory()->for($agent)->create(['mime_type' => 'image/jpeg']);

        $sent = app(ImageReplyService::class)->sendIfRequested(
            $driver,
            $channel,
            $agent,
            '555',
            'Покажи фото OMEN 16',
            ['retrieved_chunks' => [['source_id' => $source->id, 'source_mime_type' => 'image/jpeg']]],
            [],
            'OMEN 16 — игровой ноутбук.',
        );

        $this->assertSame(1, $sent);
    }
}
