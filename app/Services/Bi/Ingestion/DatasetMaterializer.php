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

class DatasetMaterializer
{
    private const FAILURE_SAMPLES = 3;

    public function __construct(private readonly TypeCaster $caster = new TypeCaster) {}

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

            'version' => (int) $dataset->version + 1,
            'status' => BiDataset::STATUS_READY,
            'error_code' => null,
            'error_detail' => null,
            'materialized_at' => now(),
        ])->save();
    }

    public function dropTable(string $table): void
    {
        BiDataset::assertSafeTableName($table);

        Schema::dropIfExists($table);
    }

    private function createTable(string $table, Collection $columns): void
    {
        BiDataset::assertSafeTableName($table);

        $this->dropTable($table);

        Schema::create($table, function (Blueprint $blueprint) use ($columns) {

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

    private function castRow(array $raw, Collection $columns, int $rowNumber, array &$failures, array &$samples): array
    {
        $row = [BiDataset::ROW_KEY => $rowNumber];

        foreach ($columns as $column) {
            $name = $column->safePhysicalName();
            $value = $raw[$column->ordinal] ?? null;

            $result = $this->caster->cast($value, $column->type, $column->format ?? []);

            if ($result->failed) {
                $failures[$name] = ($failures[$name] ?? 0) + 1;

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

    private function recordCastFailures(Collection $columns, array $failures, array $samples): void
    {
        foreach ($columns as $column) {
            $name = $column->physical_name;
            $count = $failures[$name] ?? 0;

            $column->forceFill([
                'cast_failures' => $count,
                'cast_failure_samples' => $count > 0 ? array_values($samples[$name] ?? []) : null,
            ])->save();
        }
    }
}
