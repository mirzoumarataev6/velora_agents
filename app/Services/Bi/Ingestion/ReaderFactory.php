<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;

/**
 * Extension → reader. The only place that mapping is written down.
 *
 * Deliberately keyed on the EXTENSION rather than on a sniffed magic number,
 * because the extension is what the upload validator already accepted and the two
 * must agree: a file that passed validation as .csv and is then read as a
 * spreadsheet because its first bytes happened to look like a zip would be
 * bypassing the size budget for its real format. When the extension lies, the
 * reader fails with CORRUPT_FILE, which is the correct and honest outcome —
 * "this is not the .xlsx it claims to be" is the owner's to fix.
 */
class ReaderFactory
{
    /**
     * @throws BiIngestionException
     */
    public function make(string $absolutePath, string $extension): TabularReader
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new BiIngestionException(
                BiIngestionException::FILE_UNREADABLE,
                'The uploaded file is not present on disk or cannot be read.'
            );
        }

        if (@filesize($absolutePath) === 0) {
            throw new BiIngestionException(
                BiIngestionException::EMPTY_FILE,
                'The uploaded file is empty.'
            );
        }

        return match (strtolower(trim($extension, '. '))) {
            'csv', 'tsv', 'txt' => new CsvReader($absolutePath),
            'xlsx' => new SpreadsheetReader($absolutePath, isOds: false),
            'ods' => new SpreadsheetReader($absolutePath, isOds: true),
            'xls' => new LegacyExcelReader($absolutePath),
            'json' => new JsonReader($absolutePath, isNdjson: false),
            'ndjson', 'jsonl' => new JsonReader($absolutePath, isNdjson: true),
            default => throw new BiIngestionException(
                BiIngestionException::UNSUPPORTED_FORMAT,
                "There is no reader for files with the extension [{$extension}]."
            ),
        };
    }

    /** @return array<int, string> */
    public function supportedExtensions(): array
    {
        /** @var array<int, string> $configured */
        $configured = config('agent.bi.ingest.allowed_extensions', []);

        return $configured;
    }
}
