<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Info;
use League\Csv\Reader as CsvDocument;
use Throwable;

final class CsvReader implements TabularReader
{
    private const SNIFF_BYTES = 65536;

    private const SNIFF_LINES = 12;

    private const DELIMITERS = [';', ',', "\t", '|'];

    private const ENCODINGS = ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-1'];

    public function __construct(private readonly string $path) {}

    public function sheets(): iterable
    {

        yield new ReadSheet('', 0, fn (): Generator => $this->readRows());
    }

    public function close(): void {}

    private function readRows(): Generator
    {
        $encoding = $this->detectEncoding();
        $document = $this->open($encoding);

        try {
            foreach ($document->getRecords() as $record) {
                $row = array_values($record);

                if ($this->isBlankRow($row)) {
                    continue;
                }

                yield $row;
            }
        } catch (BiIngestionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BiIngestionException(
                BiIngestionException::CORRUPT_FILE,
                'The delimited file could not be read: '.$e->getMessage(),
                $e,
            );
        }
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function open(string $encoding): CsvDocument
    {
        try {
            $document = CsvDocument::createFromPath($this->path, 'r');
        } catch (Throwable $e) {
            throw new BiIngestionException(
                BiIngestionException::FILE_UNREADABLE,
                'The uploaded file could not be opened: '.$e->getMessage(),
                $e,
            );
        }

        $document->setHeaderOffset(null);
        $document->setDelimiter($this->detectDelimiter($encoding));
        $document->skipInputBOM();

        if ($encoding !== 'UTF-8') {

            CharsetConverter::addTo($document, $encoding, 'UTF-8');
        }

        return $document;
    }

    private function detectEncoding(): string
    {
        $sample = @file_get_contents($this->path, false, null, 0, self::SNIFF_BYTES);

        if ($sample === false) {
            throw new BiIngestionException(
                BiIngestionException::FILE_UNREADABLE,
                'The uploaded file could not be read from disk.'
            );
        }

        if ($sample === '') {
            throw new BiIngestionException(
                BiIngestionException::EMPTY_FILE,
                'The uploaded file is empty.'
            );
        }

        foreach ([["\xEF\xBB\xBF", 'UTF-8'], ["\xFF\xFE", 'UTF-16LE'], ["\xFE\xFF", 'UTF-16BE']] as [$bom, $encoding]) {
            if (str_starts_with($sample, $bom)) {
                return $encoding;
            }
        }

        if (str_contains($sample, "\0") || $this->looksBinary($sample)) {
            throw new BiIngestionException(
                BiIngestionException::ENCODING_UNREADABLE,
                'The file contains binary data and is not delimited text.'
            );
        }

        foreach (self::ENCODINGS as $encoding) {
            if (mb_check_encoding($sample, $encoding)) {
                return $encoding;
            }
        }

        throw new BiIngestionException(
            BiIngestionException::ENCODING_UNREADABLE,
            'The file is not valid text in any encoding this reader supports.'
        );
    }

    private function looksBinary(string $sample): bool
    {
        $length = strlen($sample);

        if ($length === 0) {
            return false;
        }

        $control = 0;

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($sample[$i]);

            if (($byte < 0x20 && $byte !== 0x09 && $byte !== 0x0A && $byte !== 0x0D) || $byte === 0x7F) {
                $control++;
            }
        }

        return $control / $length > 0.02;
    }

    private function detectDelimiter(string $encoding): string
    {
        try {
            $probe = CsvDocument::createFromPath($this->path, 'r');
            $probe->setHeaderOffset(null);
            $probe->skipInputBOM();

            if ($encoding !== 'UTF-8') {
                CharsetConverter::addTo($probe, $encoding, 'UTF-8');
            }

            $stats = Info::getDelimiterStats($probe, self::DELIMITERS, self::SNIFF_LINES);
        } catch (Throwable) {
            return ',';
        }

        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = (int) ($stats[$delimiter] ?? 0);

            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
