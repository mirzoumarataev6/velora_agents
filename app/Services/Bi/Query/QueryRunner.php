<?php

namespace App\Services\Bi\Query;

use App\Models\BiDataset;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DriverManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Executes a QuerySpec: readiness checks, cache, the statement itself, and the
 * classification of whatever goes wrong.
 *
 * This is the only class in the layer that touches a database. Everything above
 * it — spec, validator, compiler — is pure, which is what makes the security
 * properties testable without a server and is why those classes are the ones
 * with the long docblocks. This one's job is the messy half: deciding when NOT
 * to run, and turning a driver exception into something an owner can read.
 */
class QueryRunner
{
    public function __construct(
        protected readonly CompilerFactory $compilers = new CompilerFactory,
    ) {}

    /**
     * @param  bool  $fresh  Skip the cache for this call — a manual "refresh" on a
     *                       tile, never the default. Still WRITES the result back.
     *
     * @throws BiQueryException
     */
    public function run(QuerySpec $spec, bool $fresh = false): QueryResult
    {
        $this->assertRunnable($spec->dataset);

        if ($spec->dataset->isExternal()) {
            return $this->runExternal($spec, $fresh);
        }

        $connection = $this->connection();
        $compiled = $this->compilers->for($connection)->compile($spec);

        $cache = $this->cache();
        $key = $this->cacheKey($spec);

        if (! $fresh && ($hit = $cache->get($key)) !== null) {
            return QueryResult::fromCache($hit);
        }

        $result = $this->execute($connection, $compiled, $spec);

        // Written back even when `$fresh` was requested: the next reader of the
        // same tile should benefit from the refresh somebody else just paid for.
        $cache->put($key, $result->toCache(), $this->ttl());

        return $result;
    }

    /**
     * The checks that are about the world rather than about the query.
     *
     * Kept out of QuerySpec deliberately — see that class's docblock. A spec
     * saved on a dashboard stays valid while its dataset rebuilds; it is only
     * *running* it that has to wait.
     *
     * @throws BiQueryException
     */
    protected function assertRunnable(BiDataset $dataset): void
    {
        if (! $dataset->isReady()) {
            throw new BiQueryException(
                BiQueryException::NOT_READY,
                "Dataset [{$dataset->getKey()}] is [{$dataset->status}], not ready."
            );
        }

        if ($dataset->isExternal()) {
            return;
        }

        // The table is dropped and recreated on every rematerialization, so there
        // is a real window — seconds on a small file, minutes on a large one — in
        // which the row says `ready` and the table is not there. Checked rather
        // than discovered as a driver error, because "table missing" is transient
        // and retryable while the error the driver raises for it is
        // indistinguishable from a genuine bug.
        if (! Schema::hasTable($dataset->physicalTable())) {
            throw new BiQueryException(
                BiQueryException::TABLE_MISSING,
                "Analytical table [{$dataset->physicalTable()}] is not present; it is probably being rebuilt."
            );
        }
    }

