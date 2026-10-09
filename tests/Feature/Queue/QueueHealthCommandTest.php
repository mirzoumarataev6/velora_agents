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

        // Every pool, not just the default one: the command reports on all of
        // them, so a leftover beat from another test would make "no worker has
        // checked in" untrue for a pool this test never touched.
        $this->clearAllHeartbeats();
    }

    /**
     * Check in as every configured pool, which is what "the queue is healthy"
     * actually requires now that there are two worker processes.
     */
    protected function beatAllPools(): void
    {
        foreach (WorkerHeartbeat::pools() as $pool) {
            WorkerHeartbeat::for($pool)->idle();
        }
    }

    protected function clearAllHeartbeats(): void
    {
        foreach (WorkerHeartbeat::pools() as $pool) {
            WorkerHeartbeat::for($pool)->clear();
        }
    }

    public function test_it_fails_when_no_worker_has_checked_in(): void
    {
        $this->artisan('queue:health')
            ->expectsOutputToContain('No [default] worker has checked in')
            ->assertFailed();
    }

    /**
     * The whole point of splitting the pools: an alive `default` worker says
     * nothing about whether BI imports are being picked up, so the health check
     * must not call the queue healthy while the ingest worker is absent.
     */
    public function test_it_fails_when_only_one_pool_has_checked_in(): void
    {
        WorkerHeartbeat::for('default')->idle();

        $this->artisan('queue:health')
            ->expectsOutputToContain('No [ingest] worker has checked in')
            ->assertFailed();
    }

    public function test_it_passes_when_every_worker_is_alive_and_nothing_is_stuck(): void
    {
        $this->beatAllPools();

        $this->artisan('queue:health')
            ->expectsOutputToContain('Queue looks healthy.')
            ->assertSuccessful();
    }

    public function test_it_reports_stuck_knowledge_sources_even_when_the_worker_is_alive(): void
    {
        $this->beatAllPools();

        $source = KnowledgeSource::factory()->create(['status' => 'processing']);
        KnowledgeSource::withoutTimestamps(fn () => $source->forceFill(['updated_at' => now()->subHour()])->save());

        $this->artisan('queue:health')
            ->expectsOutputToContain('knowledge source(s) stuck')
            ->assertFailed();
    }

    public function test_json_output_is_machine_readable(): void
    {
        $this->beatAllPools();
        WorkerHeartbeat::for('default')->working('App\Jobs\ExtractTextJob', timeout: 300);

        $this->assertSame(0, Artisan::call('queue:health', ['--json' => true]));

        $report = json_decode(Artisan::output(), true);

        $this->assertTrue($report['healthy']);
        $this->assertSame(WorkerHeartbeat::STATE_WORKING, $report['workers']['default']['state']);
        $this->assertSame('App\Jobs\ExtractTextJob', $report['workers']['default']['job']);
        $this->assertSame(WorkerHeartbeat::STATE_IDLE, $report['workers']['ingest']['state']);
        $this->assertSame(0, $report['queue']['pending']);
    }

    /**
     * Two workers must not share one liveness record. Before the pools were split
     * they did, and an idle ingest worker writing every few seconds would keep
     * clearing the default worker's "working" state — after which a hung job could
     * never be detected.
     */
    public function test_each_pool_keeps_its_own_heartbeat(): void
    {
        WorkerHeartbeat::for('default')->working('App\Jobs\HandleIncomingMessageJob', timeout: 60);
        WorkerHeartbeat::for('ingest')->idle();

        $this->assertSame(
            WorkerHeartbeat::STATE_WORKING,
            WorkerHeartbeat::for('default')->read()['state'],
            'The ingest worker checking in must not overwrite what the default worker is doing.'
        );
        $this->assertSame(WorkerHeartbeat::STATE_IDLE, WorkerHeartbeat::for('ingest')->read()['state']);
    }
}
