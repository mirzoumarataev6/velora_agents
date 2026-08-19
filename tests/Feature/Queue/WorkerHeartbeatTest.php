<?php

namespace Tests\Feature\Queue;

use App\Services\Queue\WorkerHeartbeat;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The heartbeat is the only way this environment can tell a hung worker from
 * a busy one: `queue:work --timeout` is a no-op on Windows, where the pcntl
 * extension it relies on does not exist.
 */
class WorkerHeartbeatTest extends TestCase
{
    protected WorkerHeartbeat $heartbeat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->heartbeat = new WorkerHeartbeat;
        $this->heartbeat->clear();
    }

    public function test_no_heartbeat_means_no_worker_is_running(): void
    {
        $this->assertNull($this->heartbeat->read());
        $this->assertFalse($this->heartbeat->running());
        // Absence is not a stall — there may simply never have been a worker.
        $this->assertNull($this->heartbeat->stallReason());
    }

    public function test_a_freshly_idle_worker_is_running_and_not_stalled(): void
    {
        $this->heartbeat->idle();

        $this->assertTrue($this->heartbeat->running());
        $this->assertNull($this->heartbeat->stallReason());
        $this->assertSame(WorkerHeartbeat::STATE_IDLE, $this->heartbeat->read()['state']);
    }

    public function test_an_idle_worker_that_stops_checking_in_is_stalled(): void
    {
        $this->heartbeat->idle();

        Carbon::setTestNow(now()->addSeconds(90)); // past idle_stale_seconds (60)

        $this->assertNotNull($this->heartbeat->stallReason());
        $this->assertStringContainsString('the worker is gone', $this->heartbeat->stallReason());
        $this->assertFalse($this->heartbeat->running());
    }

    public function test_a_worker_inside_a_slow_job_is_not_mistaken_for_a_dead_one(): void
    {
        $this->heartbeat->working('App\Jobs\EmbedChunksJob', timeout: 300);

        // Four minutes of silence: well past the idle limit, but this job is
        // allowed five. Killing it here would abort healthy work.
        Carbon::setTestNow(now()->addSeconds(240));

        $this->assertNull($this->heartbeat->stallReason());
        $this->assertTrue($this->heartbeat->running());
    }

    public function test_a_job_running_past_its_timeout_and_grace_is_reported_as_hung(): void
    {
        $this->heartbeat->working('App\Jobs\EmbedChunksJob', timeout: 300);

        Carbon::setTestNow(now()->addSeconds(300 + 60 + 5)); // timeout + grace + a bit

        $reason = $this->heartbeat->stallReason();
        $this->assertNotNull($reason);
        $this->assertStringContainsString('EmbedChunksJob', $reason);
        $this->assertStringContainsString('the worker is hung', $reason);
        $this->assertFalse($this->heartbeat->running());
    }

    public function test_finishing_a_job_returns_the_worker_to_idle(): void
    {
        $this->heartbeat->working('App\Jobs\EmbedChunksJob', timeout: 300);
        Carbon::setTestNow(now()->addSeconds(30));

        $this->heartbeat->idle(force: true);

        $this->assertSame(WorkerHeartbeat::STATE_IDLE, $this->heartbeat->read()['state']);
        $this->assertNull($this->heartbeat->read()['job']);
        $this->assertNull($this->heartbeat->stallReason());
    }

    public function test_idle_beats_are_throttled_but_can_be_forced(): void
    {
        $this->heartbeat->idle();
        $first = $this->heartbeat->read()['at'];

        Carbon::setTestNow(now()->addSeconds(2)); // inside the throttle window
        $this->heartbeat->idle();
        $this->assertSame($first, $this->heartbeat->read()['at'], 'A throttled beat should not have been written.');

        $this->heartbeat->idle(force: true);
        $this->assertNotSame($first, $this->heartbeat->read()['at']);
    }

    public function test_the_worker_records_its_process_id(): void
    {
        $this->heartbeat->idle();

        $this->assertSame(getmypid(), $this->heartbeat->read()['pid']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