    private function runExternal(QuerySpec $spec, bool $fresh): QueryResult
    {
        $dataset = $spec->dataset;
        $connection = $dataset->source?->biConnection;
        if (! $connection || $connection->user_id !== $dataset->user_id || ! $connection->isUsable()) {
            throw new BiQueryException(BiQueryException::EXTERNAL_UNSUPPORTED, 'The BI connection is unavailable for this dataset.');
        }

        $cache = $this->cache();
        $key = $this->cacheKey($spec);
        if (! $fresh && ($hit = $cache->get($key)) !== null) {
            return QueryResult::fromCache($hit);
        }

        $started = microtime(true);
        try {
            $rows = app(DriverManager::class)->make($connection->config())->queryBi($spec);
        } catch (DatabaseConnectionException $e) {
            throw new BiQueryException($e->reason === DatabaseConnectionException::TIMEOUT
                ? BiQueryException::TIMEOUT : BiQueryException::EXECUTION_FAILED, $e->getMessage(), $e);
        }

        $duration = (int) round((microtime(true) - $started) * 1000);
        if ($duration > $this->timeoutMs()) {
            throw new BiQueryException(BiQueryException::TIMEOUT, "Live query exceeded {$this->timeoutMs()}ms.");
        }
        $hasMore = count($rows) > $spec->limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $spec->limit);
        }
        $result = new QueryResult(array_values($rows), QueryResult::describe($spec), $hasMore, $spec->offset, $spec->limit, $duration);
        $cache->put($key, $result->toCache(), $this->ttl());

        return $result;
    }

    /**
     * @throws BiQueryException
     */
    protected function execute(ConnectionInterface $connection, CompiledQuery $compiled, QuerySpec $spec): QueryResult
    {
        $startedAt = microtime(true);

        try {
            $rows = $connection->select($compiled->sql, $compiled->bindings);
        } catch (QueryException $e) {
            throw $this->classify($e, (int) round((microtime(true) - $startedAt) * 1000));
        } catch (Throwable $e) {
            throw new BiQueryException(BiQueryException::EXECUTION_FAILED, $e->getMessage(), $e);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // The PHP-side ceiling. On MySQL the optimizer hint has already stopped
        // the statement and we never get here; this is what gives SQLite — and a
        // MariaDB that ignored the hint — the same classified error rather than a
        // request that merely took a very long time and then succeeded. It cannot
        // interrupt the query, and saying so is the honest version: by the time
        // this fires the work is already done. What it buys is that the owner is
        // told the tile is too slow instead of being handed a number that took
        // forty seconds and will again.
        if ($durationMs > $this->timeoutMs()) {
            throw new BiQueryException(
                BiQueryException::TIMEOUT,
                "Query took {$durationMs}ms, over the {$this->timeoutMs()}ms ceiling."
            );
        }

        $rows = array_map(fn ($row) => (array) $row, $rows);

        // The probe row is removed here and never leaves this method, so no
        // caller can accidentally render page_size + 1 rows.
        $hasMore = $compiled->hasMore(count($rows));

        if ($hasMore) {
            $rows = array_slice($rows, 0, $compiled->pageSize);
        }

        return new QueryResult(
            rows: array_values($rows),
            columns: QueryResult::describe($spec),
            hasMore: $hasMore,
            offset: $spec->offset,
            pageSize: $compiled->pageSize,
            durationMs: $durationMs,
        );
    }

    /**
     * Turn a driver exception into a classified one.
     *
     * Only two outcomes, because only two are actionable: the owner can retry a
     * timeout or simplify the tile, and everything else is ours to fix. Matching
     * on SQLSTATE and vendor code rather than on message text, which is
     * localized on some MySQL builds.
     */
    protected function classify(QueryException $e, int $durationMs): BiQueryException
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // 3024: ER_QUERY_TIMEOUT, what MAX_EXECUTION_TIME raises.
        // 1028/2013/HY000+2006: the connection died under a long statement,
        // which from the owner's side is the same event.
        $timedOut = $driverCode === 3024
            || $driverCode === 2013
            || $driverCode === 2006
            || $sqlState === '70100';

        if ($timedOut) {
            return new BiQueryException(
                BiQueryException::TIMEOUT,
                "The engine stopped the query after {$durationMs}ms.",
                $e
            );
        }

        return new BiQueryException(BiQueryException::EXECUTION_FAILED, $e->getMessage(), $e);
    }

    /**
     * The cache key.
     *
     * Namespaced by dataset so a future "invalidate everything for this dataset"
     * has somewhere to hook, and fingerprinted by the spec — which already folds
     * in `bi_datasets.version`. That is what makes a rematerialization invalidate
     * every cached tile over that dataset without anything having to go and find
     * them: the new version produces different keys, and the orphaned entries
     * expire on their own TTL rather than being hunted down.
     */
    protected function cacheKey(QuerySpec $spec): string
    {
        return 'bi:q:'.$spec->dataset->getKey().':'.$spec->fingerprint();
    }

    protected function cache(): CacheRepository
    {
        return cache()->store();
    }

    protected function connection(): ConnectionInterface
    {
        return DB::connection();
    }

    protected function ttl(): int
    {
        return max(1, (int) config('agent.bi.query.cache_ttl_seconds', 3600));
    }

    protected function timeoutMs(): int
    {
        return max(1, (int) config('agent.bi.query.timeout_seconds', 30)) * 1000;
    }
}
