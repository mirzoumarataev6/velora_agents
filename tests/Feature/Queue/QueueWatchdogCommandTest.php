<?php

namespace Tests\Feature\Queue;

use App\Jobs\ChunkTextJob;
use App\Jobs\EmbedChunksJob;
use App\Jobs\ExtractTextJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The watchdog is what guarantees a knowledge source can never sit on
 * "Processing…" forever because the worker died half-way through it.
 */
class QueueWatchdogCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function stuckSource(string $status, int $minutesAgo, array $attributes = []): KnowledgeSource
    {
        $source = KnowledgeSource::factory()->create(array_merge(['status' => $status], $attributes));

        // updated_at is what the watchdog measures, and it is refreshed on
        // every save — set it last, without touching timestamps.
        KnowledgeSource::withoutTimestamps(fn () => $source->forceFill([
            'updated_at' => now()->subMinutes($minutesAgo),
        ])->save());

        return $source->refresh();
    }

    public function test_a_source_stuck_pending_is_re_dispatched(): void
    {
        Queue::fake();
        $source = $this->stuckSource('pending', 10);

        $this->artisan('queue:watchdog')->assertSuccessful();

        Queue::assertPushed(ExtractTextJob::class, fn ($job) => $job->source->is($source));
        $this->assertSame('pending', $source->fresh()->status);
        $this->assertSame(1, $source->fresh()->meta['recovery_attempts']);
    }

    public function test_a_recently_queued_source_is_left_alone(): void
    {
        Queue::fake();
        $this->stuckSource('pending', 1); // inside the 3-minute pending window

        $this->artisan('queue:watchdog')->expectsOutputToContain('Nothing stuck.')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_source_processing_within_its_window_is_left_alone(): void
    {
        Queue::fake();
        // A big embedding run may legitimately take minutes; the processing
        // window (7 min) sits above the job timeout for exactly this reason.
        $this->stuckSource('processing', 4);

        $this->artisan('queue:watchdog')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_source_stuck_processing_past_its_window_is_recovered(): void
    {
        Queue::fake();
        $source = $this->stuckSource('processing', 10);

        $this->artisan('queue:watchdog')->assertSuccessful();

        Queue::assertPushed(ExtractTextJob::class);
        $this->assertSame('pending', $source->fresh()->status);
    }

    public function test_recovery_resumes_from_the_chunking_step_when_extraction_already_finished(): void
    {
        Queue::fake();
        Storage::fake('local');
        $source = $this->stuckSource('processing', 10);
        Storage::disk('local')->put($source->extractedPath(), json_encode(['text' => 'already extracted']));

        $this->artisan('queue:watchdog')->assertSuccessful();

        Queue::assertPushed(ChunkTextJob::class);
        Queue::assertNotPushed(ExtractTextJob::class);
    }

    public function test_recovery_resumes_from_embedding_when_chunks_already_exist(): void
    {
        Queue::fake();
        $source = $this->stuckSource('processing', 10);
        $chunk = new KnowledgeChunk(['content' => 'chunk', 'chunk_index' => 0, 'token_count' => 2]);
        $chunk->knowledge_source_id = $source->id;
        $chunk->agent_id = $source->agent_id;
        $chunk->save();

        $this->artisan('queue:watchdog')->assertSuccessful();

        Queue::assertPushed(EmbedChunksJob::class);
        Queue::assertNotPushed(ExtractTextJob::class);
    }

    public function test_it_gives_up_with_a_clear_message_after_the_attempt_limit(): void
    {
        Queue::fake();
        $source = $this->stuckSource('processing', 10, ['meta' => ['recovery_attempts' => 2]]);

        $this->artisan('queue:watchdog')->assertSuccessful();

        Queue::assertNothingPushed();

        $source->refresh();
        $this->assertSame('failed', $source->status);
        $this->assertStringContainsString('could not be recovered automatically', $source->error_message);
    }

    public function test_a_source_whose_job_is_still_waiting_in_the_queue_is_not_dispatched_twice(): void
    {
        $source = $this->stuckSource('pending', 10);

        // A real queued job for this exact source, written by the real
        // serializer — the watchdog has to recognise it in the payload, so a
        // hand-rolled row would prove nothing.
        config(['queue.default' => 'database']);
        ExtractTextJob::dispatch($source);
        $this->assertSame(1, DB::table('jobs')->whereNull('reserved_at')->count());

        $this->artisan('queue:watchdog')
            ->expectsOutputToContain('job still queued')
            ->assertSuccessful();

        // No second copy queued, and the source was left as it was.
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('pending', $source->fresh()->status);
        $this->assertArrayNotHasKey('recovery_attempts', $source->fresh()->meta ?? []);
    }

    public function test_dry_run_reports_without_changing_anything(): void
    {
        Queue::fake();
        $source = $this->stuckSource('processing', 10);

        $this->artisan('queue:watchdog --dry-run')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('processing', $source->fresh()->status);
        $this->assertArrayNotHasKey('recovery_attempts', $source->fresh()->meta ?? []);
    }

    public function test_ready_and_failed_sources_are_never_touched(): void
    {
        Queue::fake();
        $ready = $this->stuckSource('ready', 60);
        $failed = $this->stuckSource('failed', 60);

        $this->artisan('queue:watchdog')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('ready', $ready->fresh()->status);
        $this->assertSame('failed', $failed->fresh()->status);
    }
}
