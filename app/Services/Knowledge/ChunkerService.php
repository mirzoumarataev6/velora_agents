<?php

namespace App\Services\Knowledge;

/**
 * Splits normalized extraction output (see KnowledgeExtractionService) into an
 * ordered list of chunk strings ready to become KnowledgeChunk rows.
 */
class ChunkerService
{
    protected const CHARS_PER_TOKEN = 4;

    /**
     * @param  array{kind: string, text?: string, header?: string, rows?: string[]}  $extracted
     * @param  array{chunk_size_tokens: int, chunk_overlap_tokens: int, spreadsheet_rows_per_chunk: int}  $config
     * @return string[]
     */
    public function chunk(array $extracted, array $config): array
    {
        if (($extracted['kind'] ?? null) === 'tabular') {
            return $this->chunkTabular(
                $extracted['header'] ?? '',
                $extracted['rows'] ?? [],
                // A tabular payload may carry its own rows-per-chunk budget (XML
                // catalogs use xml_products_per_chunk); spreadsheets fall back
                // to the shared default.
                (int) ($extracted['rows_per_chunk'] ?? $config['spreadsheet_rows_per_chunk'])
            );
        }

        return $this->chunkPlainText(
            $extracted['text'] ?? '',
            (int) $config['chunk_size_tokens'],
            (int) $config['chunk_overlap_tokens']
        );
    }

    /**
     * @return string[]
     */
    protected function chunkTabular(string $header, array $rows, int $rowsPerChunk): array
    {
        $chunks = [];

        foreach (array_chunk($rows, max(1, $rowsPerChunk)) as $group) {
            $chunks[] = trim($header."\n".implode("\n", $group));
        }

        return $chunks;
    }

    /**
     * Greedily packs paragraphs (falling back to sentences for oversized paragraphs)
     * into chunks bounded by an approximate token budget, carrying a tail overlap
     * from the end of each chunk into the start of the next for continuity.
     *
     * @return string[]
     */
    protected function chunkPlainText(string $text, int $sizeTokens, int $overlapTokens): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $maxChars = max(1, $sizeTokens * self::CHARS_PER_TOKEN);
        $overlapChars = max(0, $overlapTokens * self::CHARS_PER_TOKEN);

        $units = [];
        foreach ($this->splitParagraphs($text) as $paragraph) {
            if (mb_strlen($paragraph) > $maxChars) {
                array_push($units, ...$this->splitSentences($paragraph));
            } else {
                $units[] = $paragraph;
            }
        }

        $chunks = [];
        $current = '';

        foreach ($units as $unit) {
            $candidate = $current === '' ? $unit : $current."\n\n".$unit;

            if (mb_strlen($candidate) > $maxChars && $current !== '') {
                $chunks[] = trim($current);
                $tail = mb_substr($current, max(0, mb_strlen($current) - $overlapChars));
                $current = trim($tail."\n\n".$unit);
            } else {
                $current = $candidate;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * @return string[]
     */
    protected function splitParagraphs(string $text): array
    {
        $parts = preg_split('/\n{2,}/u', $text) ?: [$text];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }

    /**
     * @return string[]
     */
    protected function splitSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }
}
