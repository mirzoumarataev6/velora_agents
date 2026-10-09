<?php

namespace App\Services\Bi\Ingestion;

use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Services\Bi\BiIngestionException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds a dataset's physical analytical table and fills it from the shards.
 *
 * THIS IS THE ONLY PLACE IN THE PRODUCT THAT ISSUES DDL, and the only place that
 * ever will. Everything about it is arranged so that the identifiers it emits
 * cannot have come from anywhere but our own primary keys:
 *
 *  - the table name is `bi_ds_{id}`, re-checked against BiDataset::TABLE_PATTERN
 *    on the way in;
 *  - every column name is `c{ordinal}`, re-checked against BiColumn::NAME_PATTERN;
 *  - the owner's header text is never an identifier, only a `bi_columns` row.
 *
 * Those checks cannot fail on user input — there is no path from a file's bytes to
 * an identifier here. They exist to catch OUR bug before it reaches a CREATE or a
 * DROP, because runtime DDL is the one operation in this product that editing a
 * row cannot undo.
 *
 * IDEMPOTENT BY CONSTRUCTION. It drops and rebuilds, so a retry after a worker
 * died mid-insert is correct rather than additive — there is no partial state to
 * reconcile, which is why no transaction is needed (and none would help: MySQL
 * DDL is not transactional).
 *
 * Indexes are created AFTER the rows are in. Building them alongside a two-million
 * row insert means maintaining every index tree per statement; building them once
 * at the end is the same result for a fraction of the work.
 */
class DatasetMaterializer
{
    /** Cast failures kept per column as examples, for the schema editor. */
    private const FAILURE_SAMPLES = 3;

    public function __construct(private readonly TypeCaster $caster = new TypeCaster) {}

    /**
     * @throws BiIngestionException
     */
    public function materialize(BiDataset $dataset): void
    {
        $columns = $dataset->columns()->get();

        if ($columns->isEmpty()) {
            throw new BiIngestionException(
                BiIngestionException::NO_HEADER,
                'The dataset has no columns to materialize.'
            );
        }

        $table = $dataset->physicalTable();

        $dataset->forceFill(['status' => BiDataset::STATUS_MATERIALIZING])->save();

        $this->createTable($table, $columns);

        $result = $this->loadRows($dataset, $table, $columns);

        $this->createIndexes($table, $columns);

        $this->recordCastFailures($columns, $result['failures'], $result['samples']);

        $dataset->forceFill([
            'row_count' => $result['rows'],
            // Bumped here and nowhere else. Query cache keys carry it, so a
            // rebuilt table produces new keys rather than stale hits — which is
            // what lets the cache have a long TTL and no invalidation logic.
            'version' => (int) $dataset->version + 1,
            'status' => BiDataset::STATUS_READY,
            'error_code' => null,
            'error_detail' => null,
            'materialized_at' => now(),
        ])->save();
    }

    /**
     * Drop the physical table for a dataset, if it exists.
     *
     * Public because two other callers need it: a re-materialization, and the
     * cleanup that runs when a dataset row is deleted (a dropped row would
     * otherwise leave an orphan table nothing references).
     */
    public function dropTable(string $table): void
    {
        BiDataset::assertSafeTableName($table);

        Schema::dropIfExists($table);
    }

    /**
     * @param  Collection<int, BiColumn>  $columns
     */
    private function createTable(string $table, Collection $columns): void
    {
        BiDataset::assertSafeTableName($table);

        $this->dropTable($table);

        Schema::create($table, function (Blueprint $blueprint) use ($columns) {
            // File order, as the primary key. Not an auto-increment: the row's
            // position in the owner's spreadsheet is a fact worth keeping, and it
            // makes a shard re-load land every row back where it was.
            $blueprint->unsignedBigInteger(BiDataset::ROW_KEY)->primary();

            foreach ($columns as $column) {
                $column->type->define(
                    $blueprint,
                    $column->safePhysicalName(),
                    $column->isLongText(),
                );
            }
        });
    }

