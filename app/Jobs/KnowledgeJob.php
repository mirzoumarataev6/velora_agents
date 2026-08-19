<?php

namespace App\Jobs;

use App\Exceptions\LlmException;
use App\Models\KnowledgeSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

abstract class KnowledgeJob implements ShouldQueue
{
    use Queueable;

    /**
     * If the bound KnowledgeSource was deleted while this job was queued
     * (e.g. the user removed the source), silently drop the job instead
     * of failing with a model-not-found error.
     */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * The source every step of the pipeline operates on. Declared here so the
     * shared failure handling below can reach it without each subclass
     * re-implementing failed().
     */
    public KnowledgeSource $source;

    /**
     * Seconds to wait before each retry. Transient failures are usually a rate
     * limit or a blip, so back off rather than burning the remaining attempts
     * in the same second.
     *
     * @return int[]
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    /**
     * Last line of defence: the queue has exhausted every attempt, or the
     * worker was killed mid-job and the release/retry cycle ran out. Without
     * this the source would sit on "processing" forever, which is exactly the
     * hang the watchdog and this handler exist to prevent.
     */
    public function failed(?Throwable $e): void
    {
        if (! isset($this->source) || ! $this->source->exists) {
            return;
        }

        $this->markFailed($this->source->fresh() ?? $this->source, $e ?? new \RuntimeException('The queue worker stopped before this step finished.'));
    }

    /**
     * Decide what a caught exception means: hand transient failures back to
     * the queue so they retry with backoff, and only mark the source failed
     * once retrying is pointless (or has been exhausted).
     *
     * @throws Throwable
     */
    protected function handleFailure(KnowledgeSource $source, Throwable $e): void
    {
        if ($this->isTransient($e) && $this->attempts() < $this->tries) {
            Log::warning('Knowledge step hit a transient error — retrying', [
                'knowledge_source_id' => $source->id,
                'step' => static::class,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->markFailed($source, $e);
    }

    /**
     * Whether the failure is the kind that a later attempt could survive —
     * a dropped connection, a rate limit, a provider 5xx. A malformed file or
     * an empty document fails identically every time and must not be retried.
     */
    protected function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof LlmException) {
            // 429 (rate limited) and 5xx (provider trouble) are worth another
            // go; 4xx means the request itself is wrong and always will be.
            // A status of 0 is a transport failure, which is transient too.
            return $e->status === 0 || $e->status === 429 || $e->status >= 500;
        }

        return (bool) preg_grep(
            '/timed? ?out|connection|temporarily|rate limit|too many requests|deadlock|try again/i',
            [$e->getMessage()]
        );
    }

    protected function markFailed(KnowledgeSource $source, Throwable $e): void
    {
        Log::error('Knowledge source processing failed', [
            'knowledge_source_id' => $source->id,
            'step' => static::class,
            'error' => $e->getMessage(),
        ]);

        $source->update([
            'status' => 'failed',
            'error_message' => Str::limit($e->getMessage(), 1000),
        ]);
    }

    /**
     * Mark this step as alive: the watchdog measures how long a source has sat
     * in `processing` from its updated_at, so each step must claim it when it
     * starts or a healthy multi-step run looks stuck.
     */
    protected function beginStep(): void
    {
        $this->source->forceFill(['status' => 'processing', 'error_message' => null])->save();
    }
}
