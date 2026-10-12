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

/**
 * The first half of ingest: read the upload once, write a normalized copy, and
 * decide what every column is.
 *
 * ONE PASS over the original file, which is the load-bearing performance decision
 * in the whole module. Each row is simultaneously
 *
 *   - handed to the SchemaInferrer (which stops wanting rows after the sample), and
 *   - written to an NDJSON shard,
 *
 * so a 2M-row .xlsx is parsed once and never again — not to infer, not to
 * materialize, and not when the owner corrects a type. Doing inference as its own
 * pass would double the most expensive part of ingest to save nothing.
 *
 * Nothing here creates a table or writes a row of analytical data. That is
 * MaterializeDatasetJob's job, deliberately separated: this stage is pure reading
 * and can be retried freely, while that stage issues DDL and must not be.
 *
 * WHAT IS REPORTED RATHER THAN SWALLOWED, because a short table with no
 * explanation is the worst outcome an importer can produce:
 *   - `preamble`      rows stepped over above the header (a report's title block)
 *   - `ragged`        rows whose width did not match the header's
 *   - `over_row_limit` rows past the row budget (also sets `truncated`)
 * Blank rows are the one exception: they are dropped silently, because a
 * spreadsheet's trailing empty rows can number in the thousands and are not
 * something anybody meant to send.
 */
class SourceNormalizer
{
    /**
     * Leading rows buffered while looking for the header.
     *
     * Enough to step over a formatted report's title block; small enough that it
     * is a handful of rows in memory rather than a strategy.
     */
    private const PREAMBLE_LOOKAHEAD = 12;

    public function __construct(
        private readonly ReaderFactory $readers,
        private readonly HeaderNormalizer $headers,
    ) {}

    /**
     * Read a source and create a dataset per sheet, schema inferred, shards
     * written. Returns the datasets, in file order.
     *
     * @return array<int, BiDataset>
     *
     * @throws BiIngestionException
     */
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

