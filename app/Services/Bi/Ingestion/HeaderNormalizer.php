<?php

namespace App\Services\Bi\Ingestion;

use DateTimeInterface;

class HeaderNormalizer
{
    private const MIN_HEADER_CELLS = 2;

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

        return $firstNonBlank;
    }

    public function normalize(array $cells): array
    {

        $cells = $this->trimTrailingBlanks(array_values($cells));

        $headers = [];
        $seen = [];

        foreach ($cells as $index => $cell) {
            $name = $this->stringify($cell);

            if ($name === '') {
                $name = 'Column '.($index + 1);
            }

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

    public function isBlankRow(array $row): bool
    {
        return $this->filledCells($row) === 0;
    }

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
