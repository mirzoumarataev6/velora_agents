<?php

namespace App\Services\Dataset;

use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use RuntimeException;
use SplFileObject;

class DatasetFileReader
{
    protected const DELIMITERS = [';', ',', "\t", '|'];

    protected const SNIFF_BYTES = 8192;

    // Читает CSV или табличный файл и возвращает заголовки и строки данных
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

        if ($raw['rows'] === []) {
            throw new RuntimeException('The file has a header row but no data rows.');
        }

        return [
            'headers' => $headers,
            'rows' => array_values($raw['rows']),
            'truncated' => $raw['truncated'],
        ];
    }

    // Приводит строку заголовков к непустым уникальным именам столбцов
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

    // Читает строки из файла Excel/ODS через PhpSpreadsheet
    protected function readSpreadsheet(string $path, ?int $limit): array
    {
        try {
            $reader = SpreadsheetIOFactory::createReaderForFile($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('This file could not be opened as a spreadsheet.', 0, $e);
        }

        $reader->setReadDataOnly(true);

        $sheet = $reader->load($path)->getActiveSheet();

        $lastRow = $sheet->getHighestDataRow();
        $capped = $limit !== null ? min($lastRow, $limit + 1) : $lastRow;

        $rows = $sheet->rangeToArray(
            'A1:'.$sheet->getHighestDataColumn().$capped,
            null,
            true,
            false,
            false,
        );

        return [
            'rows' => array_map(fn ($row) => array_map($this->cell(...), $row), $rows),
            'truncated' => $limit !== null && $lastRow > $capped,
        ];
    }

    // Читает строки из CSV-файла, определяя кодировку и разделитель
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
            $rows[0][0] = preg_replace('/^\x{FEFF}/u', '', (string) ($rows[0][0] ?? '')) ?? '';
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    // Определяет разделитель CSV по первой строке файла
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

    // Определяет кодировку CSV-файла (UTF-8 или Windows-1251)
    protected function detectCsvEncoding(string $path): string
    {
        $head = (string) file_get_contents($path, false, null, 0, self::SNIFF_BYTES);

        if (mb_check_encoding($head, 'UTF-8')) {
            return 'UTF-8';
        }

        return mb_check_encoding($head, 'WINDOWS-1251') ? 'WINDOWS-1251' : 'UTF-8';
    }

    // Приводит значение ячейки к строке, очищая нестандартные пробелы
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