                // A sheet with a header and no rows under it, or one that is
                // entirely blank, contributes nothing. Skipped rather than
                // failing the workbook: a "Инструкция" sheet beside three real
                // ones is normal.
                if ($dataset !== null) {
                    $datasets[] = $dataset;
                }
            }
        } catch (\Throwable $e) {
            // A workbook is all-or-nothing. Sheet three being too wide must not
            // leave sheets one and two behind as datasets nobody will ever
            // materialize: the job marks the SOURCE failed and dispatches nothing,
            // so those rows would sit at "определение типов" forever, with their
            // shards on disk and their tables never built.
            //
            // Deleted one at a time so each fires its own observer and takes its
            // shards with it.
            foreach ($datasets as $orphan) {
                $orphan->delete();
            }

            throw $e;
        } finally {
            // Always, including on the way out of an exception: a half-read
            // workbook must not leave a ZipArchive handle open on the worker.
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

        // Buffer the top of the sheet so a title block above the header can be
        // recognized and stepped over — which needs the rows AFTER it to judge.
        //
        // Driven through the generator's own valid()/current()/next() rather than a
        // `foreach` with a `break`. That is not style: breaking out of a foreach
        // leaves the generator with its current value still unconsumed, so a later
        // `yield from` re-emits the last buffered row — an off-by-one that silently
        // duplicates a row of the owner's data — and PHP then refuses to delegate to
        // it at all ("aborted without proper return"). Stepping it by hand leaves it
        // cleanly positioned on the first row nobody has taken yet.
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

        // Counted, not silent: an owner whose report had four rows of preamble
        // should be told four rows were not data.
        $preamble = $headerIndex;

        $dataset = $this->createDataset($source, $sheet, $headerNames);

        try {
            $result = $this->streamRows(
                $dataset,
                $headerNames,
                // The buffered rows after the header, then the rest of the stream.
                $this->remainingRows(array_slice($buffer, $headerIndex + 1), $rows),
            );
        } catch (\Throwable $e) {
            // The dataset row exists but has no usable schema; leaving it behind
            // would put a permanently "inferring" card in the owner's source list.
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

    /**
     * The buffered remainder followed by the live stream, as one sequence.
     *
     * The second half is stepped by hand rather than with `yield from`, and the
     * reason is a genuine PHP sharp edge: `yield from` on a generator that has
     * already FINISHED throws "aborted without proper return". That happens
     * whenever the file is shorter than the lookahead — a three-row CSV is
     * entirely inside the buffer, so the reader's generator is complete by the
     * time it gets here. Which is to say: it would have failed on every small
     * file and worked on every large one.
     *
     * @param  array<int, array<int, mixed>>  $buffered
     * @param  Generator<int, array<int, mixed>>  $rest
     * @return Generator<int, array<int, mixed>>
     */
    private function remainingRows(array $buffered, Generator $rest): Generator
    {
        yield from $buffered;

        while ($rest->valid()) {
            yield $rest->current();
            $rest->next();
        }
    }

    /**
     * The single pass: infer and shard at the same time.
     *
     * @param  array<int, string>  $headerNames
     * @param  iterable<int, array<int, mixed>>  $rows
     * @return array{rows: int, shards: int, ragged: int, over_limit: int}
     */
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
                    // Counted rather than aborted, and the count is not capped:
                    // "the first 2,000,000 of 3,412,880 rows were imported" is a
                    // far more useful thing to tell an owner than "truncated".
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

    /**
     * @param  array<int, InferredColumn>  $columns
     */
    private function saveColumns(BiDataset $dataset, array $columns): void
    {
        $now = now();

        $rows = [];

        foreach ($columns as $column) {
            $attributes = $column->toAttributes();

            $rows[] = [
                'user_id' => $dataset->user_id,
                // Bulk insert bypasses BiColumn's StampsTeam creating hook, so the
                // dataset's own team_id (resolved once, not per row) is stamped
                // here explicitly — see StampsTeam's docblock for why this matters.
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

        // One insert rather than 150. Re-normalization replaces the whole set, so
        // the delete-then-insert is the honest shape: a column that no longer
        // exists in the file must not survive as a row nothing can query.
        DB::transaction(function () use ($dataset, $rows) {
            BiColumn::where('bi_dataset_id', $dataset->getKey())->delete();

            foreach (array_chunk($rows, 50) as $chunk) {
                BiColumn::insert($chunk);
            }
        });
    }

    /**
     * @param  array<int, string>  $headerNames
     */
    private function createDataset(BiSource $source, ReadSheet $sheet, array $headerNames): BiDataset
    {
        $dataset = new BiDataset([
            'name' => $this->datasetName($source, $sheet),
            'sheet_name' => $sheet->name === '' ? null : $sheet->name,
            'status' => BiDataset::STATUS_INFERRING,
            'column_count' => count($headerNames),
        ]);

        $dataset->user_id = $source->user_id;
        $dataset->bi_source_id = $source->getKey();
        $dataset->save();

        // Both derived from the primary key, so they cannot be set before the row
        // exists — and neither is ever composed from anything a request supplied.
        $dataset->forceFill([
            'physical_table' => $dataset->physicalTable(),
            'shard_directory' => "bi/{$source->user_id}/datasets/{$dataset->getKey()}",
        ])->save();

        return $dataset;
    }

    private function datasetName(BiSource $source, ReadSheet $sheet): string
    {
        // A real sheet name in a workbook is what the owner will look for; a
        // generic "Sheet1" tells them nothing, so the filename wins there.
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

        // Counted live rather than once up front: a 25-sheet workbook must stop at
        // the quota mid-import instead of discovering afterwards that it blew it.
        $existing = BiDataset::where('user_id', $source->user_id)
            ->where('bi_source_id', '!=', $source->getKey())
            ->count();

        if ($existing + $alreadyCreated >= $max) {
            throw new BiIngestionException(
                BiIngestionException::TOO_MANY_DATASETS,
                "This account already holds {$existing} datasets, at the limit of {$max}."
            );
        }
    }

    /**
     * @param  array<int, string>  $headerNames
     */
    private function assertWithinColumnBudget(array $headerNames): void
    {
        $max = max(1, (int) config('agent.bi.ingest.max_columns', 150));

        // A hard refusal, not a truncation. Silently dropping columns 151 onward
        // would be dropping whichever column the owner actually wanted, and this
        // number is also the width of a CREATE TABLE we are about to issue.
        if (count($headerNames) > $max) {
            throw new BiIngestionException(
                BiIngestionException::TOO_MANY_COLUMNS,
                'The sheet has '.count($headerNames)." columns, over the limit of {$max}."
            );
        }
    }
}
