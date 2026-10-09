<?php

namespace Tests\Feature\Channels;

use App\Models\KnowledgeSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class KnowledgeMediaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_link_can_serve_an_image_from_private_knowledge_storage(): void
    {
        Storage::fake('local');
        $source = KnowledgeSource::factory()->create([
            'mime_type' => 'image/jpeg', 'file_path' => 'knowledge/private/product.jpg',
        ]);
        Storage::disk('local')->put($source->file_path, 'image-bytes');
        $url = URL::temporarySignedRoute('knowledge.media.show', now()->addMinutes(3), ['source' => $source]);

        $response = $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY));

        $response->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->assertSame('image-bytes', $response->streamedContent());
    }

    public function test_unsigned_private_knowledge_image_link_is_rejected(): void
    {
        $source = KnowledgeSource::factory()->create(['mime_type' => 'image/jpeg']);

        $this->get(route('knowledge.media.show', $source))->assertForbidden();
    }
}
