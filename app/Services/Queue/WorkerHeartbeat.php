<?php

namespace App\Services\Queue;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class WorkerHeartbeat
{
    public const STATE_IDLE = 'idle';

    public const STATE_WORKING = 'working';

    public readonly string $pool;

    public function __construct(?string $pool = null)
    {
        $this->pool = $pool ?? (string) config('worker.pool', 'default');
    }

    public static function pools(): array
    {
        $pools = array_keys((array) config('worker.pools', []));

        return $pools === [] ? ['default'] : $pools;
    }

    public static function for(string $pool): self
    {
        return new self($pool);
    }

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

    public function read(): ?array
    {
        $beat = Cache::get($this->key());

        return is_array($beat) ? $beat : null;
    }

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
