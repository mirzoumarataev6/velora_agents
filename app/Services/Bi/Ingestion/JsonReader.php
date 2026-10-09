<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;
use Generator;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Throwable;

/**
 * JSON, in the shapes that are actually tabular.
 *
 *   1. A top-level array of objects — what every REST export and every
 *      `SELECT … FOR JSON` produces.
 *   2. A top-level object keyed by id (`{"1001": {…}, "1002": {…}}`) — what a
 *      PHP `json_encode` of a keyed array produces. The keys are not thrown
 *      away: they become a first column, `_key`, because an id is usually the
 *      most useful column in the file and silently dropping it would be the
 *      worst kind of data loss — the kind nobody notices.
 *   3. NDJSON / JSON Lines — one document per line, what log pipelines and
 *      BigQuery exports produce.
 *   4. An array of scalars, which is a legitimate one-column table.
 *
 * All of them stream. json-machine pulls values through an event parser, so a
 * 100MB array costs one row of memory; `json_decode` on a file that size is an
 * out-of-memory fatal rather than a slow request, which is the reason this is not
 * four lines of built-in functions.
 *
 * NESTING IS FLATTENED ONE LEVEL, then serialized. `{"customer":{"name":"Иван"}}`
 * becomes a column `customer.name`. Deeper than that, or a nested list whose
 * length varies per row, and the value is stored as its JSON text — honest,
 * still searchable as a string, and not a silent loss. Full relational unnesting
 * of arbitrary JSON is a different feature, and a half-built one produces tables
 * nobody can reason about.
 */
final class JsonReader implements TabularReader
{
    /**
     * Documents sampled to establish the column set.
     *
     * JSON has no header row, so the columns are the union of the keys seen —
     * and taking that union across a 2M-row file would mean reading the whole
     * thing before emitting row one. This many documents sees every key a
     * well-formed export uses; a key appearing only later is dropped, which is
     * reported rather than silent (see SourceNormalizer's skipped-reason count).
     */
    private const HEADER_SAMPLE = 200;

    /** The column a top-level object's keys are stored under. */
    public const KEY_COLUMN = '_key';

    public function __construct(
        private readonly string $path,
        private readonly bool $isNdjson,
    ) {}

    public function sheets(): iterable
    {
        [$header, $keyed] = $this->discoverHeader();

        yield new ReadSheet('', 0, fn (): Generator => $this->readRows($header, $keyed));
    }

    public function close(): void
    {
        // json-machine holds only its own file handle, released with its
        // generator; the NDJSON path closes in a finally block.
    }

    /**
     * The column names, plus whether the top level was a keyed object.
     *
     * Order is insertion order: the first document's keys first, then any new
     * key in the order it was first seen. That matches what a person reading the
     * file expects, rather than alphabetical — which nobody's export is in.
     *
     * @return array{0: array<int, string>, 1: bool}
     */
    private function discoverHeader(): array
    {
        $keys = [];
        $seen = 0;
        $sawScalar = false;
        $keyed = false;
        $expectedIndex = 0;

        foreach ($this->items() as $key => $item) {
            // A top-level ARRAY yields 0, 1, 2, …; a top-level OBJECT yields its
            // property names. Anything that is not the next sequential integer
            // means this is a map, and its keys are data.
            if ($key !== $expectedIndex++) {
                $keyed = true;
            }

            if ($seen++ >= self::HEADER_SAMPLE) {
                break;
            }

            if (! is_array($item) && ! is_object($item)) {
                $sawScalar = true;

                continue;
            }

            foreach (array_keys($this->flatten($item)) as $flatKey) {
                $keys[(string) $flatKey] = true;
            }
        }

        if ($seen === 0) {
            throw new BiIngestionException(
                BiIngestionException::NO_ROWS,
                'The JSON file contains no values to import.'
            );
        }

        if ($keys === []) {
            if ($sawScalar) {
                // A list (or map) of plain values. One column, named the one
                // thing it could honestly be called — plus the key column when
                // the values were keyed, since `{"ru": 12, "tg": 4}` is a real
                // two-column table and not a one-column one.
                return [$keyed ? [self::KEY_COLUMN, 'value'] : ['value'], $keyed];
            }

            throw new BiIngestionException(
                BiIngestionException::NOT_TABULAR,
                'The JSON file has no object keys that could become columns.'
            );
        }

        $header = array_keys($keys);

        return [$keyed ? [self::KEY_COLUMN, ...$header] : $header, $keyed];
    }

