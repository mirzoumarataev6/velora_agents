<?php

namespace App\Jobs\Bi;

use App\Services\Bi\BiIngestionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * What every BI ingestion job shares: the queue it runs on, its retry policy, and
 * the rule that a failure always ends up visible to the owner.
 *
 * Modelled on KnowledgeJob, which solved the same problem for the knowledge
 * pipeline — including the part that is easy to forget: `failed()` must persist a
 * user-facing reason, because a job that dies in the worker leaves a card in the
 * UI that would otherwise say "processing" forever.
 *
 * THE QUEUE IS THE POINT OF THIS CLASS. Ingestion runs on `ingest`, never on
 * `default`, because `default` is where HandleIncomingMessageJob lives — a real
 * customer waiting for a reply. A 100MB spreadsheet takes minutes to normalize,
 * and on a shared FIFO those minutes would be added to every customer message that
 * happened to arrive behind it. Two named queues and two worker processes is what
 * keeps a back-office import from being a front-of-house outage.
 */
abstract class BiJob implements ShouldQueue
{
    use Queueable;

    /** The queue every BI ingestion job runs on. See the class docblock. */
    public const QUEUE = 'ingest';

    /**
     * A deleted source or dataset means the owner removed it while the job was
     * queued. Nothing to do and nothing to report.
     */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    /**
     * Fifteen minutes. Ingestion is bounded by the file budget rather than by a
     * network call, and a 100MB .xlsx genuinely takes minutes — the knowledge
     * pipeline's 300s would kill a legitimate import two thirds of the way in.
     *
     * NOT ENOUGH HEADROOM FOR THE LARGEST ALLOWED FILE, and this is a known,
     * accepted gap to be closed in the hardening phase. Measured on MySQL 8.4 at
     * 5,000 rows: ~0.40s to normalize and ~0.94s to materialize, which extrapolates
     * to roughly 160s and 375s for a 2,000,000-row file. Both fit inside 900s, but
     * a slower disk or a wider table would not leave much to spare.
     *
     * The decision taken was to RAISE THIS (and `worker.pools.ingest.timeout`, which
     * must stay above it) rather than lower `bi.ingest.max_rows`: supporting a large
     * file slowly is better than refusing it. Recorded here rather than only in a
     * plan because this constant is where anyone hitting the problem will look.
     */
    public int $timeout = 900;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Decide whether to retry or to give up and tell the owner.
     *
     * A BiIngestionException is a verdict about the FILE — the wrong format, too
     * many columns, unreadable bytes. Retrying it would produce the same verdict
     * three times and delay the message the owner needs. Only an infrastructural
     * failure is worth another attempt.
     */
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

    /**
     * Turn any throwable into the pair the UI renders: a translation code and a
     * technical detail. Never a stack trace, and never a raw driver message.
     *
     * @return array{0: string, 1: string}
     */
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

        // Deliberately vague to the owner and precise in the log. An unclassified
        // failure is our bug, and its internals are not theirs to read.
        return ['internal_error', Str::limit($e->getMessage(), 200)];
    }

    /** Persist the failure where the owner will see it. */
    abstract protected function markFailed(Throwable $e): void;

    /**
     * The queue worker gave up (timeout, or the process died). Same treatment as
     * a caught failure: the record must not be left saying "processing".
     */
    public function failed(?Throwable $e): void
    {
        $this->markFailed($e ?? new \RuntimeException('The queue worker stopped before this step finished.'));
    }
}
