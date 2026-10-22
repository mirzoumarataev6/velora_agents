<?php

namespace App\Jobs\Bi;

use App\Services\Bi\BiIngestionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

abstract class BiJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'ingest';

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $timeout = 900;

    public function backoff(): array
    {
        return [10, 60];
    }

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    protected function handleFailure(Throwable $e): void
    {
        if (! $e instanceof BiIngestionException && $this->isTransient($e) && $this->attempts() < $this->tries) {
            Log::warning('BI ingestion step hit a transient error — retrying', [
                'step' => static::class,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->markFailed($e);
    }

    protected function isTransient(Throwable $e): bool
    {
        return (bool) preg_grep(
            '/timed? ?out|deadlock|lock wait|connection|temporarily|try again|server has gone away/i',
            [$e->getMessage()]
        );
    }

    protected function classify(Throwable $e): array
    {
        if ($e instanceof BiIngestionException) {
            return [$e->reason, $e->detail()];
        }

        Log::error('BI ingestion failed with an unclassified error', [
            'step' => static::class,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        return ['internal_error', Str::limit($e->getMessage(), 200)];
    }

    abstract protected function markFailed(Throwable $e): void;

    public function failed(?Throwable $e): void
    {
        $this->markFailed($e ?? new \RuntimeException('The queue worker stopped before this step finished.'));
    }
}
