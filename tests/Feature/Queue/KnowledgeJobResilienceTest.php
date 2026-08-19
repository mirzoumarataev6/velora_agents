<?php

namespace Tests\Feature\Queue;

use App\Exceptions\LlmException;
use App\Jobs\EmbedChunksJob;
use App\Jobs\ExtractTextJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Services\Knowledge\KnowledgeExtractionService;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Llm\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * A job may fail; it may never leave a knowledge source stranded in a
 * non-terminal state, and it must not burn its retries on a failure that
 * will repeat identically.
 */
class KnowledgeJobResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function sourceWithChunk(): KnowledgeSource
    {
        $source = KnowledgeSource::factory()->create(['status' => 'processing']);

        $chunk = new KnowledgeChunk(['content' => 'chunk', 'chunk_index' => 0, 'token_count' => 2]);
        $chunk->knowledge_source_id = $source->id;
        $chunk->agent_id = $source->agent_id;
        $chunk->save();

        return $source;
    }

    public function test_a_killed_job_marks_its_source_failed_instead_of_leaving_it_processing(): void
    {
        // What happens when the worker is killed mid-run and the queue gives
        // up: previously the source stayed on "processing" forever.
        $source = KnowledgeSource::factory()->create(['status' => 'processing']);

        (new ExtractTextJob($source))->failed(new RuntimeException('worker killed'));

        $source->refresh();
        $this->assertSame('failed', $source->status);
        $this->assertStringContainsString('worker killed', $source->error_message);
    }

    public function test_the_failed_handler_explains_itself_when_there_is_no_exception(): void
    {
        $source = KnowledgeSource::factory()->create(['status' => 'processing']);

        (new ExtractTextJob($source))->failed(null);

        $this->assertSame('failed', $source->fresh()->status);
        $this->assertStringContainsString('stopped before this step finished', $source->fresh()->error_message);
    }

    public function test_a_deleted_source_does_not_break_the_failed_handler(): void
    {
        $source = KnowledgeSource::factory()->create(['status' => 'processing']);
        $job = new ExtractTextJob($source);
        $source->delete();

        (new ExtractTextJob($source))->failed(new RuntimeException('boom'));

        $this->assertTrue(true); // No exception thrown is the assertion.
        unset($job);
    }

    public function test_a_permanent_failure_is_recorded_immediately_without_retrying(): void
    {
        Queue::fake();
        $source = KnowledgeSource::factory()->create();

        $this->mock(KnowledgeExtractionService::class, function ($mock) {
            // A corrupt file fails identically on every attempt.
            $mock->shouldReceive('extract')->andThrow(new RuntimeException('Unsupported file format.'));
        });

        (new ExtractTextJob($source))->handle(app(KnowledgeExtractionService::class), app(UsageRecorder::class));

        $source->refresh();
        $this->assertSame('failed', $source->status);
        $this->assertStringContainsString('Unsupported file format.', $source->error_message);
    }

    public function test_a_transient_provider_failure_is_rethrown_so_the_queue_retries_it(): void
    {
        $source = $this->sourceWithChunk();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embedBatch')->andThrow(LlmException::for('OpenAI', 'embeddings', 429, 'rate limited'));
        });

        // Rethrown, not swallowed: that is what hands the job back to the queue
        // for another attempt with backoff.
        $this->expectException(LlmException::class);

        try {
            (new EmbedChunksJob($source))->handle(app(LlmProviderInterface::class), app(UsageRecorder::class));
        } finally {
            // ...and the source stays in flight rather than being failed early.
            $this->assertSame('processing', $source->fresh()->status);
        }
    }

    public function test_a_dropped_connection_is_treated_as_transient(): void
    {
        $source = $this->sourceWithChunk();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embedBatch')->andThrow(new ConnectionException('cURL error 28: timed out'));
        });

        $this->expectException(ConnectionException::class);

        (new EmbedChunksJob($source))->handle(app(LlmProviderInterface::class), app(UsageRecorder::class));
    }

    public function test_a_client_error_from_the_provider_is_permanent(): void
    {
        $source = $this->sourceWithChunk();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            // 401: the API key is wrong. Retrying cannot fix it.
            $mock->shouldReceive('embedBatch')->andThrow(LlmException::for('OpenAI', 'embeddings', 401, 'invalid api key'));
        });

        (new EmbedChunksJob($source))->handle(app(LlmProviderInterface::class), app(UsageRecorder::class));

        $this->assertSame('failed', $source->fresh()->status);
    }

    public function test_each_step_claims_the_source_so_the_watchdog_can_time_it(): void
    {
        $source = KnowledgeSource::factory()->create(['status' => 'pending']);
        KnowledgeSource::withoutTimestamps(fn () => $source->forceFill(['updated_at' => now()->subHour()])->save());

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embedBatch')->andReturn([]);
        });

        // No chunks: the step fails, but it must still have claimed the source
        // on the way in, so a healthy long run is never read as a stuck one.
        (new EmbedChunksJob($source))->handle(app(LlmProviderInterface::class), app(UsageRecorder::class));

        $this->assertTrue($source->fresh()->updated_at->gt(now()->subMinute()));
    }
}
