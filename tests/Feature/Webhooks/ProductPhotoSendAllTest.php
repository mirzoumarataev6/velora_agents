<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\ProductImage;
use App\Services\Channels\WikimediaImageSearch;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * replyAll() ("показать все") calls PhotoReplyService::sendPhotoFor() once
 * per offered choice, accumulating `product_image_candidates` across those
 * calls — each call's evidence carries every earlier call's candidates
 * forward. The owned-photo tier never relevance-checks a candidate's name
 * (that is the whole point of it being "owned"), so without the exact
 * `for_model` tag an earlier choice's own photo would still be sitting in
 * that accumulated evidence and get sent again on a later, unrelated
 * choice's turn.
 */
class ProductPhotoSendAllTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_all_choices_does_not_resend_one_choices_owned_photo_for_another(): void
    {
        Storage::fake('public');
        $agent = Agent::factory()->create(['behavior' => ['internet_image_search' => false]]);
        $dataset = Dataset::factory()->for($agent)->create(['row_count' => 2]);
        $productA = DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $dataset->id, 'name' => 'ASUS ROG STRIX', 'price' => 10000,
        ]);
        DatasetProduct::factory()->create([
            'agent_id' => $agent->id, 'dataset_id' => $dataset->id, 'name' => 'Lenovo Legion 5', 'price' => 9000,
        ]);
        Storage::disk('public')->put('asus.jpg', 'fixture');
        ProductImage::forceCreate([
            'agent_id' => $agent->id, 'dataset_product_id' => $productA->id,
            'path' => 'asus.jpg', 'mime_type' => 'image/jpeg', 'size' => 1, 'position' => 0,
        ]);
        // Lenovo Legion 5 deliberately has no uploaded photo.

        $channel = Channel::factory()->for($agent)->create([
            'type' => 'telegram', 'status' => 'connected',
            'credentials' => ['bot_token' => 'FAKE-TOKEN'], 'settings' => ['webhook_secret' => 'sekret'],
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturn([
                'text' => 'ok', 'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'STOP', 'tool_calls' => [],
            ]);
        });
        $this->mock(WikimediaImageSearch::class)->shouldNotReceive('search');

        $conversation = $agent->conversations()->create(['channel_id' => $channel->id, 'external_user_id' => '999', 'status' => 'ai']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'есть игровые ноутбуки?', 'meta' => []]);
        // A pending menu, as PhotoReplyService itself would have stored it.
        $conversation->messages()->create([
            'role' => 'assistant', 'content' => "1. ASUS ROG STRIX — 10000\n2. Lenovo Legion 5 — 9000",
            'meta' => [
                'photo_choices' => [['name' => 'ASUS ROG STRIX', 'price' => '10000'], ['name' => 'Lenovo Legion 5', 'price' => '9000']],
                'photo_status' => 'clarify', 'photo_context' => '',
                'failed' => false, 'input_tokens' => 0, 'output_tokens' => 0,
            ],
        ]);

        $parameters = (new \ReflectionMethod(HandleIncomingMessageJob::class, 'handle'))->getParameters();
        $dependencies = array_map(fn ($p) => app($p->getType()?->getName()), $parameters);

        $update = [
            'update_id' => 1,
            'message' => [
                'message_id' => 1, 'from' => ['id' => 999, 'first_name' => 'Customer'],
                'chat' => ['id' => 999, 'type' => 'private'], 'date' => time(), 'text' => 'все',
            ],
        ];
        (new HandleIncomingMessageJob($channel, $update))->handle(...$dependencies);

        $photosSent = [];
        Http::assertSent(function ($request) use (&$photosSent) {
            if (str_contains($request->url(), '/sendPhoto')) {
                $photosSent[] = $request['photo'];
            }

            return true;
        });

        // Exactly one send — ASUS's own photo, once. Lenovo has none, and
        // ASUS's photo must not be reused for it.
        $this->assertCount(1, $photosSent);
        $this->assertStringContainsString('asus.jpg', $photosSent[0]);
    }
}
