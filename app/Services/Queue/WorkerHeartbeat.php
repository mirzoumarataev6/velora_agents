<?php

namespace App\Services\Queue;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the queue worker is doing right now, written by the worker process and
 * read by anything that needs to know whether it is still alive: the
 * supervisor loop, `queue:health`, `queue:watchdog`.
 *
 * ONE RECORD PER POOL. The product runs two worker processes — `default` for
 * customer-facing work and `ingest` for BI file imports — and they must not share
 * a key: an idle ingest worker writing every few seconds would keep overwriting
 * the default worker's "working" state, and a hung job would then never be
 * detected. The pool comes from `worker.pool`, which `queue:supervise` sets on
 * each child it spawns.
 *
 * This exists because `queue:work --timeout` cannot enforce anything on
 * Windows — the worker's timeout is implemented with pcntl alarms, and the
 * pcntl extension does not exist here. A job that hangs on a socket therefore
 * hangs the worker forever, silently. The heartbeat makes that state visible
 * (and, via the supervisor, recoverable) without pcntl.
 */
class WorkerHeartbeat
{
    public const STATE_IDLE = 'idle';

    public const STATE_WORKING = 'working';

    /** Which worker pool this instance reads and writes the heartbeat of. */
    public readonly string $pool;

    /**
     * Defaults to the pool this PROCESS belongs to, so the event listeners in
     * AppServiceProvider — which resolve this from the container inside a worker —
     * automatically check in under the right key without being told.
     */
    public function __construct(?string $pool = null)
    {
        $this->pool = $pool ?? (string) config('worker.pool', 'default');
    }

    /** The configured pool names, in declaration order. */
    public static function pools(): array
    {
        $pools = array_keys((array) config('worker.pools', []));

        return $pools === [] ? ['default'] : $pools;
    }

    /** A heartbeat reader for one named pool. */
    public static function for(string $pool): self
    {
        return new self($pool);
    }

    /**
     * Record that the worker is alive and between jobs. Throttled — an idle
     * worker loops every few seconds and the cache store is the database.
     */
    public function idle(bool $force = false): void
    {
        $current = $this->read();

        if (! $force
            && ($current['state'] ?? null) === self::STATE_IDLE
            && $this->secondsSince($current['at'] ?? null) < (int) config('worker.heartbeat.throttle_seconds', 5)) {
            return;
        }

        $this->write(['state' => self::STATE_IDLE, 'job' => null, 'job_started_at' => null, 'job_timeout' => null]);
    }

    /**
     * Record that a job has just started, with the timeout it is allowed to
     * take, so a stall can be told apart from a slow-but-healthy job.
     */
    public function working(string $job, ?int $timeout = null): void
    {
        $this->write([
            'state' => self::STATE_WORKING,
            'job' => $job,
            'job_started_at' => now()->timestamp,
            'job_timeout' => $timeout ?: (int) config('worker.supervise.timeout', 300),
        ]);
    }

    public function clear(): void
    {
        Cache::forget($this->key());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        $beat = Cache::get($this->key());

        return is_array($beat) ? $beat : null;
    }

    /**
     * Why the worker should be considered stalled, or null if it looks healthy.
     *
     * A missing heartbeat is NOT reported as a stall: it equally means no
     * worker has ever run here (a fresh checkout, a test run), and restarting
     * on that basis would be guesswork. Absence is reported by `running()`.
     */
    public function stallReason(): ?string
    {
        $beat = $this->read();

        if ($beat === null) {
            return null;
        }

        $age = $this->secondsSince($beat['at'] ?? null);

        if (($beat['state'] ?? null) === self::STATE_WORKING) {
            $runningFor = $this->secondsSince($beat['job_started_at'] ?? null);
            $limit = (int) ($beat['job_timeout'] ?? 300) + (int) config('worker.heartbeat.job_grace_seconds', 60);

            if ($runningFor > $limit) {
                return sprintf(
                    '%s has been running %ds, past its %ds timeout — the worker is hung',
                    class_basename((string) ($beat['job'] ?? 'A job')),
                    $runningFor,
                    $limit
                );
            }

            return null;
        }

        $idleLimit = (int) config('worker.heartbeat.idle_stale_seconds', 60);

        if ($age > $idleLimit) {
            return sprintf('no heartbeat for %ds while idle (limit %ds) — the worker is gone', $age, $idleLimit);
        }

        return null;
    }

    /**
     * Whether a worker checked in recently enough to be considered present at
     * all. A worker busy inside a long job still counts as running.
     */
    public function running(): bool
    {
        $beat = $this->read();

        if ($beat === null) {
            return false;
        }

        if (($beat['state'] ?? null) === self::STATE_WORKING) {
            return $this->stallReason() === null;
        }

        return $this->secondsSince($beat['at'] ?? null) <= (int) config('worker.heartbeat.idle_stale_seconds', 60);
    }

    public function secondsSince(int|string|null $timestamp): int
    {
        if ($timestamp === null) {
            return PHP_INT_MAX;
        }

        return max(0, now()->timestamp - Carbon::createFromTimestamp((int) $timestamp)->timestamp);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function write(array $attributes): void
    {
        Cache::put(
            $this->key(),
            array_merge($attributes, ['pid' => getmypid(), 'at' => now()->timestamp]),
            now()->addHour()
        );
    }

    protected function key(): string
    {
        return (string) config('worker.heartbeat.cache_key', 'worker:heartbeat').':'.$this->pool;
    }
}
