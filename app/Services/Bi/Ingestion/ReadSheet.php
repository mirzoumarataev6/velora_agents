<?php

namespace App\Services\Bi\Ingestion;

use Closure;
use Generator;

/**
 * One sheet of a file: a name, and a forward-only stream of raw rows.
 *
 * "Raw" is precise. A row is a list of scalars exactly as the reader found them
 * — strings, ints, floats, bools, DateTimeImmutable for a date-formatted Excel
 * cell, null for a blank. Nothing has been trimmed, coerced or classified yet,
 * because inference has to see the original shape to do its job: a cell that
 * arrives as a DateTimeImmutable needs no date-format guessing at all, and one
 * that arrives as the string "03.04.2026" needs a vote. Flattening both to
 * strings here would throw away the distinction and make every Excel date
 * ambiguous.
 *
 * The rows are wrapped in a Closure rather than being an iterator held on the
 * object so that constructing a ReadSheet reads nothing. A workbook can then
 * report its sheet names cheaply, and only the sheets actually ingested are
 * parsed.
 */
final class ReadSheet
{
    /**
     * @param  Closure(): Generator<int, array<int, mixed>>  $rows
     */
    public function __construct(
        public readonly string $name,
        public readonly int $index,
        private readonly Closure $rows,
    ) {}

    /**
     * The rows, header row included as the first one.
     *
     * Single-use: this is a stream, and calling it twice starts a second read of
     * the underlying file (for CSV) or throws (for a workbook whose iterator has
     * already been consumed). Nothing in the module calls it twice — ingestion
     * is one pass, by design.
     *
     * @return Generator<int, array<int, mixed>>
     */
    public function rows(): Generator
    {
        return ($this->rows)();
    }

    /**
     * A display name for the dataset this sheet becomes.
     *
     * A single-sheet file contributes nothing useful ("Sheet1", "Worksheet"), so
     * the caller's fallback — usually the filename — wins. A real sheet name in
     * a multi-sheet workbook is exactly what the owner will look for in the
     * source list, so it wins there.
     */
    public function isGenericName(): bool
    {
        $folded = mb_strtolower(trim($this->name));

        return $folded === ''
            || (bool) preg_match('/^(sheet|worksheet|лист|варақ|varaq)\s*\d*$/u', $folded);
    }
}
