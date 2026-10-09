<?php

namespace App\Services\Bi\Ingestion;

use DateTimeInterface;

/**
 * Turns a file's first meaningful row into usable column names.
 *
 * Three real-world problems, none of which the file announces:
 *
 *  - BLANK HEADERS. A trailing column somebody deleted the title of, or a merged
 *    cell. Named `Column 7` after its position, which is at least true.
 *  - DUPLICATE HEADERS. Two columns both called "Сумма" — very common in exports
 *    that put a subtotal beside a total. Suffixed `Сумма (2)`, because silently
 *    collapsing them would lose a column and refusing the file would be absurd.
 *  - A TITLE ROW ABOVE THE HEADER. `A1: "Отчёт о продажах за март"`, then a blank
 *    row, then the real headers. Extremely common in anything a human formatted.
 *    Handled by `headerRowIndex()`: the header is the first row with at least two
 *    non-blank cells, so a one-cell title is stepped over rather than becoming a
 *    single column called "Отчёт о продажах за март" with every real header
 *    landing in row one of the data.
 *
 * The naming convention matches DatasetFileReader's, so the two importers in this
 * product describe the same spreadsheet the same way.
 */
class HeaderNormalizer
{
    /**
     * Non-blank cells a row needs before it is believed to be the header.
     *
     * Two, not one: a single-cell row is a title, and a genuinely single-column
     * file is handled by the fallback in `headerRowIndex()` rather than by
     * lowering this and letting every title row win.
     */
    private const MIN_HEADER_CELLS = 2;

    /**
     * Which of the buffered leading rows is the header.
     *
     * Returns null when none of them qualifies — an entirely blank file.
     *
     * @param  array<int, array<int, mixed>>  $rows  The first few rows, in order.
     */
    public function headerRowIndex(array $rows): ?int
    {
        $firstNonBlank = null;

        foreach ($rows as $index => $row) {
            $filled = $this->filledCells($row);

            if ($filled === 0) {
                continue;
            }

            $firstNonBlank ??= $index;

            if ($filled >= self::MIN_HEADER_CELLS) {
                return $index;
            }
        }

        // Every non-blank row had exactly one cell. That IS a single-column file —
        // a list of SKUs or phone numbers — and its first row is its header.
        return $firstNonBlank;
    }

    /**
     * Normalize a header row into non-empty, unique names.
     *
     * @param  array<int, mixed>  $cells
     * @return array<int, string>
     */
    public function normalize(array $cells): array
    {
        // Trailing blank columns are dropped: a spreadsheet's "highest data
        // column" is often a few past the last real one because something was
        // once typed there and deleted, and carrying them through would add empty
        // `Column 41`, `Column 42` columns to every dataset from that file.
        $cells = $this->trimTrailingBlanks(array_values($cells));

        $headers = [];
        $seen = [];

        foreach ($cells as $index => $cell) {
            $name = $this->stringify($cell);

            if ($name === '') {
                $name = 'Column '.($index + 1);
            }

            // Bounded because `bi_columns.source_name` is a 512-char column and a
            // merged cell can carry a whole paragraph.
            $name = mb_substr($name, 0, 480);

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                $seen[$key]++;
                $name .= ' ('.$seen[$key].')';
            } else {
                $seen[$key] = 1;
            }

            $headers[] = $name;
        }

        return $headers;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    public function filledCells(array $row): int
    {
        $filled = 0;

        foreach ($row as $cell) {
            if ($this->stringify($cell) !== '') {
                $filled++;
            }
        }

        return $filled;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    public function isBlankRow(array $row): bool
    {
        return $this->filledCells($row) === 0;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array<int, mixed>
     */
    private function trimTrailingBlanks(array $cells): array
    {
        for ($i = count($cells) - 1; $i >= 0; $i--) {
            if ($this->stringify($cells[$i]) !== '') {
                return array_slice($cells, 0, $i + 1);
            }
        }

        return [];
    }

    private function stringify(mixed $cell): string
    {
        if ($cell === null) {
            return '';
        }

        if ($cell instanceof DateTimeInterface) {
            // A date as a column heading is what a pivoted report looks like
            // ("01.03", "02.03", …). Rendered as a date rather than as an object
            // so the column is at least named what the owner sees.
            return $cell->format('Y-m-d');
        }

        if (is_bool($cell)) {
            return $cell ? 'true' : 'false';
        }

        if (! is_scalar($cell)) {
            return '';
        }

        $value = str_replace(["\u{00A0}", "\u{202F}", "\u{FEFF}"], [' ', ' ', ''], (string) $cell);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
