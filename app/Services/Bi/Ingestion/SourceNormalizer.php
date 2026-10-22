<?php

namespace App\Services\Bi\Ingestion;

use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Services\Bi\BiIngestionException;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SourceNormalizer
{
    private const PREAMBLE_LOOKAHEAD = 12;

    public function __construct(
        private readonly ReaderFactory $readers,
        private readonly HeaderNormalizer $headers,
    ) {}

    public function normalize(BiSource $source): array
    {
        if (! $source->isFile()) {
            throw new BiIngestionException(
                BiIngestionException::UNSUPPORTED_FORMAT,
                'Only file sources are normalized; a live connection is read in place.'
            );
        }

        $this->assertWithinFileBudget($source);

        $path = Storage::disk('local')->path((string) $source->disk_path);

        $reader = $this->readers->make($path, (string) $source->extension);

        $datasets = [];

        try {
            foreach ($reader->sheets() as $sheet) {
                $dataset = $this->normalizeSheet($source, $sheet, count($datasets));

                if ($dataset !== null) {
                    $datasets[] = $dataset;
                }
            }
        } catch (\Throwable $e) {

            foreach ($datasets as $orphan) {
                $orphan->delete();
            }

            throw $e;
        } finally {

            $reader->close();
        }

        if ($datasets === []) {
            throw new BiIngestionException(
                BiIngestionException::NO_ROWS,
                'The file contains no sheet with a header row and at least one row of data.'
            );
        }

        return $datasets;
    }

    private function normalizeSheet(BiSource $source, ReadSheet $sheet, int $alreadyCreated): ?BiDataset
    {
        $this->assertWithinDatasetQuota($source, $alreadyCreated);

        $rows = $sheet->rows();

        $buffer = [];

        while ($rows->valid() && count($buffer) < self::PREAMBLE_LOOKAHEAD) {
            $buffer[] = $rows->current();
            $rows->next();
        }

        if ($buffer === []) {
            return null;
        }

        $headerIndex = $this->headers->headerRowIndex($buffer);

        if ($headerIndex === null) {
            return null;
        }

        $headerNames = $this->headers->normalize($buffer[$headerIndex]);

        if ($headerNames === []) {
            return null;
        }

        $this->assertWithinColumnBudget($headerNames);

        $preamble = $headerIndex;

        $dataset = $this->createDataset($source, $sheet, $headerNames);

        try {
            $result = $this->streamRows(
                $dataset,
                $headerNames,

                $this->remainingRows(array_slice($buffer, $headerIndex + 1), $rows),
            );
        } catch (\Throwable $e) {

            $dataset->delete();

            throw $e;
        }

        if ($result['rows'] === 0) {
            $dataset->delete();

            return null;
        }

        $skipped = array_filter([
            'preamble' => $preamble,
            'ragged' => $result['ragged'],
            'over_row_limit' => $result['over_limit'],
        ]);

        $dataset->update([
            'row_count' => $result['rows'],
            'column_count' => count($headerNames),
            'shard_count' => $result['shards'],
            'skipped_rows' => array_sum($skipped),
            'skipped_reasons' => $skipped === [] ? null : $skipped,
            'truncated' => $result['over_limit'] > 0,
        ]);

        return $dataset;
    }

    private function remainingRows(array $buffered, Generator $rest): Generator
    {
        yield from $buffered;

        while ($rest->valid()) {
            yield $rest->current();
            $rest->next();
        }
    }

    private function streamRows(BiDataset $dataset, array $headerNames, iterable $rows): array
    {
        $width = count($headerNames);
        $maxRows = max(1, (int) config('agent.bi.ingest.max_rows', 2000000));

        $inferrer = new SchemaInferrer($headerNames);
        $writer = new NdjsonShardWriter($dataset->shardDirectory());

        $kept = 0;
        $ragged = 0;
        $overLimit = 0;

        try {
            foreach ($rows as $row) {
                $row = array_values($row);

                if ($this->headers->isBlankRow($row)) {
                    continue;
                }

                if (count($row) !== $width) {
                    $ragged++;
                    $row = count($row) < $width
                        ? array_pad($row, $width, null)
                        : array_slice($row, 0, $width);
                }

                if ($kept >= $maxRows) {

                    $overLimit++;

                    continue;
                }

                $writer->write($row);
                $kept++;

                if ($inferrer->wantsMore()) {
                    $inferrer->observe($row);
                }
            }
        } finally {
            $shards = $writer->close();
        }

        if ($kept > 0) {
            $this->saveColumns($dataset, $inferrer->columns());
        }

        return ['rows' => $kept, 'shards' => $shards, 'ragged' => $ragged, 'over_limit' => $overLimit];
    }

    private function saveColumns(BiDataset $dataset, array $columns): void
    {
        $now = now();

        $rows = [];

        foreach ($columns as $column) {
            $attributes = $column->toAttributes();

            $rows[] = [
                'user_id' => $dataset->user_id,

                'team_id' => $dataset->team_id,
                'bi_dataset_id' => $dataset->getKey(),
                'ordinal' => $attributes['ordinal'],
                'source_name' => $attributes['source_name'],
                'physical_name' => $attributes['physical_name'],
                'label' => null,
                'type' => $attributes['type']->value,
                'inferred_type' => $attributes['inferred_type']->value,
                'role' => $attributes['role']->value,
                'format' => $attributes['format'] === null ? null : json_encode($attributes['format'], JSON_UNESCAPED_UNICODE),
                'distinct_count' => $attributes['distinct_count'],
                'distinct_exact' => $attributes['distinct_exact'],
                'blank_count' => $attributes['blank_count'],
                'max_length' => $attributes['max_length'],
                'sample_values' => json_encode($attributes['sample_values'], JSON_UNESCAPED_UNICODE),
                'min_value' => $attributes['min_value'],
                'max_value' => $attributes['max_value'],
                'cast_failures' => 0,
                'cast_failure_samples' => null,
                'indexed' => $attributes['indexed'],
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($dataset, $rows) {
            BiColumn::where('bi_dataset_id', $dataset->getKey())->delete();

            foreach (array_chunk($rows, 50) as $chunk) {
                BiColumn::insert($chunk);
            }
        });
    }

    private function createDataset(BiSource $source, ReadSheet $sheet, array $headerNames): BiDataset
    {
        $dataset = new BiDataset([
            'name' => $this->datasetName($source, $sheet),
            'sheet_name' => $sheet->name === '' ? null : $sheet->name,
            'status' => BiDataset::STATUS_INFERRING,
            'column_count' => count($headerNames),
        ]);

        $dataset->user_id = $source->user_id;

        $dataset->team_id = $source->team_id;
        $dataset->bi_source_id = $source->getKey();
        $dataset->save();

        $dataset->forceFill([
            'physical_table' => $dataset->physicalTable(),
            'shard_directory' => "bi/{$source->user_id}/datasets/{$dataset->getKey()}",
        ])->save();

        return $dataset;
    }

    private function datasetName(BiSource $source, ReadSheet $sheet): string
    {

        if (! $sheet->isGenericName()) {
            return Str::limit($sheet->name, 120, '');
        }

        $filename = (string) ($source->original_filename ?: $source->name);

        return Str::limit(pathinfo($filename, PATHINFO_FILENAME) ?: 'Dataset', 120, '');
    }

    private function assertWithinFileBudget(BiSource $source): void
    {
        $maxKb = max(1, (int) config('agent.bi.ingest.max_file_size_kb', 100 * 1024));

        if ((int) $source->bytes > $maxKb * 1024) {
            throw new BiIngestionException(
                BiIngestionException::FILE_TOO_LARGE,
                'The file is '.round((int) $source->bytes / 1048576, 1)."MB, over the {$maxKb}KB limit."
            );
        }
    }

    private function assertWithinDatasetQuota(BiSource $source, int $alreadyCreated): void
    {
        $max = max(1, (int) config('agent.bi.ingest.max_datasets_per_owner', 25));

        $existing = BiDataset::where('team_id', $source->team_id)
            ->where('bi_source_id', '!=', $source->getKey())
            ->count();

        if ($existing + $alreadyCreated >= $max) {
            throw new BiIngestionException(
                BiIngestionException::TOO_MANY_DATASETS,
                "This account already holds {$existing} datasets, at the limit of {$max}."
            );
        }
    }

    private function assertWithinColumnBudget(array $headerNames): void
    {
        $max = max(1, (int) config('agent.bi.ingest.max_columns', 150));

        if (count($headerNames) > $max) {
            throw new BiIngestionException(
                BiIngestionException::TOO_MANY_COLUMNS,
                'The sheet has '.count($headerNames)." columns, over the limit of {$max}."
            );
        }
    }
}
