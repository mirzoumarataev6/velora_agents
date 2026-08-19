<?php

namespace Tests\Feature\Queue;

use App\Models\KnowledgeSource;
use App\Services\Queue\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class QueueHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(WorkerHeartbeat::class)->clear();
    }

    public function test_it_fails_when_no_worker_has_checked_in(): void
    {
        $this->artisan('queue:health')
            ->expectsOutputToContain('No worker has checked in')
            ->assertFailed();
    }

    public function test_it_passes_when_the_worker_is_alive_and_nothing_is_stuck(): void
    {
        app(WorkerHeartbeat::class)->idle();

        $this->artisan('queue:health')
            ->expectsOutputToContain('Queue looks healthy.')
            ->assertSuccessful();
    }

    public function test_it_reports_stuck_knowledge_sources_even_when_the_worker_is_alive(): void
    {
        app(WorkerHeartbeat::class)->idle();

        $source = KnowledgeSource::factory()->create(['status' => 'processing']);
        KnowledgeSource::withoutTimestamps(fn () => $source->forceFill(['updated_at' => now()->subHour()])->save());

        $this->artisan('queue:health')
            ->expectsOutputToContain('knowledge source(s) stuck')
            ->assertFailed();
    }

    public function test_json_output_is_machine_readable(): void
    {
        app(WorkerHeartbeat::class)->working('App\Jobs\ExtractTextJob', timeout: 300);

        $this->assertSame(0, Artisan::call('queue:health', ['--json' => true]));

        $report = json_decode(Artisan::output(), true);

        $this->assertTrue($report['healthy']);
        $this->assertSame(WorkerHeartbeat::STATE_WORKING, $report['worker']['state']);
        $this->assertSame('App\Jobs\ExtractTextJob', $report['worker']['job']);
        $this->assertSame(0, $report['queue']['pending']);
    }
}