    /**
     * Stream every shard into the table, casting as it goes.
     *
     * @param  Collection<int, BiColumn>  $columns
     * @return array{rows: int, failures: array<string, int>, samples: array<string, array<int, string>>}
     */
    private function loadRows(BiDataset $dataset, string $table, Collection $columns): array
    {
        $disk = Storage::disk('local');
        $chunkSize = max(100, (int) config('agent.bi.ingest.insert_chunk', 1000));

        $failures = [];
        $samples = [];
        $rowNumber = 0;
        $buffer = [];

        foreach (NdjsonShardWriter::shardPaths($dataset->shardDirectory(), (int) $dataset->shard_count) as $shardPath) {
            if (! $disk->exists($shardPath)) {
                // A missing shard is not recoverable by retrying — the normalized
                // copy is gone, and the honest fix is to re-read the original
                // upload, which is a different job. Said plainly rather than
                // producing a table that is quietly missing 50,000 rows.
                throw new BiIngestionException(
                    BiIngestionException::FILE_UNREADABLE,
                    "The normalized shard [{$shardPath}] is missing; the dataset must be re-imported from its file."
                );
            }

            $handle = @fopen($disk->path($shardPath), 'rb');

            if ($handle === false) {
                throw new BiIngestionException(
                    BiIngestionException::FILE_UNREADABLE,
                    "The normalized shard [{$shardPath}] could not be opened."
                );
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    $line = rtrim($line, "\r\n");

                    if ($line === '') {
                        continue;
                    }

                    $raw = json_decode($line, true);

                    if (! is_array($raw)) {
                        continue;
                    }

                    $buffer[] = $this->castRow($raw, $columns, $rowNumber++, $failures, $samples);

                    if (count($buffer) >= $chunkSize) {
                        $this->insert($table, $buffer);
                        $buffer = [];
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        if ($buffer !== []) {
            $this->insert($table, $buffer);
        }

        return ['rows' => $rowNumber, 'failures' => $failures, 'samples' => $samples];
    }

    /**
     * @param  array<int, mixed>  $raw
     * @param  Collection<int, BiColumn>  $columns
     * @param  array<string, int>  $failures
     * @param  array<string, array<int, string>>  $samples
     * @return array<string, mixed>
     */
    private function castRow(array $raw, Collection $columns, int $rowNumber, array &$failures, array &$samples): array
    {
        $row = [BiDataset::ROW_KEY => $rowNumber];

        foreach ($columns as $column) {
            $name = $column->safePhysicalName();
            $value = $raw[$column->ordinal] ?? null;

            $result = $this->caster->cast($value, $column->type, $column->format ?? []);

            if ($result->failed) {
                $failures[$name] = ($failures[$name] ?? 0) + 1;

                // Examples, not a log. Three values are enough for an owner to see
                // WHY a column rejected rows ("15 000 сом", "по запросу") and
                // decide whether to re-type it; keeping thousands would be a
                // second copy of the data.
                //
                // DISTINCT examples, because the commonest case by far is one bad
                // value repeated: a column with 400 rows saying "по запросу" would
                // otherwise report "по запросу · по запросу · по запросу", which
                // tells the owner nothing they did not already see in the first one.
                if (count($samples[$name] ?? []) < self::FAILURE_SAMPLES) {
                    $example = mb_substr((string) (is_scalar($value) ? $value : json_encode($value)), 0, 120);

                    if (! in_array($example, $samples[$name] ?? [], true)) {
                        $samples[$name][] = $example;
                    }
                }
            }

            $row[$name] = $result->value;
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        try {
            DB::table($table)->insert($rows);
        } catch (Throwable $e) {
            throw new BiIngestionException(
                BiIngestionException::CORRUPT_FILE,
                'A batch of rows could not be written to the analytical table: '.$e->getMessage(),
                $e,
            );
        }
    }

    /**
     * @param  Collection<int, BiColumn>  $columns
     */
    private function createIndexes(string $table, Collection $columns): void
    {
        BiDataset::assertSafeTableName($table);

        $indexable = $columns->filter(fn (BiColumn $column) => (bool) $column->indexed);

        if ($indexable->isEmpty()) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexable) {
            foreach ($indexable as $column) {
                $blueprint->index($column->safePhysicalName());
            }
        });
    }

    /**
     * @param  Collection<int, BiColumn>  $columns
     * @param  array<string, int>  $failures
     * @param  array<string, array<int, string>>  $samples
     */
    private function recordCastFailures(Collection $columns, array $failures, array $samples): void
    {
        foreach ($columns as $column) {
            $name = $column->physical_name;
            $count = $failures[$name] ?? 0;

            // Written even when zero: a re-materialization after the owner fixed a
            // type must clear the old count, or the schema editor would keep
            // warning about rejections that no longer happen.
            $column->forceFill([
                'cast_failures' => $count,
                'cast_failure_samples' => $count > 0 ? array_values($samples[$name] ?? []) : null,
            ])->save();
        }
    }
}