    /**
     * @param  array<int, string>  $header
     * @return Generator<int, array<int, mixed>>
     */
    private function readRows(array $header, bool $keyed): Generator
    {
        // Row zero is always the column names, so every reader in this module
        // presents the normalizer with the same shape.
        yield $header;

        // The value columns, i.e. the header minus the synthetic key column.
        $valueColumns = $keyed ? array_slice($header, 1) : $header;

        foreach ($this->items() as $key => $item) {
            $prefix = $keyed ? [$key] : [];

            if (! is_array($item) && ! is_object($item)) {
                yield array_pad([...$prefix, $item], count($header), null);

                continue;
            }

            $row = $this->flatten($item);
            $values = $prefix;

            // Read positionally against the established header, so a document
            // missing a key gets a null in that column rather than shifting
            // every value after it one place left.
            foreach ($valueColumns as $column) {
                $values[] = $row[$column] ?? null;
            }

            yield $values;
        }
    }

    /**
     * One level of nesting folded into dotted keys; anything deeper serialized.
     *
     * @return array<string, mixed>
     */
    private function flatten(mixed $item): array
    {
        $flat = [];

        foreach ((array) $item as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $flat[(string) $key] = $value;

                continue;
            }

            $nested = (array) $value;

            // A nested LIST (tags, line items) is not a set of columns: its
            // length varies per row, so there is no stable column for position
            // three. Kept as its JSON text.
            if ($nested === [] || array_is_list($nested)) {
                $flat[(string) $key] = $this->encode($nested);

                continue;
            }

            foreach ($nested as $innerKey => $innerValue) {
                $flat[$key.'.'.$innerKey] = is_scalar($innerValue) || $innerValue === null
                    ? $innerValue
                    : $this->encode($innerValue);
            }
        }

        return $flat;
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The top-level documents, as key => value.
     *
     * Called twice — once to establish the header, once to read — which is two
     * passes over the file. Deliberate: the alternative is buffering enough rows
     * to be sure of the column set, and for a 2M-row file that is the whole file
     * in memory. The header pass stops after HEADER_SAMPLE documents, so the
     * second read is the only one that is O(file).
     *
     * @return iterable<int|string, mixed>
     */
    private function items(): iterable
    {
        if ($this->isNdjson) {
            return $this->ndjsonItems();
        }

        return $this->jsonItems();
    }

    /**
     * A streamed top-level array or object.
     *
     * The iteration is wrapped rather than just the construction: json-machine is
     * an event parser, so a syntax error two thirds of the way through a file
     * surfaces while iterating, not when the file is opened. Catching only at
     * `fromFile()` would let a SyntaxError escape the reader layer — the exact
     * thing the TabularReader contract promises never happens.
     *
     * @return Generator<int|string, mixed>
     */
    private function jsonItems(): Generator
    {
        try {
            // assoc arrays rather than stdClass: everything downstream treats a
            // row as an array, and converting per document would be a cast on
            // every one of two million.
            $items = Items::fromFile($this->path, ['decoder' => new ExtJsonDecoder(true)]);

            yield from $items;
        } catch (Throwable $e) {
            throw $this->classify($e);
        }
    }

    /**
     * NDJSON by hand rather than through json-machine: the format is one
     * complete document per line, so a line-at-a-time read is both simpler and
     * faster than an event parser — and it lets one bad line be skipped instead
     * of aborting the file, which a truncated final line in a log export needs.
     *
     * @return Generator<int, mixed>
     */
    private function ndjsonItems(): Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new BiIngestionException(
                BiIngestionException::FILE_UNREADABLE,
                'The uploaded file could not be opened.'
            );
        }

        try {
            $index = 0;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    // Keyed by a running index so the shape matches the
                    // array-of-objects path: NDJSON is never a map.
                    yield $index++ => $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function classify(Throwable $e): BiIngestionException
    {
        if ($e instanceof BiIngestionException) {
            return $e;
        }

        return new BiIngestionException(
            BiIngestionException::CORRUPT_FILE,
            'The JSON file could not be parsed: '.$e->getMessage(),
            $e,
        );
    }
}
