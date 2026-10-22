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

class QueryRunner
{
    public function __construct(
        protected readonly CompilerFactory $compilers = new CompilerFactory,
    ) {}

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

        $cache->put($key, $result->toCache(), $this->ttl());

        return $result;
    }

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
        if (! $connection || $connection->team_id !== $dataset->team_id || ! $connection->isUsable()) {
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

        if ($durationMs > $this->timeoutMs()) {
            throw new BiQueryException(
                BiQueryException::TIMEOUT,
                "Query took {$durationMs}ms, over the {$this->timeoutMs()}ms ceiling."
            );
        }

        $rows = array_map(fn ($row) => (array) $row, $rows);

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

    protected function classify(QueryException $e, int $durationMs): BiQueryException
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

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
