<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\ExtractTextJob;
use App\Models\Agent;
use App\Models\KnowledgeSource;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_source_pipeline_produces_ready_source_with_embedded_chunks(): void
    {
        Storage::fake('local');

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('estimateTokens')->andReturn(5);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('embedBatch')->andReturnUsing(function (array $texts) {
                $result = [];
                foreach ($texts as $key => $text) {
                    $result[$key] = ['vector' => array_fill(0, 8, 0.125), 'input_tokens' => 5];
                }

                return $result;
            });
        });

        $agent = Agent::factory()->create();
        $path = "knowledge/{$agent->id}/sources/faq.txt";
        Storage::disk('local')->put($path, str_repeat("Our store is open every day from 9am to 8pm.\n\n", 5));

        $source = new KnowledgeSource([
            'type' => 'text',
            'name' => 'FAQ',
            'file_path' => $path,
            'mime_type' => 'text/plain',
            'status' => 'pending',
        ]);
        $source->agent_id = $agent->id;
        $source->save();

        ExtractTextJob::dispatch($source);

        $source->refresh();

        $this->assertSame('ready', $source->status);
        $this->assertNull($source->error_message);
        $this->assertGreaterThan(0, $source->chunks()->count());

        $chunk = $source->chunks()->first();
        $this->assertNotNull($chunk->embedding);
        $this->assertCount(8, $chunk->embedding);

        // The extracted temp file is cleaned up once chunks are persisted.
        Storage::disk('local')->assertMissing($source->extractedPath());

        // Which model produced the vector is recorded, so a later model switch
        // can be detected instead of silently scoring across vector spaces.
        $this->assertSame('test-embedding-model', $chunk->embedding_model);
    }

    /**
     * The root cause of "у меня нет информации об учителях": a source named
     * "учителя" whose body is just "Воситов Равшан — веб разработка, …" embeds
     * as a list of people and technologies, with the word the customer actually
     * searches for appearing nowhere in it. The owner's label for the source is
     * the topic, so it has to be part of what gets embedded.
     */
    public function test_the_source_name_is_embedded_along_with_the_chunk_text(): void
    {
        Storage::fake('local');

        $embedded = [];

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$embedded) {
            $mock->shouldReceive('estimateTokens')->andReturn(5);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('embedBatch')->andReturnUsing(function (array $texts) use (&$embedded) {
                $embedded = array_merge($embedded, array_values($texts));

                return collect($texts)->map(fn () => ['vector' => array_fill(0, 8, 0.125), 'input_tokens' => 5])->all();
            });
        });

        $agent = Agent::factory()->create();
        $path = "knowledge/{$agent->id}/sources/teachers.txt";
        Storage::disk('local')->put($path, 'Воситов Равшан - веб разработка, Раупов Азим - php laravel');

        $source = new KnowledgeSource([
            'type' => 'text',
            'name' => 'учителя',
            'file_path' => $path,
            'mime_type' => 'text/plain',
            'status' => 'pending',
        ]);
        $source->agent_id = $agent->id;
        $source->save();

        ExtractTextJob::dispatch($source);

        $this->assertNotEmpty($embedded);
        $this->assertStringStartsWith('учителя', $embedded[0]);
        $this->assertStringContainsString('Воситов Равшан', $embedded[0]);

        // Only the embedding input carries the label — the stored content stays
        // clean, since the prompt already labels each chunk with its source.
        $this->assertStringNotContainsString('учителя', $source->refresh()->chunks()->first()->content);
    }

    /**
     * A URL is a location, not a topic. Prefixing every chunk of a crawled site
     * with "https://example.com/" would add the same meaningless tokens to all
     * of them and blur what actually distinguishes one chunk from another.
     */
    public function test_a_url_source_name_is_not_embedded_into_its_chunks(): void
    {
        Storage::fake('local');

        $embedded = [];

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$embedded) {
            $mock->shouldReceive('estimateTokens')->andReturn(5);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('embedBatch')->andReturnUsing(function (array $texts) use (&$embedded) {
                $embedded = array_merge($embedded, array_values($texts));

                return collect($texts)->map(fn () => ['vector' => array_fill(0, 8, 0.125), 'input_tokens' => 5])->all();
            });
        });

        $agent = Agent::factory()->create();
        $path = "knowledge/{$agent->id}/sources/site.txt";
        Storage::disk('local')->put($path, 'Школа программирования StartCoding обучает детей с 2018 года.');

        // The guard keys off the NAME, not the source type — a crawled page is
        // named by its URL, and this is the shape that reaches the embedder.
        $source = new KnowledgeSource([
            'type' => 'text',
            'name' => 'https://startcoding.tj/',
            'file_path' => $path,
            'mime_type' => 'text/plain',
            'status' => 'pending',
        ]);
        $source->agent_id = $agent->id;
        $source->save();

        ExtractTextJob::dispatch($source);

        $this->assertNotEmpty($embedded);
        $this->assertStringNotContainsString('https://', $embedded[0]);
    }

    public function test_xml_catalog_source_pipeline_records_dialect_and_product_count(): void
    {
        Storage::fake('local');

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('estimateTokens')->andReturn(5);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('embedBatch')->andReturnUsing(function (array $texts) {
                $result = [];
                foreach ($texts as $key => $text) {
                    $result[$key] = ['vector' => array_fill(0, 8, 0.125), 'input_tokens' => 5];
                }

                return $result;
            });
        });

        $agent = Agent::factory()->create();
        $path = "knowledge/{$agent->id}/sources/catalog.xml";
        Storage::disk('local')->put($path, <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <yml_catalog>
          <shop>
            <offers>
              <offer id="1" available="true"><name>Платье</name><price>2500</price><currencyId>RUB</currencyId></offer>
              <offer id="2" available="false"><name>Куртка</name><price>5000</price><currencyId>RUB</currencyId></offer>
            </offers>
          </shop>
        </yml_catalog>
        XML);

        $source = new KnowledgeSource([
            'type' => 'file',
            'name' => 'catalog.xml',
            'original_filename' => 'catalog.xml',
            'file_path' => $path,
            'mime_type' => 'application/xml',
            'status' => 'pending',
        ]);
        $source->agent_id = $agent->id;
        $source->save();

        ExtractTextJob::dispatch($source);

        $source->refresh();

        $this->assertSame('ready', $source->status);
        $this->assertSame('yml', $source->meta['xml_dialect']);
        $this->assertSame(2, $source->meta['product_count']);
        $this->assertGreaterThan(0, $source->chunks()->count());
    }

    public function test_pipeline_marks_source_failed_when_extraction_finds_nothing(): void
    {
        Storage::fake('local');

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('estimateTokens')->andReturn(1);
        });

        $agent = Agent::factory()->create();
        $path = "knowledge/{$agent->id}/sources/empty.txt";
        Storage::disk('local')->put($path, '');

        $source = new KnowledgeSource([
            'type' => 'text',
            'name' => 'Empty',
            'file_path' => $path,
            'mime_type' => 'text/plain',
            'status' => 'pending',
        ]);
        $source->agent_id = $agent->id;
        $source->save();

        ExtractTextJob::dispatch($source);

        $source->refresh();

        $this->assertSame('failed', $source->status);
        $this->assertNotNull($source->error_message);
        $this->assertSame(0, $source->chunks()->count());
    }
}
