<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;

class ReaderFactory
{
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

    public function supportedExtensions(): array
    {

        $configured = config('agent.bi.ingest.allowed_extensions', []);

        return $configured;
    }
}
