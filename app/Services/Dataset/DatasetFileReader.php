<?php

namespace App\Services\Dataset;

use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use RuntimeException;
use SplFileObject;

/**
 * Reads an uploaded .xlsx/.csv catalog into a header row plus data rows.
 *
 * Knows nothing about products or mapping — it only turns a file into
 * `array{headers: string[], rows: array<int, string[]>}`, with every cell a
 * trimmed string. Interpreting those cells is DatasetImporter's job.
 *
 * CSV robustness matters more than it looks: the files owners actually upload
 * come out of 1C and Excel exports, so they arrive semicolon-delimited, in
 * windows-1251, and with a UTF-8 BOM glued to the first header — each of which
 * silently ruins the header row if it isn't handled.
 */
class DatasetFileReader
{
    /** Delimiters tried when sniffing a CSV, best-guess first. */
    protected const DELIMITERS = [';', ',', "\t", '|'];

    /** How much of a CSV to sniff for delimiter and encoding. */
    protected const SNIFF_BYTES = 8192;

    /**
     * @param  int|null  $limit  Stop after this many DATA rows (header excluded).
     * @return array{headers: string[], rows: array<int, array<int, string>>, truncated: bool}
     */
    public function read(string $absolutePath, ?int $limit = null): array
    {
        if (! is_readable($absolutePath)) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $raw = $extension === 'csv'
            ? $this->readCsv($absolutePath, $limit)
            : $this->readSpreadsheet($absolutePath, $limit);

        if ($raw['rows'] === []) {
            throw new RuntimeException('The file has no rows.');
        }

        $headers = $this->normalizeHeaders(array_shift($raw['rows']));

        // A file with a header and nothing else is a real (and confusing)
        // upload; say so rather than importing an empty catalog.
        if ($raw['rows'] === []) {
            throw new RuntimeException('The file has a header row but no data rows.');
        }

        return [
            'headers' => $headers,
            'rows' => array_values($raw['rows']),
            'truncated' => $raw['truncated'],
        ];
    }

    /**
     * Header cells, trimmed and de-blanked. Empty headers become "Column N" so
     * every column stays addressable in the mapping UI, and duplicates are
     * suffixed so a mapping keyed by header name can't collide.
     *
     * @param  array<int, string>  $cells
     * @return string[]
     */
    protected function normalizeHeaders(array $cells): array
    {
        $headers = [];
        $seen = [];

        foreach (array_values($cells) as $i => $cell) {
            $name = trim((string) $cell);
            if ($name === '') {
                $name = 'Column '.($i + 1);
            }

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
     * @return array{rows: array<int, array<int, string>>, truncated: bool}
     */
    protected function readSpreadsheet(string $path, ?int $limit): array
    {
        try {
            $reader = SpreadsheetIOFactory::createReaderForFile($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('This file could not be opened as a spreadsheet.', 0, $e);
        }

        // Formatting and charts are irrelevant to a catalog and are what makes
        // loading a big export expensive.
        $reader->setReadDataOnly(true);

        $sheet = $reader->load($path)->getActiveSheet();

        // +1 for the header row, which the caller shifts off.
        $lastRow = $sheet->getHighestDataRow();
        $capped = $limit !== null ? min($lastRow, $limit + 1) : $lastRow;

        $rows = $sheet->rangeToArray(
            'A1:'.$sheet->getHighestDataColumn().$capped,
            null,
            true,   // calculate formulas — a price cell is often =B2*1.2
            false,  // no formatting
            false,  // numeric keys
        );

        return [
            'rows' => array_map(fn ($row) => array_map($this->cell(...), $row), $rows),
            'truncated' => $limit !== null && $lastRow > $capped,
        ];
    }

    /**
     * @return array{rows: array<int, array<int, string>>, truncated: bool}
     */
    protected function readCsv(string $path, ?int $limit): array
    {
        $encoding = $this->detectCsvEncoding($path);
        $delimiter = $this->detectDelimiter($path, $encoding);

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter);

        $rows = [];
        $truncated = false;

        foreach ($file as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }

            if ($limit !== null && count($rows) > $limit) {
                $truncated = true;
                break;
            }

            $rows[] = array_map(function ($cell) use ($encoding) {
                $value = $this->cell($cell);

                return $encoding === 'UTF-8' ? $value : mb_convert_encoding($value, 'UTF-8', $encoding);
            }, $row);
        }

        if ($rows !== []) {
            // The BOM lands inside the first header cell, where it turns an
            // exact header match ("Название") into a near-miss no alias catches.
            $rows[0][0] = preg_replace('/^\x{FEFF}/u', '', (string) ($rows[0][0] ?? '')) ?? '';
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * The delimiter that splits the first non-empty line into the most columns.
     * Ties go to the earlier entry in DELIMITERS, which puts ';' (the Excel-RU
     * export default) ahead of ',' for a single-column file where neither wins.
     */
    protected function detectDelimiter(string $path, string $encoding): string
    {
        $head = (string) file_get_contents($path, false, null, 0, self::SNIFF_BYTES);
        if ($encoding !== 'UTF-8') {
            $head = mb_convert_encoding($head, 'UTF-8', $encoding);
        }

        $line = strtok($head, "\r\n");
        if ($line === false || trim($line) === '') {
            return ',';
        }

        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = count(str_getcsv($line, $delimiter, '"', '\\'));
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * UTF-8 unless the bytes say otherwise — windows-1251 being the one other
     * encoding CIS spreadsheet exports realistically produce.
     */
    protected function detectCsvEncoding(string $path): string
    {
        $head = (string) file_get_contents($path, false, null, 0, self::SNIFF_BYTES);

        if (mb_check_encoding($head, 'UTF-8')) {
            return 'UTF-8';
        }

        return mb_check_encoding($head, 'WINDOWS-1251') ? 'WINDOWS-1251' : 'UTF-8';
    }

    /**
     * One cell as a trimmed string. Non-breaking spaces (rife in prices copied
     * out of a browser) are folded to ordinary ones here so every later
     * consumer — number parsing included — sees plain whitespace.
     */
    protected function cell(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return $value === true ? '1' : '';
        }

        if (is_float($value) || is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return '';
        }

        $value = str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);

        return trim($value);
    }
}
